<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class UserPaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $payments = Payment::with(['store'])
            ->where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->orderByDesc('paid_at')
            ->get();

        return response()->json([
            'payments' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'store_name' => $payment->store->name ?? '不明な店舗',
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'tx_hash' => $payment->tx_hash,
                    'paid_at' => $payment->paid_at,
                ];
            })
        ]);
    }
}