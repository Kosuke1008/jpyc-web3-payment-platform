<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PaymentTransactionVerifier;
use App\Services\Payments\PaymentVerificationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PaymentController extends Controller
{
    public function confirm(
        $id,
        Request $request,
        PaymentTransactionVerifier $verifier
    ): JsonResponse {
        if (! $request->user()) {
            return response()->json([
                'error' => 'Unauthenticated user',
            ], 401);
        }

        $validated = $request->validate([
            'tx_hash' => [
                'required',
                'string',
                'regex:/\A0x[0-9a-fA-F]{64}\z/',
            ],
        ]);

        try {
            $verifier->verifyAndConfirm(
                $id,
                $validated['tx_hash'],
                $request->user()->id
            );
        } catch (PaymentVerificationException $exception) {
            return $this->paymentVerificationError($exception);
        }

        return response()->json(['success' => true]);
    }

    private function paymentVerificationError(
        PaymentVerificationException $exception
    ): JsonResponse {
        [$message, $status] = match ($exception->reason) {
            PaymentVerificationException::PAYMENT_NOT_FOUND => ['Payment not found', 404],
            PaymentVerificationException::PAYMENT_ALREADY_CONFIRMED => ['Already paid', 400],
            PaymentVerificationException::PAYMENT_NOT_PENDING => ['Payment not pending', 400],
            PaymentVerificationException::PAYMENT_EXPIRED => ['Expired', 400],
            PaymentVerificationException::DUPLICATE_TRANSACTION_HASH => ['Duplicate tx_hash', 400],
            PaymentVerificationException::RPC_URL_NOT_CONFIGURED => ['WEB3 RPC URL is not configured', 500],
            PaymentVerificationException::TOKEN_CONTRACT_NOT_CONFIGURED => ['ERC20 contract address is not configured', 500],
            PaymentVerificationException::CHAIN_ID_NOT_CONFIGURED => ['WEB3 chain ID is not configured', 500],
            PaymentVerificationException::INVALID_CHAIN_ID_RESPONSE => ['Invalid WEB3 chain ID response', 500],
            PaymentVerificationException::CHAIN_ID_MISMATCH => ['WEB3 RPC chain mismatch', 500],
            PaymentVerificationException::TRANSACTION_NOT_FOUND => ['Transaction not found', 400],
            PaymentVerificationException::TRANSACTION_FAILED => ['Transaction failed', 400],
            PaymentVerificationException::INVALID_TRANSACTION,
            PaymentVerificationException::INVALID_TRANSACTION_HASH => ['Invalid transaction', 400],
            default => ['Payment confirmation failed', 500],
        };

        return response()->json(['error' => $message], $status);
    }

    public function create(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:100000',
        ]);

        $staff = $request->user();

        $storeId = $staff->store_id;
        $staffId = $staff->id;

        $payment = Payment::create([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'amount' => $request->amount,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $payUrl = config('app.url').'/pay/'.$payment->id;

        try {
            $qr = (string) QrCode::format('svg')->size(300)->generate($payUrl);
        } catch (\Exception $e) {
            \Log::error('QR ERROR', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'QR generation failed'], 500);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'pay_url' => $payUrl,
            'qr_code' => $qr,
        ]);
    }

    public function show($id)
    {
        $payment = Payment::with('store')->findOrFail($id);

        return response()->json([
            'id' => $payment->id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'store_name' => $payment->store->name ?? 'Store',
            'expires_at' => $payment->expires_at,
        ]);
    }

    public function status($id)
    {
        $payment = Payment::find($id);

        if (! $payment) {
            return response()->json([
                'error' => 'Not found',
            ], 404);
        }

        return response()->json([
            'status' => $payment->status,
        ]);
    }
}
