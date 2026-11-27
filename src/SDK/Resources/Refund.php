<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use Mollie\Api\Models\Components\RefundResponse;

class Refund
{
    public string $resource = 'refund';
    public string $id = '';
    public string $mode = 'live';
    public string $description = '';
    public ?object $amount = null;
    public string $paymentId = '';
    public string $status = '';
    public string $createdAt = '';
    /** @var mixed */
    public $metadata = null;
    public ?object $_links = null;

    private ?RefundResponse $raw = null;

    public static function fromResponse(RefundResponse $response): self
    {
        $refund = new self();
        $refund->raw = $response;
        $refund->resource = $response->resource;
        $refund->id = $response->id;
        $refund->mode = $response->mode->value;
        $refund->description = $response->description;
        $refund->amount = Normalizer::toPlainObject($response->amount);
        $refund->paymentId = $response->paymentId;
        $refund->status = $response->status->value;
        $refund->createdAt = $response->createdAt;
        $refund->metadata = is_array($response->metadata)
            ? json_decode((string) json_encode($response->metadata), false)
            : $response->metadata;
        $refund->_links = Normalizer::toPlainObject($response->links);

        return $refund;
    }

    public function raw(): ?RefundResponse
    {
        return $this->raw;
    }
}
