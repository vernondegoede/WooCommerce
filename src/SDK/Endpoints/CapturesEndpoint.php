<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\AmountNullable;
use Mollie\Api\Models\Components\CaptureResponse;
use Mollie\Api\Models\Components\EntityCapture;
use Mollie\WooCommerce\SDK\ApiException;
use Throwable;

class CapturesEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @throws ApiException
     */
    public function createForId(string $paymentId, array $data = [], ?string $idempotencyKey = null): ?CaptureResponse
    {
        try {
            $amount = null;
            if (isset($data['amount']['currency'], $data['amount']['value'])) {
                $amount = new AmountNullable(
                    currency: (string) $data['amount']['currency'],
                    value: (string) $data['amount']['value']
                );
            }

            $body = new EntityCapture(
                description: isset($data['description']) ? (string) $data['description'] : null,
                amount: $amount,
                metadata: $data['metadata'] ?? null,
                testmode: isset($data['testmode']) ? (bool) $data['testmode'] : null
            );

            $response = $this->sdk->captures->create(
                paymentId: $paymentId,
                body: $body,
                idempotencyKey: $idempotencyKey
            );

            return $response->captureResponse;
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
