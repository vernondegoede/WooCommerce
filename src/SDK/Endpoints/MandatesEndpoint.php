<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\MandateMethod;
use Mollie\Api\Models\Components\MandateRequest;
use Mollie\Api\Models\Operations\CreateMandateRequest;
use Mollie\Api\Models\Operations\GetMandateRequest;
use Mollie\Api\Models\Operations\ListMandatesRequest;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Customer;
use Mollie\WooCommerce\SDK\Resources\Mandate;
use Throwable;

class MandatesEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * @param Customer|string $customer
     * @param array<string, mixed> $data
     * @throws ApiException
     */
    public function createFor(Customer|string $customer, array $data, ?string $idempotencyKey = null): Mandate
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        try {
            $method = MandateMethod::tryFrom((string) ($data['method'] ?? ''));
            if ($method === null) {
                throw new ApiException(sprintf('Unsupported mandate method "%s".', (string) ($data['method'] ?? '')), 422);
            }

            $body = new MandateRequest(
                method: $method,
                consumerName: (string) ($data['consumerName'] ?? ''),
                consumerAccount: isset($data['consumerAccount']) ? (string) $data['consumerAccount'] : null,
                consumerBic: isset($data['consumerBic']) ? (string) $data['consumerBic'] : null,
                consumerEmail: isset($data['consumerEmail']) ? (string) $data['consumerEmail'] : null,
                signatureDate: isset($data['signatureDate']) ? (string) $data['signatureDate'] : null,
                mandateReference: isset($data['mandateReference']) ? (string) $data['mandateReference'] : null,
                paypalBillingAgreementId: isset($data['paypalBillingAgreementId']) ? (string) $data['paypalBillingAgreementId'] : null,
                payPalVaultId: isset($data['payPalVaultId']) ? (string) $data['payPalVaultId'] : null,
                testmode: isset($data['testmode']) ? (bool) $data['testmode'] : null
            );

            $response = $this->sdk->mandates->create(
                new CreateMandateRequest(
                    customerId: $customerId,
                    idempotencyKey: $idempotencyKey,
                    body: $body
                )
            );

            if ($response->mandateResponse === null) {
                throw new ApiException('Empty mandate response from Mollie API.', $response->statusCode);
            }

            return Mandate::fromResponse($response->mandateResponse);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @throws ApiException
     */
    public function get(string $customerId, string $mandateId): Mandate
    {
        try {
            $response = $this->sdk->mandates->get(
                new GetMandateRequest(
                    customerId: $customerId,
                    mandateId: $mandateId
                )
            );

            if ($response->mandateResponse === null) {
                throw new ApiException('Empty mandate response from Mollie API.', $response->statusCode);
            }

            return Mandate::fromResponse($response->mandateResponse);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @return array<int, Mandate>
     * @throws ApiException
     */
    public function listFor(string $customerId): array
    {
        try {
            $mandates = [];
            $pages = $this->sdk->mandates->list(new ListMandatesRequest(customerId: $customerId));
            foreach ($pages as $page) {
                $items = $page->object->embedded->mandates ?? [];
                foreach ($items as $item) {
                    $mandates[] = Mandate::fromResponse($item);
                }
            }

            return $mandates;
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
