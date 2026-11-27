<?php

declare(strict_types=1);

namespace Mollie\WooCommerceTests\Functional\SDK;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\AmountNullable;
use Mollie\Api\Models\Components\ErrorResponseDocumentation;
use Mollie\Api\Models\Components\ErrorResponseLinks;
use Mollie\Api\Models\Components\Mode;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseLinks;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Components\Security;
use Mollie\Api\Models\Components\SequenceTypeResponse;
use Mollie\Api\Models\Components\Url;
use Mollie\Api\Models\Errors\APIException as SdkApiException;
use Mollie\Api\Models\Errors\ErrorResponse;
use Mollie\Api\Utils\Retry\RetryConfigBackoff;
use Mollie\Api\Utils\Retry\RetryConfigNone;
use Mollie\WooCommerce\SDK\Api;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\MollieClient;
use Mollie\WooCommerce\SDK\Resources\Normalizer;
use Mollie\WooCommerce\SDK\Resources\Payment;
use Mollie\WooCommerce\SDK\WordPressHttpAdapter;
use Mollie\WooCommerceTests\TestCase;
use stdClass;

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

    /**
     * @test
     */
    public function wordPressHttpAdapterNotifiesObserverWithoutLeakingCredentials(): void
    {
        $events = [];
        $adapter = new WordPressHttpAdapter(static function (array $telemetry) use (&$events): void {
            $events[] = $telemetry;
        });

        $wpResponse = [
            'response' => ['code' => 201],
            'headers' => ['content-type' => 'application/hal+json'],
            'body' => '{"id":"tr_obs"}',
        ];
        expect('wp_remote_request')->once()->andReturn($wpResponse);
        expect('is_wp_error')->once()->andReturn(false);
        expect('wp_remote_retrieve_response_code')->once()->with($wpResponse)->andReturn(201);
        expect('wp_remote_retrieve_headers')->once()->with($wpResponse)->andReturn(['content-type' => 'application/hal+json']);
        expect('wp_remote_retrieve_body')->once()->with($wpResponse)->andReturn('{"id":"tr_obs"}');

        $request = new Request(
            'POST',
            'https://api.mollie.com/v2/payments?include=details.qrCode',
            [
                'Authorization' => 'Bearer live_supersecretapikey123456789012345',
                'Idempotency-Key' => 'idem-123',
            ],
            '{}'
        );
        $adapter($request)->wait();

        self::assertCount(1, $events);
        self::assertSame('POST', $events[0]['method']);
        self::assertSame('/v2/payments', $events[0]['path']);
        self::assertSame(201, $events[0]['status']);
        self::assertSame('idem-123', $events[0]['idempotency_key']);
        self::assertIsInt($events[0]['ms']);
        self::assertStringNotContainsString('live_supersecret', (string) json_encode($events));
    }

    /**
     * @test
     */
    public function apiCachesClientsPerApiKeyAndWiresUserAgentAndRetryConfig(): void
    {
        when('has_filter')->justReturn(false);
        when('apply_filters')->alias(static function (string $hook, $value) {
            return $value;
        });
        when('get_option')->alias(static function (string $option, $default = '') {
            if ($option === 'woocommerce_version') {
                return '9.5.0';
            }
            if ($option === 'woocommerce_subscriptions_active_version') {
                return '6.8.0';
            }
            return $default;
        });

        $api = new Api('9.0.0-dev', 'mollie-payments-for-woocommerce');
        $testKey = 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $liveKey = 'live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $testClient1 = $api->getApiClient($testKey);
        $testClient2 = $api->getApiClient($testKey);
        $liveClient = $api->getApiClient($liveKey);

        self::assertSame($testClient1, $testClient2);
        self::assertNotSame($testClient1, $liveClient);

        $customUserAgent = (string) ($testClient1->sdk()->sdkConfiguration->globals['parameters']['header']['customUserAgent'] ?? '');
        self::assertStringContainsString('MollieWoo/9.0.0-dev', $customUserAgent);
        self::assertStringContainsString('WooCommerce/9.5.0', $customUserAgent);
        self::assertStringContainsString('WooCommerceSubscriptions/6.8.0', $customUserAgent);
        self::assertStringContainsString('mollie/php', $testClient1->sdk()->sdkConfiguration->userAgent);
        self::assertInstanceOf(RetryConfigBackoff::class, $testClient1->sdk()->sdkConfiguration->retryConfig);

        $rebuiltClient = $api->getApiClient($testKey, true);
        self::assertNotSame($testClient1, $rebuiltClient);

        $api->resetClients();
        self::assertNotSame($rebuiltClient, $api->getApiClient($testKey));
    }

    /**
     * @test
     */
    public function normalizerReusesSerializerAndFastPathsAmountsAndUrls(): void
    {
        self::assertSame(Normalizer::serializer(), Normalizer::serializer());

        $amount = Normalizer::toPlainObject(new Amount(currency: 'EUR', value: '19.95'));
        self::assertInstanceOf(stdClass::class, $amount);
        self::assertSame('EUR', $amount->currency);
        self::assertSame('19.95', $amount->value);

        $nullableAmount = Normalizer::toPlainObject(new AmountNullable(currency: 'USD', value: '5.00'));
        self::assertInstanceOf(stdClass::class, $nullableAmount);
        self::assertSame('USD', $nullableAmount->currency);

        $url = Normalizer::toPlainObject(new Url(href: 'https://example.com', type: 'text/html'));
        self::assertInstanceOf(stdClass::class, $url);
        self::assertSame('https://example.com', $url->href);
        self::assertSame('text/html', $url->type);

        $plain = (object) ['foo' => 'bar'];
        self::assertSame($plain, Normalizer::toPlainObject($plain));
    }

    /**
     * @test
     */
    public function mutatingEndpointsAttachIdempotencyKeyAndShippingSessionsRetryOn503(): void
    {
        $mock = new MockHandler([
            new Response(
                201,
                ['Content-Type' => 'application/hal+json'],
                json_encode([
                    'resource' => 'payment',
                    'id' => 'tr_created123',
                    'mode' => 'test',
                    'createdAt' => '2026-01-01T00:00:00+00:00',
                    'amount' => ['value' => '10.00', 'currency' => 'EUR'],
                    'description' => 'Order #10',
                    'sequenceType' => 'oneoff',
                    'profileId' => 'pfl_123',
                    'status' => 'open',
                    '_links' => [
                        'self' => ['href' => 'https://api.mollie.com/v2/payments/tr_created123', 'type' => 'application/hal+json'],
                        'dashboard' => ['href' => 'https://my.mollie.com/dashboard/payments/tr_created123', 'type' => 'text/html'],
                        'checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/created123', 'type' => 'text/html'],
                    ],
                ], JSON_THROW_ON_ERROR)
            ),
            new Response(503, ['Content-Type' => 'application/json'], '{"status":503,"title":"Service Unavailable","detail":"Retry me"}'),
            new Response(
                201,
                ['Content-Type' => 'application/hal+json'],
                json_encode([
                    'resource' => 'session',
                    'id' => 'sess_retry123',
                    'mode' => 'test',
                    'status' => 'open',
                    'clientAccessToken' => 'tok_abc',
                    'amount' => ['currency' => 'EUR', 'value' => '10.00'],
                    'description' => 'Express Checkout',
                    'redirectUrl' => 'https://example.com/return',
                    'profileId' => 'pfl_123',
                    'createdAt' => '2026-01-01T00:00:00+00:00',
                    'shipping' => ['callbackUrl' => 'https://example.com/shipping'],
                    '_links' => [
                        'self' => ['href' => 'https://api.mollie.com/v2/sessions/sess_retry123', 'type' => 'application/hal+json'],
                    ],
                ], JSON_THROW_ON_ERROR)
            ),
        ]);

        $sentRequests = [];
        $recordingHandler = static function (RequestInterface $request, array $options) use ($mock, &$sentRequests) {
            $sentRequests[] = $request;
            return $mock($request, $options);
        };
        $stack = HandlerStack::create($recordingHandler);
        $guzzle = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);

        $sdk = Client::builder()
            ->setClient($guzzle)
            ->setSecurity(new Security(apiKey: 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'))
            ->setCustomUserAgent('MollieWoo/9.0.0-dev')
            ->setRetryConfig(new RetryConfigBackoff(
                initialIntervalMs: 1,
                maxIntervalMs: 5,
                exponent: 1,
                maxElapsedTimeMs: 1000,
                retryConnectionErrors: true
            ))
            ->build();

        $client = new MollieClient($sdk);

        $payment = $client->payments->create([
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'description' => 'Order #10',
            'redirectUrl' => 'https://example.com/return',
        ]);

        self::assertSame('tr_created123', $payment->id);
        self::assertRegExp(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sentRequests[0]->getHeaderLine('idempotency-key')
        );
        self::assertFalse($sentRequests[0]->hasHeader('x-mollie-sdk-fallback-idempotency-key'));
        self::assertSame(1, substr_count($sentRequests[0]->getHeaderLine('user-agent'), 'MollieWoo/9.0.0-dev'));
        self::assertStringContainsString('mollie/php', $sentRequests[0]->getHeaderLine('user-agent'));

        $session = $client->sessions->create([
            'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            'description' => 'Express Checkout',
            'redirectUrl' => 'https://example.com/return',
            'lines' => [
                [
                    'description' => 'T-Shirt',
                    'quantity' => 1,
                    'unitPrice' => ['currency' => 'EUR', 'value' => '10.00'],
                    'totalAmount' => ['currency' => 'EUR', 'value' => '10.00'],
                ],
            ],
            'requiredCustomerDetails' => ['shipping-address'],
            'shipping' => ['callbackUrl' => 'https://example.com/shipping'],
        ], 'sess-idem-key-1');

        self::assertSame('sess_retry123', $session->id);
        self::assertSame('https://example.com/shipping', $session->shipping->callbackUrl);
        self::assertCount(3, $sentRequests);
        self::assertSame('sess-idem-key-1', $sentRequests[1]->getHeaderLine('idempotency-key'));
        self::assertSame('sess-idem-key-1', $sentRequests[2]->getHeaderLine('idempotency-key'));
        self::assertSame(1, substr_count($sentRequests[2]->getHeaderLine('user-agent'), 'MollieWoo/9.0.0-dev'));
        $sentSessionPayload = json_decode((string) $sentRequests[2]->getBody(), true);
        self::assertSame('https://example.com/shipping', $sentSessionPayload['shipping']['callbackUrl'] ?? null);
        self::assertSame(['shipping-address'], $sentSessionPayload['requiredCustomerDetails'] ?? null);
    }

    /**
     * @test
     */
    public function apiAllowsDisablingRetriesViaFilter(): void
    {
        when('has_filter')->justReturn(false);
        when('apply_filters')->alias(static function (string $hook, $value) {
            if ($hook === 'mollie-payments-for-woocommerce_sdk_retry_config') {
                return new RetryConfigNone();
            }
            return $value;
        });
        when('get_option')->justReturn('1.0.0');

        $api = new Api('9.0.0-dev', 'mollie-payments-for-woocommerce');
        $client = $api->getApiClient('test_cccccccccccccccccccccccccccccc');

        self::assertInstanceOf(RetryConfigNone::class, $client->sdk()->sdkConfiguration->retryConfig);
    }

    /**
     * @test
     */
    public function paymentsEndpointSanitizesLocaleLegacyKlarnaArrayMethodsAndBillingEmail(): void
    {
        $sentRequests = [];
        $paymentJson = static function (string $id): string {
            return json_encode([
                'resource' => 'payment',
                'id' => $id,
                'mode' => 'test',
                'createdAt' => '2026-01-01T00:00:00+00:00',
                'amount' => ['value' => '15.00', 'currency' => 'EUR'],
                'description' => 'Order #99',
                'sequenceType' => 'oneoff',
                'profileId' => 'pfl_123',
                'status' => 'open',
                '_links' => [
                    'self' => ['href' => 'https://api.mollie.com/v2/payments/' . $id, 'type' => 'application/hal+json'],
                    'dashboard' => ['href' => 'https://my.mollie.com/dashboard/payments/' . $id, 'type' => 'text/html'],
                ],
            ], JSON_THROW_ON_ERROR);
        };

        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/hal+json'], $paymentJson('tr_edge1')),
            new Response(201, ['Content-Type' => 'application/hal+json'], $paymentJson('tr_edge2')),
        ]);

        $recordingHandler = static function (RequestInterface $request, array $options) use ($mock, &$sentRequests) {
            $sentRequests[] = $request;
            return $mock($request, $options);
        };
        $stack = HandlerStack::create($recordingHandler);
        $guzzle = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);

        $sdk = Client::builder()
            ->setClient($guzzle)
            ->setSecurity(new Security(apiKey: 'test_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'))
            ->build();

        $client = new MollieClient($sdk);

        $client->payments->create([
            'amount' => ['currency' => 'EUR', 'value' => '15.00'],
            'description' => 'Order #99',
            'redirectUrl' => 'https://example.com/return',
            'locale' => 'ar_SA',
            'method' => 'klarnapaylater',
            'billingEmail' => 'buyer@example.com',
        ]);

        $firstBody = json_decode((string) $sentRequests[0]->getBody(), true);
        self::assertArrayNotHasKey('locale', $firstBody);
        self::assertSame('klarna', $firstBody['method']);
        self::assertSame('buyer@example.com', $firstBody['billingAddress']['email'] ?? null);
        self::assertArrayNotHasKey('billingEmail', $firstBody);

        $client->payments->create([
            'amount' => ['currency' => 'EUR', 'value' => '15.00'],
            'description' => 'Order #100',
            'redirectUrl' => 'https://example.com/return',
            'method' => ['ideal', 'klarnasliceit'],
        ]);

        $secondBody = json_decode((string) $sentRequests[1]->getBody(), true);
        self::assertSame(['ideal', 'klarna'], $secondBody['method']);
    }
}
