<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\Payment\Request\Middleware;

use Mollie\WooCommerce\Payment\LineItems\LineItemProvider;
use WC_Order;

/**
 * Class OrderLinesMiddleware
 *
 * Middleware to handle line items in the payment request.
 *
 * @package Mollie\WooCommerce\Payment\Request\Middleware
 */
class OrderLinesMiddleware implements RequestMiddlewareInterface
{
    private LineItemProvider $paymentLines;

    public function __construct(LineItemProvider $paymentLines)
    {
        $this->paymentLines = $paymentLines;
    }

    /**
     * Invoke the middleware.
     *
     * @param array $requestData The request data.
     * @param WC_Order $order The WooCommerce order object.
     * @param string $context The context of the request.
     * @param callable $next The next middleware to call.
     * @return array The modified request data.
     */
    public function __invoke(array $requestData, WC_Order $order, $context, $next): array
    {
        $methodId = $requestData['method'] ?? '';
        $optionName = 'mollie_wc_gateway_' . $methodId . '_settings';
        $hideOrderLines = (get_option($optionName, false)['hide_order_lines'] ?? '') === 'yes';
        if ($hideOrderLines) {
            /**
             * Merchant has configured to hide order lines via payment method settings.
             */
            return $next($requestData, $order, $context);
        }

        $requestData['lines'] = $this->paymentLines->order_lines($order);

        return $next($requestData, $order, $context);
    }
}
