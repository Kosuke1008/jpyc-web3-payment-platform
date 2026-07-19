<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentTransactionVerifier;
use App\Services\Payments\PaymentVerificationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class PaymentController extends Controller
{
    public function confirm(
        $id,
        Request $request,
        PaymentTransactionVerifier $verifier
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'Unauthenticated user',
            ], 401);
        }

        if (! $user instanceof User) {
            return response()->json([
                'error' => 'Forbidden',
            ], 403);
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
                $user->id
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
            PaymentVerificationException::RPC_TRANSPORT_FAILURE => ['WEB3 RPC is unavailable', 503],
            PaymentVerificationException::MALFORMED_RPC_RESPONSE => ['Invalid WEB3 RPC response', 500],
            PaymentVerificationException::JSON_RPC_PROVIDER_ERROR => ['WEB3 RPC provider error', 502],
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

    public function show($id): JsonResponse
    {
        $payment = Payment::with('store.wallet')->findOrFail($id);
        $amount = (string) $payment->amount;
        $recipientAddress = $payment->store?->wallet?->address;
        $chainId = config('services.web3.chain_id');
        $network = config('services.web3.network');
        $chainName = config('services.web3.chain_name');
        $tokenContract = config('services.web3.erc20_contract_address');
        $tokenSymbol = config('services.web3.token_symbol');
        $tokenDecimals = config('services.web3.token_decimals');
        $legacyExpiresAt = $payment->expires_at;

        try {
            $expiresAtIso = $legacyExpiresAt
                ? Carbon::parse($legacyExpiresAt)->toIso8601String()
                : null;
        } catch (Throwable) {
            return response()->json([
                'error' => 'Payment details unavailable',
            ], 500);
        }

        if (preg_match('/\A[0-9]+\z/', $amount) !== 1
            || ! is_string($recipientAddress)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $recipientAddress) !== 1
            || (! is_int($chainId) && ! is_string($chainId))
            || preg_match('/\A[0-9]+\z/', (string) $chainId) !== 1
            || gmp_cmp(gmp_init((string) $chainId, 10), 0) <= 0
            || ! is_string($network)
            || $network === ''
            || ! is_string($chainName)
            || $chainName === ''
            || ! is_string($tokenContract)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $tokenContract) !== 1
            || ! is_string($tokenSymbol)
            || $tokenSymbol === ''
            || (! is_int($tokenDecimals) && ! is_string($tokenDecimals))
            || preg_match('/\A[0-9]+\z/', (string) $tokenDecimals) !== 1
            || gmp_cmp(gmp_init((string) $tokenDecimals, 10), 255) > 0) {
            return response()->json([
                'error' => 'Payment details unavailable',
            ], 500);
        }

        $decimals = gmp_strval(gmp_init((string) $tokenDecimals, 10), 10);
        $atomicAmount = bcmul(
            $amount,
            bcpow('10', $decimals, 0),
            0
        );

        return response()->json([
            'id' => $payment->id,
            'amount' => $payment->amount,
            'display_amount' => $amount,
            'atomic_amount' => $atomicAmount,
            'status' => $payment->status,
            'store_name' => $payment->store->name ?? 'Store',
            'recipient_address' => strtolower($recipientAddress),
            'network' => $network,
            'chain_name' => $chainName,
            'chain_id' => (int) $chainId,
            'token_contract' => strtolower($tokenContract),
            'token_symbol' => $tokenSymbol,
            'token_decimals' => (int) $decimals,
            'expires_at' => $legacyExpiresAt,
            'expires_at_iso' => $expiresAtIso,
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
