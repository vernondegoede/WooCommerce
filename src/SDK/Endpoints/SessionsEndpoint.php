<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use GuzzleHttp\Psr7\Request;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\SessionRequest;
use Mollie\Api\Models\Errors\APIException as SdkApiException;
use Mollie\Api\Models\Errors\ErrorResponse;
use Mollie\Api\Utils\Retry\RetryConfigBackoff;
use Mollie\Api\Utils\Retry\RetryUtils;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\IdempotencyKey;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use Speakeasy\Serializer\DeserializationContext;
use stdClass;
use Throwable;

class SessionsEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * Create a Mollie session (POST /v2/sessions).
     *
     * When the payload carries the private-beta `shipping` key (not yet modeled on
     * SessionRequest in mollie/php), the request is sent directly through the SDK's
     * configured security/HTTP client so `shipping` is not dropped during serialization.
     *
     * @param array<string, mixed> $payload
     * @return stdClass
     * @throws ApiException
     */
    public function create(array $payload, ?string $idempotencyKey = null): stdClass
    {
        $resolvedKey = IdempotencyKey::resolve($idempotencyKey);

        try {
            if (array_key_exists('shipping', $payload)) {
                return $this->createWithRawPayload($payload, $resolvedKey);
            }

            $body = Normalizer::serializer()->deserialize(
                json_encode($payload, JSON_THROW_ON_ERROR),
                SessionRequest::class,
                'json'
            );

            $response = $this->sdk->sessions->create(
                body: $body,
                idempotencyKey: $resolvedKey
            );

            if ($response->sessionResponse === null) {
                throw new ApiException('Empty session response from Mollie API.', $response->statusCode);
            }

            $plain = Normalizer::toPlainObject($response->sessionResponse);

            return $plain instanceof stdClass ? $plain : new stdClass();
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * Get a Mollie session (GET /v2/sessions/{sessionId}).
     *
     * @return stdClass
     * @throws ApiException
     */
    public function get(string $sessionId, ?string $idempotencyKey = null): stdClass
    {
        try {
            $response = $this->sdk->sessions->get(
                sessionId: $sessionId,
                idempotencyKey: $idempotencyKey
            );

            if ($response->sessionResponse === null) {
                throw new ApiException('Empty session response from Mollie API.', $response->statusCode);
            }

            $plain = Normalizer::toPlainObject($response->sessionResponse);

            return $plain instanceof stdClass ? $plain : new stdClass();
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException
     */
    private function createWithRawPayload(array $payload, string $idempotencyKey): stdClass
    {
        $config = $this->sdk->sdkConfiguration;
        $baseUrl = rtrim($config->getTemplatedServerUrl(), '/');
        $url = $baseUrl . '/v2/sessions';
        $headers = [
            'Accept' => 'application/hal+json',
            'Content-Type' => 'application/json',
            'user-agent' => $config->userAgent,
            'idempotency-key' => $idempotencyKey,
        ];

        $httpRequest = new Request('POST', $url);
        $httpOptions = [
            'http_errors' => false,
            'headers' => $headers,
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];

        $client = $config->client;
        if ($client === null) {
            throw new ApiException('Mollie SDK HTTP client is not initialized.', 0);
        }

        $retryConfig = $config->retryConfig ?? new RetryConfigBackoff(
            initialIntervalMs: 500,
            maxIntervalMs: 2500,
            exponent: 2,
            maxElapsedTimeMs: 5000,
            retryConnectionErrors: true
        );

        $httpResponse = RetryUtils::retryWrapper(
            static fn () => $client->send($httpRequest, $httpOptions),
            $retryConfig,
            ['429', '5xx']
        );
        $statusCode = $httpResponse->getStatusCode();
        $responseBody = (string) $httpResponse->getBody();

        if ($statusCode >= 200 && $statusCode < 300) {
            $decoded = json_decode($responseBody, false);
            if ($decoded instanceof stdClass) {
                return $decoded;
            }
            throw new ApiException('Unable to decode Mollie session response.', $statusCode);
        }

        if ($statusCode === 422 || $statusCode === 429 || $statusCode === 404 || $statusCode === 401) {
            try {
                $errorObj = Normalizer::serializer()->deserialize(
                    $responseBody,
                    ErrorResponse::class,
                    'json',
                    DeserializationContext::create()->setRequireAllRequiredProperties(true)
                );
                $errorObj->rawResponse = $httpResponse;
                throw ApiException::fromSdkThrowable($errorObj->toException());
            } catch (ApiException $e) {
                throw $e;
            } catch (Throwable $ignored) {
                // Fall through to generic SDK exception below.
            }
        }

        throw ApiException::fromSdkThrowable(
            new SdkApiException('API error occurred', $statusCode, $responseBody, $httpResponse)
        );
    }
}
