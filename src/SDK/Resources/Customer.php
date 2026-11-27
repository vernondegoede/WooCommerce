<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use Mollie\Api\Models\Components\CustomerResponse;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Endpoints\MandatesEndpoint;

class Customer
{
    public string $resource = 'customer';
    public string $id = '';
    public string $mode = 'live';
    public ?string $name = null;
    public ?string $email = null;
    public ?string $locale = null;
    /** @var mixed */
    public $metadata = null;
    public string $createdAt = '';

    private ?CustomerResponse $raw = null;
    private ?MandatesEndpoint $mandatesEndpoint = null;

    public static function fromResponse(CustomerResponse $response, ?MandatesEndpoint $mandatesEndpoint = null): self
    {
        $customer = new self();
        $customer->raw = $response;
        $customer->mandatesEndpoint = $mandatesEndpoint;
        $customer->resource = $response->resource;
        $customer->id = $response->id;
        $customer->mode = $response->mode->value;
        $customer->name = $response->name;
        $customer->email = $response->email;
        $customer->locale = $response->locale?->value;
        $customer->metadata = is_array($response->metadata)
            ? json_decode((string) json_encode($response->metadata), false)
            : $response->metadata;
        $customer->createdAt = $response->createdAt;

        return $customer;
    }

    public function raw(): ?CustomerResponse
    {
        return $this->raw;
    }

    /**
     * @return array<int, Mandate>
     * @throws ApiException
     */
    public function mandates(): array
    {
        if ($this->mandatesEndpoint === null) {
            return [];
        }

        return $this->mandatesEndpoint->listFor($this->id);
    }

    /**
     * @throws ApiException
     */
    public function getMandate(string $mandateId): Mandate
    {
        if ($this->mandatesEndpoint === null) {
            throw new ApiException('Mandates endpoint is not configured for this customer.');
        }

        return $this->mandatesEndpoint->get($this->id, $mandateId);
    }

    /**
     * @throws ApiException
     */
    public function hasValidMandateForMethod(string $method): bool
    {
        foreach ($this->mandates() as $mandate) {
            if ($mandate->method === $method && ($mandate->status === 'valid' || $mandate->status === 'pending')) {
                return true;
            }
        }

        return false;
    }
}
