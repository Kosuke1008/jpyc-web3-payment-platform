<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Payment extends Model
{
    private const SNAPSHOT_ATTRIBUTES = [
        'network',
        'network_profile_version',
        'chain_id',
        'token_contract',
        'token_symbol',
        'token_decimals',
        'recipient_address',
        'display_amount',
        'atomic_amount',
        'expires_at',
    ];

    private const CONFIRMATION_EVIDENCE_ATTRIBUTES = [
        'tx_hash',
        'observed_chain_id',
        'confirmed_block_number',
        'confirmed_block_hash',
        'receipt_status',
        'payer_address',
        'transfer_log_index',
        'chain_confirmed_at',
        'verified_at',
        'paid_at',
        'user_id',
    ];

    protected $fillable = [
        'store_id',
        'staff_id',
        'amount',
        'status',
        'tx_hash',
        'paid_at',
        'network',
        'network_profile_version',
        'chain_id',
        'token_contract',
        'token_symbol',
        'token_decimals',
        'recipient_address',
        'display_amount',
        'atomic_amount',
        'expires_at',
        'observed_chain_id',
        'confirmed_block_number',
        'confirmed_block_hash',
        'receipt_status',
        'payer_address',
        'transfer_log_index',
        'chain_confirmed_at',
        'verified_at',
        'reconciliation_status',
        'reconciliation_error_code',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'network_profile_version' => 'integer',
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'display_amount' => 'string',
            'expires_at' => 'datetime',
            'observed_chain_id' => 'integer',
            'confirmed_block_number' => 'integer',
            'receipt_status' => 'integer',
            'transfer_log_index' => 'integer',
            'chain_confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            foreach (self::SNAPSHOT_ATTRIBUTES as $attribute) {
                if ($payment->isDirty($attribute)) {
                    throw new LogicException(
                        'Payment snapshot attributes are immutable.'
                    );
                }
            }

            foreach (self::CONFIRMATION_EVIDENCE_ATTRIBUTES as $attribute) {
                if ($payment->isDirty($attribute)
                    && ($payment->getOriginal('status') === 'confirmed'
                        || $payment->getOriginal($attribute) !== null)) {
                    throw new LogicException(
                        'Payment confirmation evidence is immutable.'
                    );
                }
            }
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }

    public function feeDelegationAttempt()
    {
        return $this->hasOne(PaymentFeeDelegationAttempt::class);
    }
}
