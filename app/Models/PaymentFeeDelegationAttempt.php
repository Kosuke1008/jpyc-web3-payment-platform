<?php

namespace App\Models;

use App\Services\Payments\FeeDelegationAttemptState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PaymentFeeDelegationAttempt extends Model
{
    protected $fillable = [
        'payment_id',
        'requester_user_id',
        'network',
        'chain_id',
        'provider',
        'sender_address',
        'sender_tx_hash',
        'tx_hash',
        'request_fingerprint',
        'state',
        'provider_http_status',
        'diagnostic_code',
        'sender_nonce',
        'validated_at',
        'submitting_at',
        'submitted_at',
        'receipt_observed_at',
        'resolved_at',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'provider_http_status' => 'integer',
            'validated_at' => 'datetime',
            'submitting_at' => 'datetime',
            'submitted_at' => 'datetime',
            'receipt_observed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (PaymentFeeDelegationAttempt $attempt): void {
            $attempt->writeOperationalLog(null, $attempt->state);
        });
    }

    public function transitionTo(string $state, array $attributes = []): void
    {
        $previousState = $this->state;
        FeeDelegationAttemptState::assertTransition($previousState, $state);
        $this->forceFill(array_merge($attributes, ['state' => $state]));
        $this->save();
        $this->writeOperationalLog($previousState, $state);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    private function writeOperationalLog(?string $from, string $to): void
    {
        Log::info('fee_delegation_attempt_state', [
            'payment_id' => $this->payment_id,
            'attempt_id' => $this->id,
            'provider' => $this->provider,
            'sender_tx_hash' => $this->sender_tx_hash,
            'tx_hash' => $this->tx_hash,
            'diagnostic_code' => $this->diagnostic_code,
            'state_from' => $from,
            'state_to' => $to,
        ]);
    }
}
