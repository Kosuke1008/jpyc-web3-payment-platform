<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_can_create_a_payment(): void
    {
        [$store, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);

        $response = $this->postJson('/api/payments/create', [
            'amount' => 1_000,
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'payment_id',
                'amount',
                'pay_url',
                'qr_code',
            ])
            ->assertJsonPath('amount', 1_000);

        $paymentId = $response->json('payment_id');
        $payment = Payment::findOrFail($paymentId);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'store_id' => $store->id,
            'staff_id' => $staff->id,
            'amount' => 1_000,
            'status' => 'pending',
            'expires_at' => $payment->expires_at,
        ]);
        $this->assertNotNull($payment->expires_at);
        $this->assertSame(
            config('app.url').'/pay/'.$paymentId,
            $response->json('pay_url')
        );
        $this->assertNotSame('', $response->json('qr_code'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/payments/create', [
            'amount' => 1_000,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_invalid_amount_is_rejected(): void
    {
        [, $staff] = $this->createStaff();
        Sanctum::actingAs($staff, ['payment:create']);

        foreach ([null, 0, 100_001, 'invalid'] as $amount) {
            $this->postJson('/api/payments/create', [
                'amount' => $amount,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('payments', 0);
    }

    private function createStaff(): array
    {
        $store = Store::create([
            'store_code' => 'test-store',
            'store_pin' => 'test-pin',
            'name' => 'Test Store',
        ]);

        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'test-staff',
            'name' => 'Test Staff',
            'pin' => 'test-pin',
            'role' => 'staff',
        ]);

        return [$store, $staff];
    }
}
