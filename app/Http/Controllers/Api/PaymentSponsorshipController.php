<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\PaymentSponsorshipException;
use App\Services\Payments\PaymentSponsorshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSponsorshipController extends Controller
{
    public function __invoke(
        $id,
        Request $request,
        PaymentSponsorshipService $service
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'sender_signed_tx' => [
                'required',
                'string',
                'max:20000',
                'regex:/\A0x[0-9a-fA-F]+\z/',
            ],
        ]);

        try {
            $transactionHash = $service->sponsor(
                $id,
                $validated['sender_signed_tx']
            );
        } catch (PaymentSponsorshipException $exception) {
            return $this->sponsorshipError($exception);
        }

        return response()->json(['transaction_hash' => $transactionHash]);
    }

    private function sponsorshipError(
        PaymentSponsorshipException $exception
    ): JsonResponse {
        [$message, $status] = match ($exception->reason) {
            PaymentSponsorshipException::PAYMENT_NOT_FOUND => [
                'Payment not found', 404,
            ],
            PaymentSponsorshipException::PAYMENT_ALREADY_CONFIRMED => [
                'Already paid', 400,
            ],
            PaymentSponsorshipException::PAYMENT_NOT_PENDING => [
                'Payment not pending', 400,
            ],
            PaymentSponsorshipException::PAYMENT_EXPIRED => [
                'Expired', 400,
            ],
            PaymentSponsorshipException::INVALID_TRANSACTION => [
                'Invalid sponsored transaction', 400,
            ],
            PaymentSponsorshipException::SPONSORSHIP_CONFLICT => [
                'Fee sponsorship already requested', 409,
            ],
            PaymentSponsorshipException::PROVIDER_REJECTED => [
                'Fee sponsorship rejected', 502,
            ],
            PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN => [
                'Fee sponsorship status is unknown', 503,
            ],
            PaymentSponsorshipException::DISABLED,
            PaymentSponsorshipException::RPC_UNAVAILABLE,
            PaymentSponsorshipException::PROVIDER_UNAVAILABLE => [
                'Fee sponsorship is unavailable', 503,
            ],
            default => ['Fee sponsorship is unavailable', 500],
        };

        return response()->json(['error' => $message], $status);
    }
}
