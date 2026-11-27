<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\Payment;

use Mollie\WooCommerce\SDK\ApiException;

class PaymentFactory
{
    /** @var callable */
    private $molliePaymentFactory;

    /**
     * PaymentFactory constructor.
     */
    public function __construct(callable $molliePaymentFactory)
    {
        $this->molliePaymentFactory = $molliePaymentFactory;
    }

    /**
     * @param mixed $data
     * @return bool|MolliePayment
     * @throws ApiException
     */
    public function getPaymentObject($data)
    {
        if (
            (!is_object($data) && $data === 'payment')
            || (is_string($data) && strpos($data, 'tr_') !== false)
            || (is_object($data) && ($data->resource ?? null) === 'payment')
        ) {
            $molliePayment = ($this->molliePaymentFactory)();
            $molliePayment->setPayment($data);
            return $molliePayment;
        }

        return false;
    }
}
