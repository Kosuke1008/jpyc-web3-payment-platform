<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Uri;

class PayController extends Controller
{
    public function show($id)
    {
        $payment = Payment::with('store')->findOrFail($id);

        return view('pay', [
            'payment' => $payment,
            'paymentExpiresAt' => $payment->expires_at
                ? Carbon::parse($payment->expires_at)
                : null,
            'livtWalletPaymentUrl' => $this->livtWalletPaymentUrl($payment),
        ]);
    }

    private function livtWalletPaymentUrl(Payment $payment): ?string
    {
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
