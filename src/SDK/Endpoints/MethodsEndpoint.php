<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\LineCategories;
use Mollie\Api\Models\Components\Locale;
use Mollie\Api\Models\Components\MethodEnum;
use Mollie\Api\Models\Components\MethodIncludeWalletsParameter;
use Mollie\Api\Models\Components\MethodResourceParameter;
use Mollie\Api\Models\Components\SequenceType;
use Mollie\Api\Models\Operations\GetMethodRequest;
use Mollie\Api\Models\Operations\ListAllMethodsRequest;
use Mollie\Api\Models\Operations\ListMethodsRequest;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use stdClass;
use Throwable;

class MethodsEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * List enabled payment methods (GET /v2/methods).
     *
     * @param array<string, mixed> $parameters
     * @return array<int, stdClass>
     * @throws ApiException
     */
    public function all(array $parameters = []): array
    {
        return $this->allActive($parameters);
    }

    /**
     * List active payment methods for the current profile (GET /v2/methods).
     *
     * @param array<string, mixed> $parameters
     * @return array<int, stdClass>
     * @throws ApiException
     */
    public function allActive(array $parameters = []): array
    {
        try {
            $request = new ListMethodsRequest(
                sequenceType: isset($parameters['sequenceType']) && is_string($parameters['sequenceType'])
                    ? SequenceType::tryFrom($parameters['sequenceType'])
                    : null,
                amount: $this->toAmount($parameters['amount'] ?? null),
                resource: isset($parameters['resource']) && is_string($parameters['resource'])
                    ? MethodResourceParameter::tryFrom($parameters['resource'])
                    : null,
                billingCountry: isset($parameters['billingCountry']) && is_string($parameters['billingCountry'])
                    ? $parameters['billingCountry']
                    : null,
                includeWallets: isset($parameters['includeWallets']) && is_string($parameters['includeWallets'])
                    ? MethodIncludeWalletsParameter::tryFrom($parameters['includeWallets'])
                    : null,
                orderLineCategories: isset($parameters['orderLineCategories']) && is_string($parameters['orderLineCategories'])
                    ? LineCategories::tryFrom($parameters['orderLineCategories'])
                    : null,
                profileId: isset($parameters['profileId']) && is_string($parameters['profileId'])
                    ? $parameters['profileId']
                    : null,
                testmode: isset($parameters['testmode']) ? (bool) $parameters['testmode'] : null,
                locale: isset($parameters['locale']) && is_string($parameters['locale'])
                    ? Locale::tryFrom($parameters['locale'])
                    : null,
                include: isset($parameters['include']) && is_string($parameters['include'])
                    ? $parameters['include']
                    : null
            );

            $response = $this->sdk->methods->list($request);
            $methods = $response->object->embedded->methods ?? [];

            return $this->normalizeMethodsList($methods);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * List all payment methods Mollie offers (GET /v2/methods/all).
     *
     * @param array<string, mixed> $parameters
     * @return array<int, stdClass>
     * @throws ApiException
     */
    public function allAvailable(array $parameters = []): array
    {
        try {
            $request = new ListAllMethodsRequest(
                amount: $this->toAmount($parameters['amount'] ?? null),
                sequenceType: isset($parameters['sequenceType']) && is_string($parameters['sequenceType'])
                    ? SequenceType::tryFrom($parameters['sequenceType'])
                    : null,
                profileId: isset($parameters['profileId']) && is_string($parameters['profileId'])
                    ? $parameters['profileId']
                    : null,
                testmode: isset($parameters['testmode']) ? (bool) $parameters['testmode'] : null,
                locale: isset($parameters['locale']) && is_string($parameters['locale'])
                    ? Locale::tryFrom($parameters['locale'])
                    : null,
                include: isset($parameters['include']) && is_string($parameters['include'])
                    ? $parameters['include']
                    : null
            );

            $response = $this->sdk->methods->all($request);
            $methods = $response->object->embedded->methods ?? [];

            return $this->normalizeMethodsList($methods);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * Get a single payment method (GET /v2/methods/{methodId}).
     *
     * @param array<string, mixed> $parameters
     * @return stdClass
     * @throws ApiException
     */
    public function get(string $methodId, array $parameters = []): stdClass
    {
        try {
            $methodEnum = MethodEnum::tryFrom($methodId);
            if ($methodEnum === null) {
                throw new ApiException(sprintf('Unknown payment method ID "%s".', $methodId), 404);
            }

            $request = new GetMethodRequest(
                methodId: $methodEnum,
                currency: isset($parameters['currency']) && is_string($parameters['currency'])
                    ? $parameters['currency']
                    : null,
                profileId: isset($parameters['profileId']) && is_string($parameters['profileId'])
                    ? $parameters['profileId']
                    : null,
                sequenceType: isset($parameters['sequenceType']) && is_string($parameters['sequenceType'])
                    ? SequenceType::tryFrom($parameters['sequenceType'])
                    : null,
                testmode: isset($parameters['testmode']) ? (bool) $parameters['testmode'] : null,
                locale: isset($parameters['locale']) && is_string($parameters['locale'])
                    ? Locale::tryFrom($parameters['locale'])
                    : null,
                include: isset($parameters['include']) && is_string($parameters['include'])
                    ? $parameters['include']
                    : null
            );

            $response = $this->sdk->methods->get($request);
            if ($response->entityMethodGet === null) {
                throw new ApiException('Empty method response from Mollie API.', $response->statusCode);
            }

            $plain = Normalizer::toPlainObject($response->entityMethodGet);

            return $plain instanceof stdClass ? $plain : new stdClass();
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param mixed $amount
     */
    private function toAmount($amount): ?Amount
    {
        if (is_array($amount) && isset($amount['currency'], $amount['value'])) {
            return new Amount(
                currency: (string) $amount['currency'],
                value: (string) $amount['value']
            );
        }

        return null;
    }

    /**
     * @param array<int, object> $methods
     * @return array<int, stdClass>
     */
    private function normalizeMethodsList(array $methods): array
    {
        $result = [];
        foreach ($methods as $method) {
            $plain = Normalizer::toPlainObject($method);
            if ($plain instanceof stdClass) {
                $result[] = $plain;
            }
        }

        return $result;
    }
}
