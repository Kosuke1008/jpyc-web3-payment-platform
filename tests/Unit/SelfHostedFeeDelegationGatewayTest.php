<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentSponsorshipException;
use App\Services\Payments\SelfHostedFeeDelegationGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelfHostedFeeDelegationGatewayTest extends TestCase
{
    public function test_explicit_signer_timeout_is_classified_before_broadcast(): void
    {
        config([
            'services.fee_delegation.url' => 'http://127.0.0.1:19000',
            'services.fee_delegation.api_key' => 'unit-test-api-key',
            'services.fee_delegation.timeout_seconds' => 5,
        ]);
        Http::fake([
            '*' => Http::response([
                'status' => false,
                'error' => 'SIGNER_TIMEOUT',
            ], 503),
        ]);

        try {
            (new SelfHostedFeeDelegationGateway)->sponsor('0x31');
            $this->fail('Expected pre-broadcast signer failure');
        } catch (PaymentSponsorshipException $exception) {
            $this->assertSame(
                PaymentSponsorshipException::SIGNER_PRE_BROADCAST_FAILED,
                $exception->reason
            );
            $this->assertSame('signer_timeout', $exception->diagnosticCode);
            $this->assertSame(503, $exception->upstreamStatus);
        }
    }

    public function test_unknown_sidecar_error_remains_submission_unknown(): void
    {
        config([
            'services.fee_delegation.url' => 'http://127.0.0.1:19000',
            'services.fee_delegation.api_key' => 'unit-test-api-key',
            'services.fee_delegation.timeout_seconds' => 5,
        ]);
        Http::fake([
            '*' => Http::response([
                'status' => false,
                'error' => 'INTERNAL_ERROR',
            ], 503),
        ]);

        $this->expectExceptionObject(new PaymentSponsorshipException(
            PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
            externalMethod: 'signAsFeePayer',
            upstreamStatus: 503
        ));

        (new SelfHostedFeeDelegationGateway)->sponsor('0x31');
    }
}
