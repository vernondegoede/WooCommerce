<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Profile;
use Throwable;

class ProfilesEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * @throws ApiException
     */
    public function getCurrent(): Profile
    {
        try {
            $response = $this->sdk->profiles->getCurrent();
            if ($response->profileResponse === null) {
                throw new ApiException('Empty profile response from Mollie API.', $response->statusCode);
            }

            return Profile::fromResponse($response->profileResponse);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
