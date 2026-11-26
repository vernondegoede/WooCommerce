<?php

declare(strict_types=1);

namespace Mollie\WooCommerceTests\Functional\SDK;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\ErrorResponseDocumentation;
use Mollie\Api\Models\Components\ErrorResponseLinks;
use Mollie\Api\Models\Components\Mode;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseLinks;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Components\SequenceTypeResponse;
use Mollie\Api\Models\Components\Url;
use Mollie\Api\Models\Errors\APIException as SdkApiException;
use Mollie\Api\Models\Errors\ErrorResponse;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Resources\Payment;
use Mollie\WooCommerce\SDK\WordPressHttpAdapter;
use Mollie\WooCommerceTests\TestCase;

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;

class SdkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        when('esc_html')->alias(static function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        });
    }
    /**
     * WHEN WordPress HTTP transport fails with WP_Error
     * THEN WordPressHttpAdapter rejects the Guzzle promise with ConnectException
     * @test
     */
    public function wordPressHttpAdapterRejectsOnWpError(): void
    {
        $testee = new WordPressHttpAdapter();
        expect('wp_remote_request')->once()->andReturn(new \WP_Error('http_request_failed', 'Connection timed out'));
        expect('is_wp_error')->once()->andReturn(true);

        $request = new Request('POST', 'https://api.mollie.com/v2/payments', ['User-Agent' => 'test'], '{}');
        $promise = $testee($request);

        $this->expectException(ConnectException::class);
        $promise->wait();
    }

    /**
     * WHEN WordPress HTTP transport succeeds
     * THEN WordPressHttpAdapter fulfills the Guzzle promise with a PSR-7 Response
     * @test
     */
    public function wordPressHttpAdapterReturnsPsr7Response(): void
    {
        $wpResponse = [
            'response' => ['code' => 200],
            'headers' => ['content-type' => 'application/hal+json'],
            'body' => '{"resource":"payment","id":"tr_123"}',
        ];
        expect('wp_remote_request')->once()->andReturn($wpResponse);
        expect('is_wp_error')->once()->andReturn(false);
        expect('wp_remote_retrieve_response_code')->once()->with($wpResponse)->andReturn(200);
        expect('wp_remote_retrieve_headers')->once()->with($wpResponse)->andReturn(['content-type' => 'application/hal+json']);
        expect('wp_remote_retrieve_body')->once()->with($wpResponse)->andReturn('{"resource":"payment","id":"tr_123"}');

        $testee = new WordPressHttpAdapter();
        $request = new Request('GET', 'https://api.mollie.com/v2/payments/tr_123', ['User-Agent' => 'test']);
        $response = $testee($request)->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/hal+json', $response->getHeaderLine('content-type'));
        self::assertSame('{"resource":"payment","id":"tr_123"}', (string) $response->getBody());
    }

    /**
     * @test
     */
    public function apiExceptionNormalizesErrorResponseThrowable(): void
    {
        $errorResponse = new ErrorResponse(
            status: 422,
            title: 'Unprocessable Entity',
            detail: 'The phone number is invalid',
            links: new ErrorResponseLinks(documentation: new ErrorResponseDocumentation(href: 'https://docs.mollie.com/errors', type: 'text/html')),
            field: 'billingAddress.phone'
        );

        $exception = ApiException::fromSdkThrowable($errorResponse->toException());

        self::assertSame(422, $exception->getCode());
        self::assertSame('billingAddress.phone', $exception->getField());
        self::assertStringContainsString('The phone number is invalid', $exception->getPlainMessage());
        self::assertStringContainsString('https://docs.mollie.com/errors', $exception->getMessage());
    }

    /**
     * @test
     */
    public function apiExceptionNormalizesSdkApiException(): void
    {
        $sdkException = new SdkApiException('API error occurred', 503, '{"status":503,"title":"Service Unavailable","detail":"Try later"}', null);

        $exception = ApiException::fromSdkThrowable($sdkException);

        self::assertSame(503, $exception->getCode());
        self::assertStringContainsString('Try later', $exception->getPlainMessage());
    }

    /**
     * @test
     */
    public function paymentResourceWrapsPaymentResponse(): void
    {
        $response = new PaymentResponse(
            resource: 'payment',
            id: 'tr_abc123',
            mode: Mode::Test,
            description: 'Order #1',
            amount: new Amount(currency: 'EUR', value: '12.50'),
            sequenceType: SequenceTypeResponse::Oneoff,
            profileId: 'pfl_123',
            status: PaymentResponseStatus::Paid,
            createdAt: '2026-01-01T00:00:00+00:00',
            links: new PaymentResponseLinks(
                self: new Url(href: 'https://api.mollie.com/v2/payments/tr_abc123', type: 'application/hal+json'),
                dashboard: new Url(href: 'https://my.mollie.com/dashboard/payments/tr_abc123', type: 'text/html'),
                checkout: new Url(href: 'https://www.mollie.com/checkout/select-method/abc123', type: 'text/html')
            ),
            metadata: ['order_id' => 99]
        );

        $payment = Payment::fromResponse($response);

        self::assertSame('tr_abc123', $payment->id);
        self::assertSame('test', $payment->mode);
        self::assertSame('paid', $payment->status);
        self::assertTrue($payment->isPaid());
        self::assertFalse($payment->isAuthorized());
        self::assertSame('https://www.mollie.com/checkout/select-method/abc123', $payment->getCheckoutUrl());
        self::assertSame('12.50', $payment->amount->value);
        self::assertSame(99, $payment->metadata->order_id);
        self::assertSame($response, $payment->raw());
    }
}
