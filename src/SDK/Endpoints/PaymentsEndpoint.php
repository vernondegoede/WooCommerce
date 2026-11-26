<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\PaymentRequest;
use Mollie\Api\Models\Components\RefundRequest;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Api\Models\Operations\UpdatePaymentRequestBody;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\IdempotencyKey;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use Mollie\WooCommerce\SDK\Resources\Payment;
use Mollie\WooCommerce\SDK\Resources\Refund;
use Throwable;

class PaymentsEndpoint
{
    public function __construct(private Client $sdk)
    {
    }

    /**
     * @param array<string, mixed>|PaymentRequest $data
     * @param array<string, mixed> $filters
     * @throws ApiException
     */
    public function create(array|PaymentRequest $data, array $filters = [], ?string $idempotencyKey = null): Payment
    {
        try {
            $body = $data instanceof PaymentRequest
                ? $data
                : $this->deserializePaymentRequest($data);
            $include = isset($filters['include']) && is_string($filters['include']) ? $filters['include'] : null;

            $response = $this->sdk->payments->create(
                body: $body,
                include: $include,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );

            if ($response->paymentResponse === null) {
                throw new ApiException('Empty payment response from Mollie API.', $response->statusCode);
            }

            return Payment::fromResponse($response->paymentResponse, $this->sdk);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @throws ApiException
     */
    public function get(string $paymentId, array $parameters = []): Payment
    {
        try {
            $request = new GetPaymentRequest(
                paymentId: $paymentId,
                testmode: isset($parameters['testmode']) ? (bool) $parameters['testmode'] : null,
                include: isset($parameters['include']) && is_string($parameters['include']) ? $parameters['include'] : null,
                embed: isset($parameters['embed']) && is_string($parameters['embed']) ? $parameters['embed'] : null
            );

            $response = $this->sdk->payments->get($request);
            if ($response->paymentResponse === null) {
                throw new ApiException('Empty payment response from Mollie API.', $response->statusCode);
            }

            return Payment::fromResponse($response->paymentResponse, $this->sdk);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @throws ApiException
     */
    public function cancel(string $paymentId, ?string $idempotencyKey = null): Payment
    {
        try {
            $response = $this->sdk->payments->cancel(
                paymentId: $paymentId,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );

            if ($response->paymentResponse === null) {
                throw new ApiException('Empty payment cancel response from Mollie API.', $response->statusCode);
            }

            return Payment::fromResponse($response->paymentResponse, $this->sdk);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param array<string, mixed>|UpdatePaymentRequestBody $data
     * @throws ApiException
     */
    public function update(string $paymentId, array|UpdatePaymentRequestBody $data, ?string $idempotencyKey = null): Payment
    {
        try {
            $body = $data instanceof UpdatePaymentRequestBody
                ? $data
                : Normalizer::serializer()->deserialize(
                    json_encode($data, JSON_THROW_ON_ERROR),
                    UpdatePaymentRequestBody::class,
                    'json'
                );
            $response = $this->sdk->payments->update(
                paymentId: $paymentId,
                body: $body,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );

            if ($response->paymentResponse === null) {
                throw new ApiException('Empty payment update response from Mollie API.', $response->statusCode);
            }

            return Payment::fromResponse($response->paymentResponse, $this->sdk);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param Payment|string $payment
     * @param array<string, mixed> $data
     * @throws ApiException
     */
    public function refund(Payment|string $payment, array $data, ?string $idempotencyKey = null): Refund
    {
        $paymentId = $payment instanceof Payment ? $payment->id : $payment;

        try {
            $amountData = $data['amount'] ?? [];
            $amount = new Amount(
                currency: (string) ($amountData['currency'] ?? ''),
                value: (string) ($amountData['value'] ?? '')
            );
            $description = isset($data['description']) ? (string) $data['description'] : null;
            $metadata = $data['metadata'] ?? null;

            $refundRequest = new RefundRequest(
                amount: $amount,
                description: $description,
                metadata: $metadata
            );

            $response = $this->sdk->refunds->create(
                paymentId: $paymentId,
                body: $refundRequest,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );

            if ($response->refundResponse === null) {
                throw new ApiException('Empty refund response from Mollie API.', $response->statusCode);
            }

            return Refund::fromResponse($response->refundResponse);
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @throws ApiException
     */
    public function releaseAuthorization(string $paymentId, ?string $idempotencyKey = null): void
    {
        try {
            $this->sdk->payments->releaseAuthorization(
                paymentId: $paymentId,
                idempotencyKey: IdempotencyKey::resolve($idempotencyKey)
            );
        } catch (Throwable $throwable) {
            throw ApiException::fromSdkThrowable($throwable);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserializePaymentRequest(array $data): PaymentRequest
    {
        return Normalizer::serializer()->deserialize(
            json_encode($data, JSON_THROW_ON_ERROR),
            PaymentRequest::class,
            'json'
        );
    }
}
