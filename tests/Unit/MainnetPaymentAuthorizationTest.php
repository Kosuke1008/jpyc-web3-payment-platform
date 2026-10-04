<?php

namespace Tests\Unit;

use App\Services\Payments\MainnetPaymentAuthorization;
use App\Services\Payments\PaymentSponsorshipException;
use Tests\TestCase;

final class MainnetPaymentAuthorizationTest extends TestCase
{
    public function test_matches_the_fee_payer_golden_vector(): void
    {
        config([
            'services.fee_delegation.self_hosted_mainnet.authorization_key' => str_repeat('c', 64),
        ]);

        $authorization = app(MainnetPaymentAuthorization::class)->issue(
            1,
            '2099-01-01T00:00:00Z',
            '0xfb00cdc0a693be52d7cb7c3d02a4002e4fc07832b0ebd59d80f8256a1a15356e'
        );

        $this->assertSame(
            '09f7561b595d9e90a3ad8e69519a3407dafe63019685023af7a15e174017193d',
            $authorization
        );
        $this->assertSame(
            'sha256:c2f480d4dda9f452',
            MainnetPaymentAuthorization::keyId()
        );
    }

    public function test_missing_or_malformed_key_fails_closed(): void
    {
        foreach ([null, '', 'secret', str_repeat('g', 64)] as $key) {
            config([
                'services.fee_delegation.self_hosted_mainnet.authorization_key' => $key,
            ]);

            try {
                app(MainnetPaymentAuthorization::class)->issue(
                    1,
                    '2099-01-01T00:00:00Z',
                    '0x'.str_repeat('a', 64)
                );
                $this->fail('Expected invalid authorization key rejection.');
            } catch (PaymentSponsorshipException $exception) {
                $this->assertSame(
                    PaymentSponsorshipException::CONFIGURATION_ERROR,
                    $exception->reason
                );
            }
        }
    }
}
