<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use Mollie\Api\Client;
use Mollie\WooCommerce\SDK\Endpoints\CapturesEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\CustomersEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\MandatesEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\MethodsEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\PaymentsEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\ProfilesEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\SessionsEndpoint;
use Mollie\WooCommerce\SDK\Endpoints\WalletsEndpoint;

/**
 * Facade over the Speakeasy-generated Mollie\Api\Client providing plugin-level resource adapters
 * and unified ApiException handling.
 */
class MollieClient
{
    public PaymentsEndpoint $payments;
    public CapturesEndpoint $paymentCaptures;
    public MethodsEndpoint $methods;
    public CustomersEndpoint $customers;
    public MandatesEndpoint $mandates;
    public ProfilesEndpoint $profiles;
    public WalletsEndpoint $wallets;
    public SessionsEndpoint $sessions;

    private Client $sdk;

    public function __construct(Client $sdk)
    {
        $this->sdk = $sdk;
        $this->mandates = new MandatesEndpoint($sdk);
        $this->payments = new PaymentsEndpoint($sdk);
        $this->paymentCaptures = new CapturesEndpoint($sdk);
        $this->methods = new MethodsEndpoint($sdk);
        $this->customers = new CustomersEndpoint($sdk, $this->mandates);
        $this->profiles = new ProfilesEndpoint($sdk);
        $this->wallets = new WalletsEndpoint($sdk);
        $this->sessions = new SessionsEndpoint($sdk);
    }

    public function sdk(): Client
    {
        return $this->sdk;
    }
}
