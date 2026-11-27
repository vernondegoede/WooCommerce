<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use Mollie\Api\Models\Components\ListMandateResponse;
use Mollie\Api\Models\Components\MandateResponse;

class Mandate
{
    public string $resource = 'mandate';
    public string $id = '';
    public string $mode = 'live';
    public string $method = '';
    public string $status = '';
    public string $customerId = '';
    public string $createdAt = '';
    public ?string $signatureDate = null;
    public ?string $mandateReference = null;
    public ?object $details = null;

    /** @var MandateResponse|ListMandateResponse|null */
    private $raw = null;

    /**
     * @param MandateResponse|ListMandateResponse $response
     */
    public static function fromResponse(MandateResponse|ListMandateResponse $response): self
    {
        $mandate = new self();
        $mandate->raw = $response;
        $mandate->resource = $response->resource;
        $mandate->id = $response->id;
        $mandate->mode = $response->mode->value;
        $mandate->method = $response->method->value;
        $mandate->status = $response->status->value;
        $mandate->customerId = $response->customerId;
        $mandate->createdAt = $response->createdAt;
        $mandate->signatureDate = $response->signatureDate;
        $mandate->mandateReference = $response->mandateReference;
        $mandate->details = Normalizer::toPlainObject($response->details);

        return $mandate;
    }

    /**
     * @return MandateResponse|ListMandateResponse|null
     */
    public function raw()
    {
        return $this->raw;
    }

    public function isValid(): bool
    {
        return $this->status === 'valid';
    }
}
