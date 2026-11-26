<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Security;

class Api
{
    public const DEFAULT_API_ENDPOINT = 'https://api.mollie.com';

    /**
     * @var MollieClient|null
     */
    protected static ?MollieClient $api_client = null;
    /**
     * @var string
     */
    protected $pluginVersion;
    /**
     * @var string
     */
    protected $pluginId;

    public function __construct(string $pluginVersion, string $pluginId)
    {
        $this->pluginVersion = $pluginVersion;
        $this->pluginId = $pluginId;
    }

    /**
     * @param string $apiKey
     * @param bool $needToUpdateApiKey If the apiKey was updated discard the old instance, and create a new one with the new key.
     *
     * @return MollieClient
     * @throws ApiException
     */
    public function getApiClient($apiKey, $needToUpdateApiKey = false)
    {
        if (has_filter('mollie_api_key_filter')) {
            $apiKey = apply_filters('mollie_api_key_filter', $apiKey);
        }

        if (empty($apiKey)) {
            throw new ApiException(esc_html__('No API key provided. Please set your Mollie API keys below.', 'mollie-payments-for-woocommerce'));
        } elseif (!preg_match('#^(live|test)_\w{30,}$#', $apiKey)) {
            throw new ApiException(
                sprintf(
                    esc_html__(
                        "Invalid API key(s). Get them on the %1\$sDevelopers page in the Mollie dashboard%2\$s. The API key(s) must start with 'live_' or 'test_', be at least 30 characters and must not contain any special characters.",
                        'mollie-payments-for-woocommerce'
                    ),
                    '<a href="https://my.mollie.com/dashboard/developers/api-access-tokens?utm_source=woocommerce&utm_medium=plugin&utm_campaign=partner" target="_blank">',
                    '</a>'
                )
            );
        }

        if (self::$api_client === null || $needToUpdateApiKey) {
            $userAgent = implode(' ', [
                (new WordPressHttpAdapter())->versionString(),
                'WooCommerce/' . get_option('woocommerce_version', 'Unknown'),
                'WooCommerceSubscriptions/' . get_option('woocommerce_subscriptions_active_version', 'Unknown'),
                'MollieWoo/' . $this->pluginVersion,
            ]);

            $sdk = Client::builder()
                ->setClient(WordPressHttpAdapter::createGuzzleClient())
                ->setSecurity(new Security(apiKey: $apiKey))
                ->setServerUrl($this->getApiEndpoint())
                ->setCustomUserAgent($userAgent)
                ->build();

            self::$api_client = new MollieClient($sdk);
        }

        return self::$api_client;
    }

    /**
     * Get API endpoint. Override using filter.
     * @return string
     */
    public function getApiEndpoint()
    {
        return apply_filters($this->pluginId . '_api_endpoint', Client::SERVERS[0]);
    }

    public function isMollieOutageException(ApiException $e): bool
    {
        //see https://status.mollie.com/
        $outageCode = [400, 500];

        return in_array($e->getCode(), $outageCode, true);
    }

    public function isMollieFraudException(ApiException $e): bool
    {
        $isFraudCode = $e->getCode() === 422;
        $isFraudMessage = strpos($e->getMessage(), 'The payment was declined due to suspected fraud') !== false;

        return $isFraudCode && $isFraudMessage;
    }

    public function isUnprocessablePhoneException(ApiException $e): bool
    {
        $isUnprocessablePhoneCode = $e->getCode() === 422;
        $isUnprocessablePhoneMessage = strpos($e->getMessage(), 'phone number is invalid') !== false;

        return $isUnprocessablePhoneCode && $isUnprocessablePhoneMessage;
    }

    public function isMollieException(ApiException $e): ?string
    {
        if ($this->isMollieOutageException($e)) {
            return 'outage';
        }

        if ($this->isMollieFraudException($e)) {
            return 'fraud_rejection';
        }

        if ($this->isUnprocessablePhoneException($e)) {
            return 'unprocessable_phone_number';
        }

        return null;
    }
}
