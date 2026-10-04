<?php

namespace App\Payments;

use App\Models\Payment;

final class PaymentDisplayStatus
{
    public static function for(Payment $payment): string
    {
        if ($payment->status === 'pending'
            && $payment->expires_at !== null
            && $payment->expires_at->lte(now())) {
            return 'expired';
        }

        return $payment->status;
    }
}
