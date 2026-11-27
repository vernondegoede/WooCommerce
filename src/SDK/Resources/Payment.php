<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Operations\ListChargebacksRequest;
use Mollie\Api\Models\Operations\ListRefundsRequest;
use Mollie\WooCommerce\SDK\ApiException;
use stdClass;
use Throwable;

/**
 * Adapter around Mollie\Api\Models\Components\PaymentResponse exposing convenience helpers
 * and scalar/stdClass properties expected across the WooCommerce plugin.
 */
class Payment
{
    public string $resource = 'payment';
    public string $id = '';
    public string $mode = 'live';
    public string $status = '';
    public ?string $method = null;
    public string $description = '';
    public ?object $amount = null;
    public ?object $amountRefunded = null;
    public ?object $amountRemaining = null;
    public ?object $amountCaptured = null;
    public ?object $amountChargedBack = null;
    public ?string $sequenceType = null;
    public ?string $customerId = null;
    public ?string $mandateId = null;
    public ?string $subscriptionId = null;
    public string $profileId = '';
    public ?bool $isCancelable = null;
    public ?string $redirectUrl = null;
    public ?string $cancelUrl = null;
    public ?string $webhookUrl = null;
    public ?string $captureMode = null;
    public string $createdAt = '';
    public ?string $expiresAt = null;
    /** @var mixed */
    public $metadata = null;
    public ?object $details = null;
    public ?object $billingAddress = null;
    public ?object $shippingAddress = null;
    /** @var array<int, object>|null */
    public ?array $lines = null;
    public ?object $_links = null;

    private ?PaymentResponse $raw = null;
    private ?Client $sdk = null;

    public static function fromResponse(PaymentResponse $response, ?Client $sdk = null): self
    {
        $payment = new self();
        $payment->raw = $response;
        $payment->sdk = $sdk;
        $payment->resource = $response->resource;
        $payment->id = $response->id;
        $payment->mode = $response->mode->value;
        $payment->status = $response->status->value;
        $payment->method = $response->method?->value;
        $payment->description = $response->description;
        $payment->amount = Normalizer::toPlainObject($response->amount);
        $payment->amountRefunded = Normalizer::toPlainObject($response->amountRefunded);
        $payment->amountRemaining = Normalizer::toPlainObject($response->amountRemaining);
        $payment->amountCaptured = Normalizer::toPlainObject($response->amountCaptured);
        $payment->amountChargedBack = Normalizer::toPlainObject($response->amountChargedBack);
        $payment->sequenceType = $response->sequenceType->value;
        $payment->customerId = $response->customerId;
        $payment->mandateId = $response->mandateId;
        $payment->subscriptionId = $response->subscriptionId;
        $payment->profileId = $response->profileId;
        $payment->isCancelable = $response->isCancelable;
        $payment->redirectUrl = $response->redirectUrl;
        $payment->cancelUrl = $response->cancelUrl;
        $payment->webhookUrl = $response->webhookUrl;
        $payment->captureMode = $response->captureMode?->value;
        $payment->createdAt = $response->createdAt;
        $payment->expiresAt = $response->expiresAt ?? null;
        $payment->metadata = is_array($response->metadata)
            ? json_decode((string) json_encode($response->metadata), false)
            : $response->metadata;
        $payment->details = Normalizer::toPlainObject($response->details);
        $payment->billingAddress = Normalizer::toPlainObject($response->billingAddress);
        $payment->shippingAddress = Normalizer::toPlainObject($response->shippingAddress);
        $payment->lines = $response->lines !== null ? Normalizer::toPlainObject($response->lines) : null;
        $payment->_links = Normalizer::toPlainObject($response->links);

        return $payment;
    }

    public function raw(): ?PaymentResponse
    {
        return $this->raw;
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentResponseStatus::Paid->value;
    }

    public function isAuthorized(): bool
    {
        return $this->status === PaymentResponseStatus::Authorized->value;
    }

    public function isCanceled(): bool
    {
        return $this->status === PaymentResponseStatus::Canceled->value;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentResponseStatus::Failed->value;
    }

    public function isExpired(): bool
    {
        return $this->status === PaymentResponseStatus::Expired->value;
    }

    public function isOpen(): bool
    {
        return $this->status === PaymentResponseStatus::Open->value;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentResponseStatus::Pending->value;
    }

    public function hasRefunds(): bool
    {
        return $this->getAmountRefunded() > 0.0;
    }

    public function hasChargebacks(): bool
    {
        if ($this->amountChargedBack === null || !isset($this->amountChargedBack->value)) {
            return false;
        }

        return (float) $this->amountChargedBack->value > 0.0;
    }

    public function getCheckoutUrl(): ?string
    {
        if (isset($this->_links->checkout->href) && is_string($this->_links->checkout->href)) {
            return $this->_links->checkout->href;
        }

        return null;
    }

    public function getAmountCaptured(): float
    {
        if ($this->amountCaptured !== null && isset($this->amountCaptured->value)) {
            return (float) $this->amountCaptured->value;
        }

        return 0.0;
    }

    public function getAmountRefunded(): float
    {
        if ($this->amountRefunded !== null && isset($this->amountRefunded->value)) {
            return (float) $this->amountRefunded->value;
        }

        return 0.0;
    }

    public function getAmountRemaining(): float
    {
        if ($this->amountRemaining !== null && isset($this->amountRemaining->value)) {
            return (float) $this->amountRemaining->value;
        }

        return 0.0;
    }

    public function hasSequenceTypeFirst(): bool
    {
        return $this->sequenceType === 'first';
    }

    public function hasSequenceTypeRecurring(): bool
    {
        return $this->sequenceType === 'recurring';
    }

    /**
     * List refunds associated with this payment.
     *
     * @return array<int, stdClass>
     * @throws ApiException
     */
    public function refunds(): array
    {
        if ($this->sdk === null || $this->id === '') {
            return [];
        }

        try {
            $result = [];
            $pages = $this->sdk->refunds->list(new ListRefundsRequest(paymentId: $this->id));
            foreach ($pages as $page) {
                $items = $page->object->embedded->refunds ?? [];
                foreach ($items as $item) {
                    $plain = Normalizer::toPlainObject($item);
                    if ($plain instanceof stdClass) {
                        $result[] = $plain;
                    }
                }
            }

            return $result;
        } catch (Throwable $throwable) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped inside ApiException::fromSdkThrowable().
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * List chargebacks associated with this payment.
     *
     * @return array<int, stdClass>
     * @throws ApiException
     */
    public function chargebacks(): array
    {
        if ($this->sdk === null || $this->id === '') {
            return [];
        }

        try {
            $result = [];
            $pages = $this->sdk->chargebacks->list(new ListChargebacksRequest(paymentId: $this->id));
            foreach ($pages as $page) {
                $items = $page->object->embedded->chargebacks ?? [];
                foreach ($items as $item) {
                    $plain = Normalizer::toPlainObject($item);
                    if ($plain instanceof stdClass) {
                        $result[] = $plain;
                    }
                }
            }

            return $result;
        } catch (Throwable $throwable) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped inside ApiException::fromSdkThrowable().
            throw ApiException::fromSdkThrowable($throwable);
        }
    }
}
