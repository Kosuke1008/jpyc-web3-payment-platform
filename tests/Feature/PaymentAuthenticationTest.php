<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PaymentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'payment-login-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.livt_wallet.payment_token_expiration_minutes' => '30',
        ]);
    }

    public function test_payment_login_issues_a_short_lived_scoped_token_without_revoking_existing_tokens(): void
    {
        Carbon::setTestNow('2026-07-18T03:00:00+00:00');
        $user = $this->createUser();
        $existingToken = $user->createToken('existing-user-token');

        $response = $this->postJson('/api/payment/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', '2026-07-18T03:30:00+00:00')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', $user->name)
            ->assertJsonMissingPath('user.email')
            ->assertJsonStructure(['token']);

        $this->assertSame(2, $user->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $existingToken->accessToken->id,
            'name' => 'existing-user-token',
        ]);

        $paymentToken = $user->tokens()
            ->where('name', 'payment-confirmation')
            ->firstOrFail();

        $this->assertSame(['payment:confirm'], $paymentToken->abilities);
        $this->assertSame(
            '2026-07-18T03:30:00+00:00',
            $paymentToken->expires_at->toIso8601String()
        );
    }

    public function test_payment_token_can_reach_the_existing_confirm_endpoint(): void
    {
        $user = $this->createUser();
        $token = $user->createToken(
            'payment-confirmation',
            ['payment:confirm'],
            now()->addMinutes(30)
        )->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/payments/1/confirm', ['tx_hash' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tx_hash');
    }

    public function test_payment_token_can_only_read_its_safe_payment_session(): void
    {
        $user = $this->createUser();
        $token = $user->createToken(
            'payment-confirmation',
            ['payment:confirm'],
            now()->addMinutes(30)
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/payment/session')
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
            ]);

        $this->withToken($token)
            ->getJson('/api/user/me')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/user/payments')
            ->assertForbidden();
    }

    public function test_existing_wildcard_user_token_remains_compatible_with_confirm(): void
    {
        $user = $this->createUser();
        $token = $user
            ->createToken('existing-user-token')
            ->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/payments/1/confirm', ['tx_hash' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tx_hash');

        $this->withToken($token)
            ->getJson('/api/user/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_token_without_payment_confirmation_ability_is_rejected(): void
    {
        $token = $this->createUser()
            ->createToken('unrelated-token', ['profile:read'])
            ->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/payments/1/confirm', [
                'tx_hash' => '0x'.str_repeat('a', 64),
            ])
            ->assertForbidden();
    }

    public function test_invalid_payment_login_credentials_do_not_issue_a_token(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/payment/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_invalid_or_excessive_payment_token_lifetime_is_rejected(): void
    {
        $user = $this->createUser();
        config([
            'services.livt_wallet.payment_token_expiration_minutes' => '1440',
        ]);

        $this->postJson('/api/payment/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])
            ->assertInternalServerError()
            ->assertExactJson([
                'message' => 'Payment authentication is unavailable',
            ]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_expired_payment_token_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-18T03:00:00+00:00');
        $token = $this->createUser()
            ->createToken(
                'payment-confirmation',
                ['payment:confirm'],
                now()->addMinute()
            )->plainTextToken;

        Carbon::setTestNow('2026-07-18T03:01:01+00:00');

        $this->withToken($token)
            ->getJson('/api/payment/session')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_payment_token(): void
    {
        $user = $this->createUser();
        $user->createToken('existing-user-token');
        $paymentToken = $user->createToken(
            'payment-confirmation',
            ['payment:confirm'],
            now()->addMinutes(30)
        );

        $this->withToken($paymentToken->plainTextToken)
            ->postJson('/api/user/logout')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $paymentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'existing-user-token',
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Payment Login User',
            'email' => 'payment-login@example.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }
}
