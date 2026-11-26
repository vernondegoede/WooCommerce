<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\PaymentMethods\PaymentRedirectStrategies;

use Mollie\WooCommerce\PaymentMethods\PaymentMethodI;
use Mollie\WooCommerce\SDK\Resources\Payment;
use WC_Order;

class DefaultRedirectStrategy implements PaymentRedirectStrategyI
{
    /**
     * Redirect location after successfully completing process_payment
     *
     * @param WC_Order $order
     * @param Payment $paymentObject
     *
     */
    public function execute(PaymentMethodI $paymentMethod, $order, $paymentObject, string $redirectUrl)
    {
        return $paymentObject->getCheckoutUrl();
    }
}
