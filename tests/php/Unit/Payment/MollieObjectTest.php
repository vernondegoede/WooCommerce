<?php

declare(strict_types=1);

namespace Mollie\WooCommerceTests\Unit\Payment;

use Mockery;
use Mollie\WooCommerce\Payment\MollieObject;
use Mollie\WooCommerce\Payment\PaymentFactory;
use Mollie\WooCommerce\Payment\Request\RequestFactory;
use Mollie\WooCommerce\SDK\Api;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\Endpoints\PaymentsEndpoint;
use Mollie\WooCommerce\SDK\MollieClient;
use Mollie\WooCommerce\SDK\Resources\Payment as MollieApiPayment;
use Mollie\WooCommerce\Settings\Settings;
use Mollie\WooCommerceTests\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionProperty;

use function Brain\Monkey\Functions\when;

/**
 * @covers \Mollie\WooCommerce\Payment\MollieObject
 */
class MollieObjectTest extends TestCase
{
    /** @var LoggerInterface&\Mockery\MockInterface */
    private $logger;

    /** @var Settings&\Mockery\MockInterface */
    private $settings;

    /** @var Api&\Mockery\MockInterface */
    private $apiHelper;

    /** @var MollieClient */
    private $apiClient;

    /** @var \Mockery\MockInterface */
    private $paymentEndpoint;

    private MollieObject $sut;

    protected function setUp(): void
    {
        parent::setUp();

        when('wc_get_base_location')->justReturn(['country' => 'NL']);

        $this->logger          = Mockery::mock(LoggerInterface::class);
        $this->settings        = Mockery::mock(Settings::class);
        $this->apiHelper       = Mockery::mock(Api::class);
        $this->paymentEndpoint = Mockery::mock(PaymentsEndpoint::class);

        $this->logger->shouldReceive('debug')->zeroOrMoreTimes();
        $this->settings->shouldReceive('isTestModeEnabled')->andReturn(false)->byDefault();
        $this->settings->shouldReceive('getApiKey')->andReturn('test_api_key')->byDefault();

        $this->apiClient           = $this->createMock(MollieClient::class);
        $this->apiClient->payments = $this->paymentEndpoint;

        $this->apiHelper
            ->shouldReceive('getApiClient')
            ->with('test_api_key')
            ->andReturn($this->apiClient)
            ->byDefault();

        $this->resetStaticCaches();

        $this->sut = new MollieObject(
            null,
            $this->logger,
            Mockery::mock(PaymentFactory::class),
            $this->apiHelper,
            $this->settings,
            'mollie-payments-for-woocommerce',
            Mockery::mock(RequestFactory::class)
        );
    }

    private function resetStaticCaches(): void
    {
        try {
            $ref = new ReflectionProperty(MollieObject::class, 'paymentObjectCache');
            $ref->setValue(null, []);
        } catch (ReflectionException $e) {
            // Ignore if absent
        }
    }

    private function makePayment(string $id = 'tr_test123'): MollieApiPayment
    {
        $payment     = Mockery::mock(MollieApiPayment::class);
        $payment->id = $id;
        return $payment;
    }

    /**
     * @scenario Within a single PHP request, calling getPaymentObjectPayment with the same
     *           payment_id and use_cache=true more than once returns the result cached from
     *           the first call without making additional HTTP requests to the Mollie API.
     * @covers \Mollie\WooCommerce\Payment\MollieObject::getPaymentObjectPayment
     */
    public function test_cache_deduplicates_repeated_calls_for_same_payment_id(): void
    {
        $paymentId = 'tr_cache_test';
        $payment   = $this->makePayment($paymentId);
        $this->paymentEndpoint
            ->shouldReceive('get')
            ->with($paymentId)
            ->once()
            ->andReturn($payment);

        $first  = $this->sut->getPaymentObjectPayment($paymentId, false, true);
        $second = $this->sut->getPaymentObjectPayment($paymentId, false, true);

        $this->assertSame($payment, $first);
        $this->assertSame($payment, $second);
    }

    /**
     * @scenario When use_cache=false, getPaymentObjectPayment always calls the Mollie API,
     *           updates the static cache entry for the payment_id, and the subsequent
     *           use_cache=true call returns the fresh result without another API call.
     * @covers \Mollie\WooCommerce\Payment\MollieObject::getPaymentObjectPayment
     */
    public function test_use_cache_false_bypasses_cache_and_updates_it(): void
    {
        $paymentId   = 'tr_bypass_test';
        $firstResult = $this->makePayment($paymentId);
        $freshResult = $this->makePayment($paymentId);
        $this->paymentEndpoint
            ->shouldReceive('get')
            ->with($paymentId)
            ->twice()
            ->andReturn($firstResult, $freshResult);

        $this->sut->getPaymentObjectPayment($paymentId, false, true);
        $fromBypass = $this->sut->getPaymentObjectPayment($paymentId, false, false);
        $fromCache  = $this->sut->getPaymentObjectPayment($paymentId, false, true);

        $this->assertSame($freshResult, $fromBypass);
        $this->assertSame($freshResult, $fromCache);
    }

    /**
     * @scenario When the first call throws an ApiException, null is stored in the cache;
     *           subsequent use_cache=true calls within the same request return null without
     *           making further API calls.
     * @covers \Mollie\WooCommerce\Payment\MollieObject::getPaymentObjectPayment
     */
    public function test_null_is_cached_when_api_throws(): void
    {
        $paymentId = 'tr_error_test';
        $this->paymentEndpoint
            ->shouldReceive('get')
            ->with($paymentId)
            ->once()
            ->andThrow(new ApiException('Connection error'));

        $first  = $this->sut->getPaymentObjectPayment($paymentId, false, true);
        $second = $this->sut->getPaymentObjectPayment($paymentId, false, true);

        $this->assertNull($first);
        $this->assertNull($second);
    }
}
