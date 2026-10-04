<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-user-password';

    public function test_user_and_payment_login_share_the_same_identity_and_ip_failure_limit(): void
    {
        $user = $this->createUser();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/user/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/payment/login', [
                'email' => strtoupper($user->email),
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/user/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertTooManyRequests();

        $this->postJson('/api/user/login', [
            'email' => 'another-user@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_distributed_user_login_failures_are_bounded_by_identity(): void
    {
        $user = $this->createUser();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$attempt}"])
                ->postJson('/api/user/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.21'])
            ->postJson('/api/payment/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])
            ->assertTooManyRequests();
    }

    public function test_staff_login_is_limited_by_normalized_store_and_staff_identity(): void
    {
        [$store, $staff] = $this->createStaff();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/staff/login', [
                'store_code' => "  {$store->store_code}  ",
                'staff_id' => strtoupper($staff->staff_id),
                'pin' => 'wrong-pin',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/staff/login', [
            'store_code' => $store->store_code,
            'staff_id' => $staff->staff_id,
            'pin' => 'correct-staff-pin',
        ])->assertTooManyRequests();
    }

    public function test_successful_logins_do_not_consume_the_failure_budget(): void
    {
        $user = $this->createUser();

        for ($attempt = 0; $attempt < 7; $attempt++) {
            $this->postJson('/api/payment/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertOk();
        }
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Rate Limited User',
            'email' => 'rate-limited-user@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /**
     * @return array{Store, Staff}
     */
    private function createStaff(): array
    {
        $store = Store::create([
            'store_code' => 'rate-limit-store',
            'store_pin' => Hash::make('unused-store-pin'),
            'name' => 'Rate Limit Store',
        ]);
        $staff = Staff::create([
            'store_id' => $store->id,
            'staff_id' => 'rate-limit-staff',
            'name' => 'Rate Limit Staff',
            'pin' => Hash::make('correct-staff-pin'),
            'role' => 'staff',
        ]);

        return [$store, $staff];
    }
}
