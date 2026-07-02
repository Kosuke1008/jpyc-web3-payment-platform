<?php

namespace App\Http\Controllers;

use App\Models\Payment;

class PayController extends Controller
{
    public function show($id)
    {
        $payment = Payment::with('store')->findOrFail($id);

        return view('pay', [
            'payment' => $payment
        ]);
    }
}