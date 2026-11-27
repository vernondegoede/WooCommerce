<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\EntityCustomer;
use Mollie\Api\Models\Components\LocaleResponse;
use Mollie\Api\Models\Operations\GetCustomerRequest;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\IdempotencyKey;
use Mollie\WooCommerce\SDK\Resources\Customer;
use Throwable;

class CustomersEndpoint
{
    public function __construct(
        private Client $sdk,
        private MandatesEndpoint $mandatesEndpoint
    ) {
    }

    /**
     * @throws ApiException
     */
    public function get(?string $customerId): Customer
    {
        if ($customerId === null || $customerId === '') {
            throw new ApiException('Invalid customer ID.');
        }

        try {
            $response = $this->sdk->customers->get(
                new GetCustomerRequest(customerId: $customerId)
            );
            if ($response->customerResponse === null) {
                throw new ApiException('Empty customer response from Mollie API.', $response->statusCode);
            }

            return Customer::fromResponse($response->customerResponse, $this->mandatesEndpoint);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws ApiException
     */
    public function create(array $data, ?string $idempotencyKey = null): Customer
    {
        try {
            $body = new EntityCustomer(
                name: isset($data['name']) ? (string) $data['name'] : null,
                email: isset($data['email']) ? (string) $data['email'] : null,
                locale: isset($data['locale']) && is_string($data['locale'])
                    ? LocaleResponse::tryFrom($data['locale'])
                    : null,
                metadata: $data['metadata'] ?? null,
                testmode: isset($data['testmode']) ? (bool) $data['testmode'] : null
            );

            $response = $this->sdk->customers->create(
                body: $body,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );

            if ($response->customerResponse === null) {
                throw new ApiException('Empty customer creation response from Mollie API.', $response->statusCode);
            }

            return Customer::fromResponse($response->customerResponse, $this->mandatesEndpoint);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
