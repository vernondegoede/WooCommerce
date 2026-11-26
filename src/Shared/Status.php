<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\Shared;

use Mollie\Api\Client;
use Mollie\WooCommerce\SDK\ApiException;
use WooCommerce;

class Status
{
    /**
     * Minimal required WooCommerce version
     *
     * @var string
     */
    public const MIN_WOOCOMMERCE_VERSION = '3.0';

    /**
     * Minimal required PHP version
     *
     * @var string
     */
    public const MIN_PHP_VERSION = '8.2';

    /**
     * @var string[]
     */
    protected $errors = [];

    protected string $pluginTitle;

    public function __construct(string $pluginTitle = '')
    {
        $this->pluginTitle = $pluginTitle;
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Check if this plugin is compatible
     *
     * @return bool
     */
    public function isCompatible()
    {
        static $isCompatible = null;

        if ($isCompatible !== null) {
            return $isCompatible;
        }

        $isCompatible = true;

        if (!$this->hasCompatibleWooCommerceVersion()) {
            $this->errors[] = sprintf(
                /* translators: Placeholder 1: Plugin title. Placeholder 2: Min WooCommerce version. Placeholder 3: WooCommerce version used. */
                __(
                    'The %1$s plugin requires at least WooCommerce version %2$s, you are using version %3$s. Please update your WooCommerce plugin.',
                    'mollie-payments-for-woocommerce'
                ),
                $this->pluginTitle,
                self::MIN_WOOCOMMERCE_VERSION,
                $this->getWooCommerceVersion()
            );

            return $isCompatible = false;
        }

        if (!$this->isApiClientInstalled()) {
            $this->errors[] = __(
                'Mollie API client not installed. Please make sure the plugin is installed correctly.',
                'mollie-payments-for-woocommerce'
            );

            return $isCompatible = false;
        }

        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->errors[] = sprintf(
                /* translators: Placeholder 1: Min PHP version. Placeholder 2: PHP version used. Placeholder 3: Opening link tag. placeholder 4: Closing link tag. */
                __(
                    'Mollie Payments for WooCommerce require PHP %1$s or higher, you have PHP %2$s. Please upgrade and view %3$sthis FAQ%4$s',
                    'mollie-payments-for-woocommerce'
                ),
                self::MIN_PHP_VERSION,
                PHP_VERSION,
                '<a href="https://github.com/mollie/WooCommerce/wiki/PHP-&-Mollie-API-v2" target="_blank">',
                '</a>'
            );

            return $isCompatible = false;
        }

        if (function_exists('extension_loaded') && !extension_loaded('json')) {
            $this->errors[] = __(
                'Mollie Payments for WooCommerce requires the JSON extension for PHP. Enable it in your server or ask your webhoster to enable it for you.',
                'mollie-payments-for-woocommerce'
            );

            return $isCompatible = false;
        }

        return $isCompatible;
    }

    /**
     * @return string
     */
    public function getWooCommerceVersion()
    {
        return WooCommerce::instance()->version;
    }

    /**
     * @return bool
     */
    public function hasCompatibleWooCommerceVersion()
    {
        return version_compare($this->getWooCommerceVersion(), self::MIN_WOOCOMMERCE_VERSION, '>=');
    }

    /**
     * @return bool
     */
    protected function isApiClientInstalled()
    {
        return class_exists(Client::class);
    }

    /**
     * Re-throws the API failure with its HTTP status code intact, so callers can tell an
     * authentication problem from an outage or from a request that never left the server.
     * The message is left unescaped here; escaping belongs to whoever renders it.
     *
     * @param \Mollie\WooCommerce\SDK\MollieClient $apiClient
     * @throws ApiException
     */
    public function getMollieApiStatus($apiClient)
    {
        try {
            // Try to load Mollie methods
            $apiClient->methods->all();
        } catch (ApiException $apiException) {
            $code = (int) $apiException->getCode();
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output; escaped once where rendered
            if ($code === 401) {
                throw new ApiException(
                    __('incorrect API key or other authentication issue. Please check your API keys!', 'mollie-payments-for-woocommerce'),
                    $code,
                    null,
                    $apiException
                );
            }

            throw new ApiException(
                self::plainApiErrorMessage($apiException),
                $code,
                null,
                $apiException
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * The undecorated failure text, without the ISO-8601 prefix, documentation link and
     * request body that ApiException appends to getMessage().
     */
    public static function plainApiErrorMessage(ApiException $exception): string
    {
        $plain = $exception->getPlainMessage();
        if ($plain !== '') {
            return $plain;
        }

        return (string) preg_replace('/^(\[[\d\-T:+]+\]\s*)+/', '', $exception->getMessage());
    }
}
