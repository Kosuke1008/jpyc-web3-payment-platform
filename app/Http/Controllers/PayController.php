<?php

namespace App\Http\Controllers;

use App\Blockchain\NetworkExecutionDisabledException;
use App\Blockchain\NetworkProfileRegistry;
use App\Models\Payment;
use App\Payments\InvalidPaymentSnapshotException;
use App\Payments\PaymentSnapshot;
use Illuminate\Support\Uri;
use Throwable;

class PayController extends Controller
{
    public function show($id, NetworkProfileRegistry $networks)
    {
        $payment = Payment::with('store')->findOrFail($id);

        try {
            $paymentSnapshot = PaymentSnapshot::fromRecord($payment);
            $networkProfile = $networks->get($paymentSnapshot->network);

            if ($networkProfile->chainId !== $paymentSnapshot->chainId) {
                throw new InvalidPaymentSnapshotException(
                    'Payment chain snapshot does not match its network.'
                );
            }

            $networks->assertPaymentExecutionAllowed($networkProfile);
        } catch (NetworkExecutionDisabledException) {
            abort(503, 'Payment execution is disabled for this network');
        } catch (Throwable) {
            abort(500, 'Payment configuration invalid');
        }

        return view('pay', [
            'payment' => $payment,
            'paymentSnapshot' => $paymentSnapshot,
            'recipientAddress' => $paymentSnapshot->recipientAddress,
            'paymentExpiresAt' => $paymentSnapshot->expiresAt,
            'livtWalletPaymentUrl' => $this->livtWalletPaymentUrl($payment),
            'networkProfile' => $networkProfile,
        ]);
    }

    private function livtWalletPaymentUrl(Payment $payment): ?string
    {
        // [Flow D] 支払いIDだけをWalletへ渡し、金額や宛先は渡さない。
        $walletUrl = config('services.livt_wallet.url');

        if (! is_string($walletUrl) || filter_var($walletUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($walletUrl);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $localHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];

        if (isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($scheme !== 'https'
                && ! ($scheme === 'http' && in_array($host, $localHosts, true)))) {
            return null;
        }

        return (string) Uri::of($walletUrl)->withQuery([
            'payment_id' => (string) $payment->id,
        ]);
    }
}
