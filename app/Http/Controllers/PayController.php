<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Uri;

class PayController extends Controller
{
    public function show($id)
    {
        $payment = Payment::with('store.wallet')->findOrFail($id);
        $recipientAddress = $payment->store?->wallet?->address;

        if (! is_string($recipientAddress)
            || preg_match('/\A0x[0-9a-fA-F]{40}\z/', $recipientAddress) !== 1) {
            abort(500, 'Payment configuration invalid');
        }

        return view('pay', [
            'payment' => $payment,
            'recipientAddress' => strtolower($recipientAddress),
            'paymentExpiresAt' => $payment->expires_at
                ? Carbon::parse($payment->expires_at)
                : null,
            'livtWalletPaymentUrl' => $this->livtWalletPaymentUrl($payment),
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
