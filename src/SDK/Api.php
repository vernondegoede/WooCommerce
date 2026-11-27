<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Security;
use Mollie\Api\Utils\Retry\RetryConfig;
use Mollie\Api\Utils\Retry\RetryConfigBackoff;
use Mollie\WooCommerce\Log\EventLog;

class Api
{
    public const DEFAULT_API_ENDPOINT = 'https://api.mollie.com';

    /**
     * Cached SDK clients keyed by hashed API key + endpoint so switching between test/live keys
     * or multisite blogs never leaks a client built for another credential.
     *
     * @var array<string, MollieClient>
     */
    private array $clients = [];

    /**
     * @var string
     */
    protected $pluginVersion;

    /**
     * @var string
     */
    protected $pluginId;

    private ?EventLog $eventLog;

    public function __construct(string $pluginVersion, string $pluginId, ?EventLog $eventLog = null)
    {
        $this->pluginVersion = $pluginVersion;
        $this->pluginId = $pluginId;
        $this->eventLog = $eventLog;
    }

    /**
     * Clear cached MollieClient instances.
     */
    public function resetClients(): void
    {
        $this->clients = [];
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

        $endpoint = (string) $this->getApiEndpoint();
        $cacheKey = hash('sha256', $apiKey . "\0" . $endpoint);

        if (!isset($this->clients[$cacheKey]) || $needToUpdateApiKey) {
            $userAgent = implode(' ', [
                (new WordPressHttpAdapter())->versionString(),
                'WooCommerce/' . get_option('woocommerce_version', 'Unknown'),
                'WooCommerceSubscriptions/' . get_option('woocommerce_subscriptions_active_version', 'Unknown'),
                'MollieWoo/' . $this->pluginVersion,
            ]);

            $observer = $this->eventLog !== null
                ? function (array $telemetry): void {
                    $this->eventLog?->step('mollie.http', $telemetry);
                }
                : null;

            $builder = Client::builder()
                ->setClient(WordPressHttpAdapter::createGuzzleClient($observer))
                ->setSecurity(new Security(apiKey: $apiKey))
                ->setServerUrl($endpoint)
                ->setCustomUserAgent($userAgent)
                ->setRetryConfig($this->retryConfig());

            $sdk = $builder->build();
            // mollie/php emits SDKConfiguration::$userAgent as the User-Agent header
            // and does not yet wire globals['parameters']['header']['customUserAgent'] into requests.
            $sdk->sdkConfiguration->userAgent = trim($userAgent . ' ' . $sdk->sdkConfiguration->userAgent);

            $this->clients[$cacheKey] = new MollieClient($sdk);
        }

        return $this->clients[$cacheKey];
    }

    /**
     * Build the default exponential-backoff retry configuration for transient Mollie failures
     * (429, 5xx, and connection errors), tuned for WordPress checkout request budgets.
     */
    protected function retryConfig(): RetryConfig
    {
        $default = new RetryConfigBackoff(
            initialIntervalMs: 500,
            maxIntervalMs: 2500,
            exponent: 2,
            maxElapsedTimeMs: 5000,
            retryConnectionErrors: true
        );

        $filtered = apply_filters($this->pluginId . '_sdk_retry_config', $default);

        return $filtered instanceof RetryConfig ? $filtered : $default;
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
