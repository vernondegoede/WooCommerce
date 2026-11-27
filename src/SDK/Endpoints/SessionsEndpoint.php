<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\SessionRequest;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use stdClass;
use Throwable;

class SessionsEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * Create a Mollie Checkout Session (POST /v2/sessions).
     *
     * @param array<string, mixed>|SessionRequest $payload
     * @return stdClass
     * @throws ApiException
     */
    public function create(array|SessionRequest $payload, ?string $idempotencyKey = null): stdClass
    {
        try {
            $body = $payload instanceof SessionRequest
                ? $payload
                : Normalizer::serializer()->deserialize(
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    SessionRequest::class,
                    'json'
                );

            $response = $this->sdk->checkoutSessions->create(
                body: $body,
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
     * Get a Mollie Checkout Session (GET /v2/sessions/{sessionId}).
     *
     * @return stdClass
     * @throws ApiException
     */
    public function get(string $sessionId, ?string $idempotencyKey = null): stdClass
    {
        try {
            $response = $this->sdk->checkoutSessions->get(
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
}
