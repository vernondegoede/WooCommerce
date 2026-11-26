<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Operations\RequestApplePayPaymentSessionRequestBody;
use Mollie\WooCommerce\SDK\ApiException;
use Throwable;

class WalletsEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * @throws ApiException
     */
    public function requestApplePayPaymentSession(string $domain, string $validationUrl): string
    {
        try {
            $response = $this->sdk->wallets->requestApplePaySession(
                body: new RequestApplePayPaymentSessionRequestBody(
                    validationUrl: $validationUrl,
                    domain: $domain
                )
            );

            if ($response->entitySession2 !== null) {
                return json_encode($response->entitySession2, JSON_THROW_ON_ERROR);
            }

            return (string) $response->rawResponse->getBody();
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
