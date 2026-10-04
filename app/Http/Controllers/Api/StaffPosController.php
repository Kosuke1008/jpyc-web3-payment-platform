<?php

namespace App\Http\Controllers\Api;

use App\Blockchain\NetworkProfileRegistry;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Wallet;
use App\Payments\MainnetPaymentCreationPolicy;
use App\Payments\MainnetPilotLimits;
use App\Payments\PaymentDisplayStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class StaffPosController extends Controller
{
    public function context(
        Request $request,
        NetworkProfileRegistry $networks,
        MainnetPaymentCreationPolicy $mainnetCreation
    ): JsonResponse {
        $staff = $request->user();
        if (! $staff instanceof Staff) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $profile = $networks->active();
            $store = $staff->store()->firstOrFail();
            $wallet = Wallet::query()->where('store_id', $store->id)->first();
            $maximum = $profile->isKaiaMainnet()
                ? MainnetPilotLimits::paymentMaximum()
                : $this->positiveInteger(config('blockchain.payment_max_jpy'));
            $walletReady = $wallet !== null
                && preg_match('/\A0x[0-9a-fA-F]{40}\z/', (string) $wallet->address) === 1
                && $wallet->network === $profile->id;
            $executionAvailable = true;
            try {
                if ($profile->isKaiaMainnet() && $wallet !== null) {
                    $mainnetCreation->assertConfigurationAuthorized(
                        $profile,
                        $staff,
                        $wallet
                    );
                } else {
                    $networks->assertPaymentExecutionAllowed($profile);
                }
            } catch (Throwable) {
                $executionAvailable = false;
            }
            $products = collect(config('pos.products', []))
                ->filter(fn (mixed $product): bool => is_array($product)
                    && is_string($product['code'] ?? null)
                    && preg_match('/\A[a-z0-9-]{1,32}\z/', $product['code']) === 1
                    && is_string($product['name'] ?? null)
                    && trim($product['name']) !== ''
                    && mb_strlen($product['name']) <= 40
                    && $this->isPositiveInteger($product['amount'] ?? null)
                    && bccomp((string) $product['amount'], $maximum, 0) <= 0)
                ->map(fn (array $product): array => [
                    'code' => $product['code'],
                    'name' => trim($product['name']),
                    'amount' => (int) $product['amount'],
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return response()->json([
                'error' => 'POS configuration unavailable',
            ], 503);
        }

        return response()->json([
            'ready' => $walletReady,
            'creation_available' => $walletReady
                && $executionAvailable,
            'store' => ['id' => $store->id, 'name' => $store->name],
            'staff' => ['id' => $staff->id, 'name' => $staff->name],
            'wallet' => $walletReady ? [
                'address' => strtolower($wallet->address),
                'network' => $wallet->network,
            ] : null,
            'network' => [
                'id' => $profile->id,
                'name' => $profile->chainName,
                'chain_id' => $profile->chainId,
                'testnet' => $profile->testnet,
                'token_symbol' => $profile->jpycSymbol,
            ],
            'maximum_amount' => (int) $maximum,
            'expiration_seconds' => (int) $this->positiveInteger(
                config('blockchain.payment_expiration_seconds')
            ),
            'products' => $products,
            'diagnostic' => $walletReady
                ? ($executionAvailable ? 'none' : 'payment_execution_disabled')
                : 'store_wallet_or_network_mismatch',
        ]);
    }

    public function history(
        Request $request,
        NetworkProfileRegistry $networks
    ): JsonResponse {
        $staff = $request->user();
        if (! $staff instanceof Staff) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        try {
            $profile = $networks->active();
            $limit = min((int) $this->positiveInteger(
                config('pos.history_limit', 20)
            ), 100);
            $payments = Payment::query()
                ->where('store_id', $staff->store_id)
                ->where('network', $profile->id)
                ->latest('id')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return response()->json([
                'error' => 'Payment history unavailable',
            ], 503);
        }

        return response()->json([
            'network' => $profile->id,
            'payments' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'amount' => (int) ($payment->display_amount ?? $payment->amount),
                'token_symbol' => $payment->token_symbol ?? 'JPYC',
                'status' => PaymentDisplayStatus::for($payment),
                'created_at' => $payment->created_at?->toIso8601String(),
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'tx_hash' => $payment->tx_hash,
            ])->all(),
        ]);
    }

    private function positiveInteger(mixed $value): string
    {
        if (! $this->isPositiveInteger($value)) {
            throw new \RuntimeException('pos_positive_integer_required');
        }

        return (string) $value;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/\A[1-9][0-9]*\z/', (string) $value) === 1;
    }
}
