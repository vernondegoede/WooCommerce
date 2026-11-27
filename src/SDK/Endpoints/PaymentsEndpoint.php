<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Endpoints;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\Locale;
use Mollie\Api\Models\Components\MethodEnum;
use Mollie\Api\Models\Components\PaymentRequest;
use Mollie\Api\Models\Components\RefundRequest;
use Mollie\Api\Models\Operations\CancelPaymentRequest;
use Mollie\Api\Models\Operations\CreatePaymentRequest;
use Mollie\Api\Models\Operations\CreateRefundRequest;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Api\Models\Operations\ReleaseAuthorizationRequest;
use Mollie\Api\Models\Operations\UpdatePaymentRequest;
use Mollie\Api\Models\Operations\UpdatePaymentRequestBody;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use Mollie\WooCommerce\SDK\Resources\Payment;
use Mollie\WooCommerce\SDK\Resources\Refund;
use Throwable;

class PaymentsEndpoint
{
    /**
     * Legacy Orders-API Klarna method IDs mapped to the Payments API unified Klarna method.
     */
    private const LEGACY_METHOD_MAP = [
        'klarnapaylater' => 'klarna',
        'klarnapaynow' => 'klarna',
        'klarnasliceit' => 'klarna',
    ];

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
                new CreatePaymentRequest(
                    idempotencyKey: $idempotencyKey,
                    body: $body,
                    include: $include
                )
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
                new CancelPaymentRequest(
                    paymentId: $paymentId,
                    idempotencyKey: $idempotencyKey
                )
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
            if ($data instanceof UpdatePaymentRequestBody) {
                $body = $data;
            } else {
                $data = $this->sanitizeLocale($data);
                $body = Normalizer::serializer()->deserialize(
                    json_encode($data, JSON_THROW_ON_ERROR),
                    UpdatePaymentRequestBody::class,
                    'json'
                );
            }
            $response = $this->sdk->payments->update(
                new UpdatePaymentRequest(
                    paymentId: $paymentId,
                    idempotencyKey: $idempotencyKey,
                    body: $body
                )
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
                new CreateRefundRequest(
                    paymentId: $paymentId,
                    idempotencyKey: $idempotencyKey,
                    body: $refundRequest
                )
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
                new ReleaseAuthorizationRequest(
                    paymentId: $paymentId,
                    idempotencyKey: $idempotencyKey
                )
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
        $data = $this->sanitizeLocale($data);
        $data = $this->promoteBillingEmail($data);

        foreach (['sequenceType', 'captureMode'] as $enumField) {
            if (isset($data[$enumField]) && $data[$enumField] === '') {
                unset($data[$enumField]);
            }
        }

        // Work around Speakeasy UnionHandler failing on array<MethodEnum> deserialization
        // and map legacy Klarna Orders-API method IDs to the unified Payments API 'klarna' method.
        $methodArray = null;
        if (isset($data['method'])) {
            if (is_string($data['method'])) {
                $normalized = self::LEGACY_METHOD_MAP[$data['method']] ?? $data['method'];
                if ($normalized === '' || MethodEnum::tryFrom($normalized) === null) {
                    unset($data['method']);
                } else {
                    $data['method'] = $normalized;
                }
            } elseif (is_array($data['method'])) {
                $values = [];
                foreach ($data['method'] as $item) {
                    if ($item instanceof MethodEnum) {
                        $values[] = $item->value;
                        continue;
                    }
                    if (!is_string($item)) {
                        continue;
                    }
                    $normalized = self::LEGACY_METHOD_MAP[$item] ?? $item;
                    $enum = MethodEnum::tryFrom($normalized);
                    if ($enum !== null) {
                        $values[] = $enum->value;
                    }
                }
                unset($data['method']);
                if ($values !== []) {
                    $methodArray = $values;
                }
            }
        }

        /** @var PaymentRequest $request */
        $request = Normalizer::serializer()->deserialize(
            json_encode($data, JSON_THROW_ON_ERROR),
            PaymentRequest::class,
            'json'
        );

        if ($methodArray !== null) {
            // Assign string backing values because Speakeasy's UnionHandler serializes
            // array<MethodEnum> items as {name, value} objects instead of enum strings.
            /** @phpstan-ignore-next-line */
            $request->method = $methodArray;
        }

        return $request;
    }

    /**
     * Drop unrecognized/empty locales so stores using WordPress locales outside Mollie's
     * Locale enum fall back cleanly instead of throwing during deserialization.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeLocale(array $data): array
    {
        if (!array_key_exists('locale', $data)) {
            return $data;
        }

        if (!is_string($data['locale']) || $data['locale'] === '' || Locale::tryFrom($data['locale']) === null) {
            unset($data['locale']);
        }

        return $data;
    }

    /**
     * Promote legacy top-level billingEmail (e.g. from custom filters or banktransfer payloads)
     * into billingAddress.email so it is preserved on PaymentRequest.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function promoteBillingEmail(array $data): array
    {
        if (!isset($data['billingEmail']) || !is_string($data['billingEmail']) || trim($data['billingEmail']) === '') {
            unset($data['billingEmail']);
            return $data;
        }

        $email = $data['billingEmail'];
        unset($data['billingEmail']);

        if (!isset($data['billingAddress'])) {
            $data['billingAddress'] = ['email' => $email];
        } elseif (is_object($data['billingAddress'])) {
            $address = clone $data['billingAddress'];
            if (empty($address->email)) {
                $address->email = $email;
            }
            $data['billingAddress'] = $address;
        } elseif (is_array($data['billingAddress']) && empty($data['billingAddress']['email'])) {
            $data['billingAddress']['email'] = $email;
        }

        return $data;
    }
}
