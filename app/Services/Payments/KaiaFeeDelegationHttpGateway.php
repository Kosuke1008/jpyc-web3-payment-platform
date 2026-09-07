<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

abstract class KaiaFeeDelegationHttpGateway implements FeeDelegationGateway
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    abstract protected function managed(): bool;

    public function assertConfigured(): void
    {
        $this->configuration();
    }

    public function sponsor(
        string $senderSignedTransaction
    ): FeeDelegationSubmission {
        [$url, $apiKey, $timeout] = $this->configuration();

        try {
            $request = Http::timeout($timeout)
                ->acceptJson()
                ->withOptions(['allow_redirects' => false]);

            if ($apiKey !== null) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post(
                rtrim($url, '/').'/api/signAsFeePayer',
                ['userSignedTx' => ['raw' => $senderSignedTransaction]]
            );
        } catch (ConnectionException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer',
                previous: $exception
            );
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw $this->unknown($response);
        }

        if (! $response->successful()) {
            $definitive = $this->managed()
                ? in_array(
                    $response->status(),
                    [400, 401, 403, 404, 405, 413, 415, 422],
                    true
                )
                : ($response->clientError()
                    && ! in_array($response->status(), [408, 425, 429], true));

            throw new PaymentSponsorshipException(
                $definitive
                    ? PaymentSponsorshipException::PROVIDER_REJECTED
                    : PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer',
                upstreamStatus: $response->status()
            );
        }

        try {
            $body = json_decode(
                $response->body(),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer',
                upstreamStatus: $response->status(),
                previous: $exception
            );
        }

        if (! is_array($body)) {
            throw $this->unknown($response);
        }

        if ($this->managed()
            && (! isset($body['message'])
                || ! is_string($body['message'])
                || $body['message'] === '')) {
            throw $this->unknown($response);
        }

        $providerStatus = $body['status'] ?? null;
        $data = $body['data'] ?? null;
        $receiptStatus = is_array($data)
            ? $this->receiptStatus($data)
            : 'unknown';
        $providerError = $body['error'] ?? null;

        if ($providerStatus === false) {
            $contradictory = $receiptStatus === 'success';
            $reverted = $providerError === 'REVERTED'
                || $receiptStatus === 'failed';
            $explicit = in_array(
                $providerError,
                ['BAD_REQUEST', 'REVERTED'],
                true
            ) || $receiptStatus === 'failed';

            throw new PaymentSponsorshipException(
                match (true) {
                    $reverted && ! $contradictory => PaymentSponsorshipException::PROVIDER_REVERTED,
                    $explicit && ! $contradictory => PaymentSponsorshipException::PROVIDER_REJECTED,
                    default => PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                },
                externalMethod: 'signAsFeePayer',
                upstreamStatus: $response->status()
            );
        }

        if ($providerStatus === true && $receiptStatus === 'failed') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_REVERTED,
                externalMethod: 'signAsFeePayer',
                upstreamStatus: $response->status()
            );
        }

        if ($providerStatus !== true
            || ! is_array($data)
            || (array_key_exists('error', $body) && $providerError !== null)
            || $receiptStatus !== 'success') {
            throw $this->unknown($response);
        }

        $hash = $data['hash'] ?? $data['transactionHash'] ?? null;

        if (isset($data['hash'], $data['transactionHash'])
            && strcasecmp(
                (string) $data['hash'],
                (string) $data['transactionHash']
            ) !== 0) {
            throw $this->unknown($response);
        }

        if (! is_string($hash)
            || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $hash) !== 1) {
            throw $this->unknown($response);
        }

        return new FeeDelegationSubmission(
            strtolower($hash),
            $response->status()
        );
    }

    /** @return array{string, ?string, int} */
    private function configuration(): array
    {
        $url = config('services.fee_delegation.url');
        $apiKey = config('services.fee_delegation.api_key');
        $timeout = config('services.fee_delegation.timeout_seconds');

        $endpointAllowed = $this->managed()
            ? FeeDelegationEndpoint::isManaged($url, $apiKey)
            : FeeDelegationEndpoint::isAllowed($url, $apiKey);

        if (! $endpointAllowed
            || (! is_int($timeout) && ! is_string($timeout))
            || preg_match('/\A[0-9]+\z/', (string) $timeout) !== 1
            || (int) $timeout < 1
            || (int) $timeout > 120) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::CONFIGURATION_ERROR
            );
        }

        return [
            (string) $url,
            is_string($apiKey) && $apiKey !== '' ? $apiKey : null,
            (int) $timeout,
        ];
    }

    private function receiptStatus(array $receipt): string
    {
        $status = $receipt['status'] ?? null;

        if ($status === 1 || $status === '1' || $status === '0x1') {
            return 'success';
        }

        if ($status === 0 || $status === '0' || $status === '0x0') {
            return 'failed';
        }

        return 'unknown';
    }

    private function unknown(Response $response): PaymentSponsorshipException
    {
        return new PaymentSponsorshipException(
            PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
            externalMethod: 'signAsFeePayer',
            upstreamStatus: $response->status()
        );
    }
}
