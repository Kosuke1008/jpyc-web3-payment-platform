<?php

namespace Tests\Unit;

use App\Services\Payments\BroadcastCertainty;
use App\Services\Payments\PaymentSponsorshipException;
use App\Services\Payments\SelfHostedFeeDelegationGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ValueError;

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
                'protocol_version' => 2,
                'broadcast_certainty' => 'definitely_not_broadcast',
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
            $this->assertSame(
                BroadcastCertainty::DEFINITELY_NOT_BROADCAST,
                $exception->broadcastCertainty
            );
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

    public function test_exact_protocol_v2_certainties_are_carried_internally(): void
    {
        $body = [];
        $this->configureGateway();
        Http::fake(function () use (&$body) {
            return Http::response($body, 400);
        });

        foreach (BroadcastCertainty::cases() as $certainty) {
            $body = [
                'status' => false,
                'error' => 'BAD_REQUEST',
                'protocol_version' => 2,
                'broadcast_certainty' => $certainty->value,
            ];
            $exception = $this->captureException();

            $this->assertSame(
                PaymentSponsorshipException::PROVIDER_REJECTED,
                $exception->reason
            );
            $this->assertSame($certainty, $exception->broadcastCertainty);
        }
    }

    public function test_legacy_or_invalid_protocol_certainty_is_conservative(): void
    {
        $body = [];
        $this->configureGateway();
        Http::fake(function () use (&$body) {
            return Http::response($body, 400);
        });

        foreach ([
            [],
            ['protocol_version' => 1, 'broadcast_certainty' => 'definitely_not_broadcast'],
            ['protocol_version' => 3, 'broadcast_certainty' => 'definitely_not_broadcast'],
            ['protocol_version' => 2, 'broadcast_certainty' => 'unknown'],
            ['protocol_version' => 2, 'broadcast_certainty' => null],
        ] as $metadata) {
            $body = array_merge([
                'status' => false,
                'error' => 'BAD_REQUEST',
            ], $metadata);
            $exception = $this->captureException();

            $this->assertSame(
                BroadcastCertainty::BROADCAST_POSSIBLE,
                $exception->broadcastCertainty
            );
        }
    }

    public function test_malformed_json_is_broadcast_possible(): void
    {
        $this->configureGateway();
        Http::fake(['*' => Http::response('not-json', 503)]);

        try {
            (new SelfHostedFeeDelegationGateway)->sponsor('0x31');
            $this->fail('Expected unknown provider status');
        } catch (PaymentSponsorshipException $exception) {
            $this->assertSame(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                $exception->reason
            );
            $this->assertSame(
                BroadcastCertainty::BROADCAST_POSSIBLE,
                $exception->broadcastCertainty
            );
        }
    }

    public function test_connection_and_timeout_failures_are_broadcast_possible(): void
    {
        foreach (['connection refused', 'cURL error 28: timeout'] as $message) {
            $this->configureGateway();
            Http::fake(fn (Request $request) => (Http::failedConnection(
                $message
            ))($request));

            try {
                (new SelfHostedFeeDelegationGateway)->sponsor('0x31');
                $this->fail('Expected provider connection failure');
            } catch (PaymentSponsorshipException $exception) {
                $this->assertSame(
                    PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                    $exception->reason
                );
                $this->assertSame(
                    BroadcastCertainty::BROADCAST_POSSIBLE,
                    $exception->broadcastCertainty
                );
            }
        }
    }

    public function test_successful_sponsorship_is_submitted_internally(): void
    {
        $this->configureGateway();
        $hash = '0x'.str_repeat('a', 64);
        Http::fake(['*' => Http::response([
            'status' => true,
            'protocol_version' => 2,
            'broadcast_certainty' => 'submitted',
            'data' => [
                'status' => '0x1',
                'hash' => $hash,
                'transactionHash' => $hash,
            ],
        ])]);

        $submission = (new SelfHostedFeeDelegationGateway)->sponsor('0x31');

        $this->assertSame($hash, $submission->transactionHash);
        $this->assertSame(
            BroadcastCertainty::SUBMITTED,
            $submission->broadcastCertainty
        );
    }

    public function test_unknown_enum_value_is_rejected(): void
    {
        $this->expectException(ValueError::class);

        BroadcastCertainty::from('unknown');
    }

    private function captureException(): PaymentSponsorshipException
    {
        try {
            (new SelfHostedFeeDelegationGateway)->sponsor('0x31');
            $this->fail('Expected provider rejection');
        } catch (PaymentSponsorshipException $exception) {
            return $exception;
        }
    }

    private function configureGateway(): void
    {
        config([
            'services.fee_delegation.url' => 'http://127.0.0.1:19000',
            'services.fee_delegation.api_key' => 'unit-test-api-key',
            'services.fee_delegation.timeout_seconds' => 5,
        ]);
    }
}
