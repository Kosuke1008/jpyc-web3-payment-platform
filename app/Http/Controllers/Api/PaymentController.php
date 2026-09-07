<?php

namespace App\Http\Controllers\Api;

use App\Blockchain\NetworkExecutionDisabledException;
use App\Blockchain\NetworkProfile;
use App\Blockchain\NetworkProfileRegistry;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentSnapshot;
use App\Services\Payments\FeeDelegationEndpoint;
use App\Services\Payments\PaymentTransactionVerifier;
use App\Services\Payments\PaymentVerificationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class PaymentController extends Controller
{
    public function confirm(
        $id,
        Request $request,
        PaymentTransactionVerifier $verifier
    ): JsonResponse {
        // [Flow M] MetaMask/LivT Wallet共通の入口でtxHashを受け取る。
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
        } catch (NetworkExecutionDisabledException) {
            return response()->json([
                'error' => 'Payment execution is disabled for this network',
            ], 503);
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
            PaymentVerificationException::PAYMENT_SNAPSHOT_UNAVAILABLE => ['Payment snapshot unavailable', 409],
            PaymentVerificationException::CONFIRMATION_EVIDENCE_UNAVAILABLE => ['Confirmation evidence unavailable', 409],
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

    public function create(
        Request $request,
        NetworkProfileRegistry $networks
    ) {
        try {
            $profile = $networks->assertPaymentExecutionAllowed();
        } catch (NetworkExecutionDisabledException) {
            return response()->json([
                'error' => 'Payment execution is disabled for this network',
            ], 503);
        }

        $staff = $request->user();

        $storeId = $staff->store_id;
        $staffId = $staff->id;
        $wallet = Wallet::query()->where('store_id', $storeId)->first();

        if ($wallet === null
            || ! is_string($wallet->address)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $wallet->address) !== 1
            || $wallet->network !== $profile->id) {
            return response()->json([
                'error' => 'Payment configuration invalid',
            ], 500);
        }

        $maximumAmount = $this->positiveConfigurationInteger(
            'blockchain.payment_max_jpy'
        );
        $expirationSeconds = $this->positiveConfigurationInteger(
            'blockchain.payment_expiration_seconds'
        );

        if ($maximumAmount === null || $expirationSeconds === null) {
            return response()->json([
                'error' => 'Payment configuration invalid',
            ], 500);
        }

        try {
            $snapshot = PaymentSnapshot::create(
                profile: $profile,
                recipientAddress: $wallet->address,
                amount: $request->input('amount'),
                expiresAt: now()->addSeconds($expirationSeconds),
                maximumDisplayAmount: $maximumAmount,
            );
        } catch (InvalidPaymentSnapshotException) {
            throw ValidationException::withMessages([
                'amount' => ['The amount must be a canonical integer within the supported range.'],
            ]);
        }

        // [Flow B] 期限付きのpending支払いを既存DBへ作成する。
        $payment = Payment::create(array_merge([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'amount' => (int) $snapshot->displayAmount,
            'status' => 'pending',
        ], $snapshot->databaseAttributes()));

        $payUrl = config('app.url').'/pay/'.$payment->id;

        try {
            $qr = (string) QrCode::format('svg')->size(300)->generate($payUrl);
        } catch (\Exception $e) {
            \Log::error('QR ERROR', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'QR generation failed'], 500);
        }

        // [Flow C] payment IDと既存の支払いURL/QRをPOSへ返す。
        return response()->json([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'pay_url' => $payUrl,
            'qr_code' => $qr,
        ]);
    }

    public function show(
        $id,
        NetworkProfileRegistry $networks
    ): JsonResponse {
        // [Flow E] DBと設定を正本としてsource-neutralな支払い情報を返す。
        $payment = Payment::with('store')->findOrFail($id);

        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $expiresAtIso = $snapshot->expiresAt->toIso8601String();
        } catch (Throwable) {
            return response()->json([
                'error' => 'Payment details unavailable',
            ], 500);
        }

        // chain_name is presentation metadata only. Failure to resolve the
        // current profile must not redefine or hide stored payment semantics.
        try {
            $chainName = $networks->get($snapshot->network)->chainName;
        } catch (Throwable) {
            $chainName = $snapshot->network;
        }

        return response()->json([
            'id' => $payment->id,
            'amount' => (int) $snapshot->displayAmount,
            'display_amount' => $snapshot->displayAmount,
            'atomic_amount' => $snapshot->atomicAmount,
            'status' => $payment->status,
            'store_name' => $payment->store->name ?? 'Store',
            'recipient_address' => $snapshot->recipientAddress,
            'network' => $snapshot->network,
            'chain_name' => $chainName,
            'chain_id' => $snapshot->chainId,
            'token_contract' => $snapshot->tokenContract,
            'token_symbol' => $snapshot->tokenSymbol,
            'token_decimals' => $snapshot->tokenDecimals,
            'expires_at' => $snapshot->expiresAt->format('Y-m-d H:i:s'),
            'expires_at_iso' => $expiresAtIso,
        ]);
    }

    public function sponsorshipAvailability(
        $id,
        NetworkProfileRegistry $networks
    ): JsonResponse {
        $payment = Payment::findOrFail($id);

        try {
            $snapshot = PaymentSnapshot::fromRecord($payment);
            $profile = $networks->get($snapshot->network);
            $active = $networks->active();
        } catch (Throwable) {
            return response()->json(['available' => false]);
        }

        return response()->json([
            'available' => $profile->chainId === $snapshot->chainId
                && $active->id === $snapshot->network
                && $this->feeDelegationAvailable($profile),
        ]);
    }

    private function feeDelegationAvailable(NetworkProfile $profile): bool
    {
        $url = config('services.fee_delegation.url');
        $apiKey = config('services.fee_delegation.api_key');
        $configured = match (config('services.fee_delegation.mode')) {
            'self-hosted' => FeeDelegationEndpoint::isAllowed($url, $apiKey),
            'kaia-managed' => FeeDelegationEndpoint::isManaged($url, $apiKey),
            default => false,
        };

        return config('services.fee_delegation.enabled') === true
            && $profile->feeDelegationExecutionEnabled
            && $configured;
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

    private function positiveConfigurationInteger(string $key): ?int
    {
        $value = config($key);

        if ((! is_int($value) && ! is_string($value))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1) {
            return null;
        }

        $integer = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return is_int($integer) ? $integer : null;
    }
}
