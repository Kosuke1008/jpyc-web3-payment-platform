<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'payment_id',
        'tx_hash',
        'from_address',
        'to_address',
        'amount',
        'confirmed_at'
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
