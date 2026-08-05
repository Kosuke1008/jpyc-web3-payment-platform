<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class KaiaFeeDelegationGateway implements FeeDelegationGateway
{
    public function sponsor(string $senderSignedTransaction): string
    {
        [$url, $apiKey, $timeout] = $this->configuration();

        try {
            $request = Http::timeout($timeout)->acceptJson();

            if ($apiKey !== null) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post(
                rtrim($url, '/').'/api/signAsFeePayer',
                [
                    'userSignedTx' => ['raw' => $senderSignedTransaction],
                ]
            );
        } catch (ConnectionException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer',
                previous: $exception
            );
        }

        if (! $response->successful()) {
            $isDefinitiveClientRejection = $response->clientError()
                && ! in_array($response->status(), [408, 425, 429], true);

            throw new PaymentSponsorshipException(
                $isDefinitiveClientRejection
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
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer',
                previous: $exception
            );
        }

        if (! is_array($body)) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer'
            );
        }

        $providerStatus = $body['status'] ?? null;
        $data = $body['data'] ?? null;
        $receiptStatus = is_array($data)
            ? $this->receiptStatus($data)
            : 'unknown';
        $providerError = $body['error'] ?? null;

        if ($providerStatus === false) {
            $isContradictory = $receiptStatus === 'success';
            $isExplicitRejection = in_array(
                $providerError,
                ['BAD_REQUEST', 'REVERTED'],
                true
            ) || $receiptStatus === 'failed';

            throw new PaymentSponsorshipException(
                $isExplicitRejection && ! $isContradictory
                    ? PaymentSponsorshipException::PROVIDER_REJECTED
                    : PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer'
            );
        }

        if ($providerStatus !== true
            || ! is_array($data)
            || (array_key_exists('error', $body)
                && $providerError !== null)
            || $receiptStatus === 'unknown') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer'
            );
        }

        if ($receiptStatus === 'failed') {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_REJECTED,
                externalMethod: 'signAsFeePayer'
            );
        }

        $hash = $data['hash']
            ?? $data['transactionHash']
            ?? null;

        if (isset($data['hash'], $data['transactionHash'])
            && strcasecmp(
                (string) $data['hash'],
                (string) $data['transactionHash']
            ) !== 0) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer'
            );
        }

        if (! is_string($hash)
            || preg_match('/\A0x[0-9a-fA-F]{64}\z/', $hash) !== 1) {
            throw new PaymentSponsorshipException(
                PaymentSponsorshipException::PROVIDER_STATUS_UNKNOWN,
                externalMethod: 'signAsFeePayer'
            );
        }

        return strtolower($hash);
    }

    private function configuration(): array
    {
        $url = config('services.fee_delegation.url');
        $apiKey = config('services.fee_delegation.api_key');
        $timeout = config('services.fee_delegation.timeout_seconds');

        if (! FeeDelegationEndpoint::isAllowed($url, $apiKey)
            || (! is_null($apiKey) && ! is_string($apiKey))
            || (is_string($apiKey)
                && (strlen($apiKey) > 4096 || preg_match('/\s/', $apiKey)))
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
}
