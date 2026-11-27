<?php

# -*- coding: utf-8 -*-

declare(strict_types=1);

namespace Mollie\WooCommerce\Payment;

use Mollie\WooCommerce\ExpressComponent\Rules\StartOrderDecision;
use Mollie\WooCommerce\Payment\Rules\CancelUnpaidSchedule;
use Inpsyde\Modularity\Module\ExecutableModule;
use Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Inpsyde\Modularity\Module\ServiceModule;
use Mollie\WooCommerce\Gateway\MolliePaymentGatewayHandler;
use Mollie\WooCommerce\MerchantCapture\Capture\Action\CapturePayment;
use Mollie\WooCommerce\Payment\Webhooks\RestApi;
use Mollie\WooCommerce\PaymentMethods\InstructionStrategies\OrderInstructionsManager;
use Mollie\WooCommerce\SDK\Api;
use Mollie\WooCommerce\SDK\ApiException;
use Mollie\WooCommerce\SDK\HttpResponse;
use Mollie\WooCommerce\Settings\Settings;
use Mollie\WooCommerce\Shared\SharedDataDictionary;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use WC_Order;

class PaymentModule implements ServiceModule, ExecutableModule
{
    use ModuleClassNameIdTrait;

    /**
     * @var mixed
     */
    protected $httpResponse;
    /**
     * @var mixed
     */
    protected $logger;
    protected $apiHelper;
    /**
     * @var mixed
     */
    protected $settingsHelper;
    protected $pluginId;
    /**
     * @var mixed
     */
    protected $gatewayClassnames;
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function services(): array
    {
        static $services;

        if ($services === null) {
            $services = require_once __DIR__ . '/inc/services.php';
        }

        return $services();
    }

    public function run(ContainerInterface $container): bool
    {
        $this->httpResponse = $container->get('SDK.HttpResponse');
        assert($this->httpResponse instanceof HttpResponse);
        $this->logger = $container->get(Logger::class);
        assert($this->logger instanceof Logger);
        $this->apiHelper = $container->get('SDK.api_helper');
        assert($this->apiHelper instanceof Api);
        $this->settingsHelper = $container->get('settings.settings_helper');
        assert($this->settingsHelper instanceof Settings);
        $this->pluginId = $container->get('shared.plugin_id');
        $this->gatewayClassnames = $container->get('gateway.classnames');
        $this->container = $container;

        //add webhook rest API endpoint
        add_action('rest_api_init', static function () use ($container) {
            $container->get(RestApi::class)->registerRoutes();
        });

        // Listen to return URL call
        add_action('woocommerce_api_mollie_return', function () use ($container) {
            $this->onMollieReturn($container);
        }, 10, 1);
        add_action('init', function () use ($container) {
            $this->mollieReturnRedirect($container);
        });

        // Show Mollie instructions on order details page
        add_action('woocommerce_order_details_after_order_table', function (WC_Order $order) use ($container) {
            $this->onOrderDetails($order, $container);
        }, 10, 1);

        // Cancel order at Mollie (for Orders API/Klarna)
        add_action('woocommerce_order_status_cancelled', [ $this, 'cancelOrderAtMollie' ]);

        // Capture order at Mollie (for Orders API/Klarna)
        add_action('woocommerce_order_status_completed', [ $this, 'shipAndCaptureOrderAtMollie' ]);

        add_filter(
            'woocommerce_cancel_unpaid_order',
            [ $this, 'maybeLetWCCancelOrder' ],
            9,
            2
        );
        $paymentMethods = $container->get('gateway.paymentMethods');
        add_action(
            'init',
            function () use ($paymentMethods, $container) {
                $expressCleanup = $container->has('express.cleanup_needed') && ($container->get('express.cleanup_needed'))();
                $this->handleExpiryDateCancelation($paymentMethods, $expressCleanup);
            },
            10,
            0
        );

        return true;
    }

    public function maybeLetWCCancelOrder($willCancel, $order)
    {
        if (!empty($willCancel)) {
            $isMollieGateway = mollieWooCommerceIsMollieGateway($order->get_payment_method(
            ));

            $mollieDueDateEnabled = mollieWooCommerceIsGatewayEnabled($order->get_payment_method(
            ), 'activate_expiry_days_setting');
            if (
                !$isMollieGateway || !$mollieDueDateEnabled
            ) {
                return $willCancel;
            }

            return false;
        }
        return $willCancel;
    }

    public function cancelOrderOnExpiryDate()
    {
        $classNames = $this->gatewayClassnames;
        foreach ($classNames as $gateway) {
            if (empty($gateway)) {
                continue;
            }
            $gatewayName = strtolower($gateway) . '_settings';
            $gatewaySettings = get_option($gatewayName);

            if (empty($gatewaySettings["activate_expiry_days_setting"]) || $gatewaySettings["activate_expiry_days_setting"] === 'no') {
                continue;
            }

            $heldDuration = isset($gatewaySettings['order_dueDate']) ? $gatewaySettings['order_dueDate'] : 0;

            if ($heldDuration < 1) {
                continue;
            }
            $heldDurationInSeconds = $heldDuration * 60;
            if ($gateway === 'Mollie_WC_Gateway_Banktransfer' || $gateway === 'Mollie_WC_Gateway_Paybybank') {
                $durationInHours = absint($heldDuration) * 24;
                $durationInMinutes = $durationInHours * 60;
                $heldDurationInSeconds = $durationInMinutes * 60;
            }
            $args = [
                'limit' => -1,
                'status' => 'pending',
                'payment_method' => strtolower($gateway),
                'date_modified' => '<' . (time() - $heldDurationInSeconds),
                'return' => 'ids',
            ];
            $unpaid_orders = wc_get_orders($args);

            if (!$unpaid_orders) {
                continue;
            }
            foreach ($unpaid_orders as $unpaid_order) {
                $this->cancelOrderIfStillUnpaid($unpaid_order);
            }
        }
    }

    private function cancelOrderIfStillUnpaid($unpaid_order)
    {
        $order = wc_get_order($unpaid_order);
        if (!$order instanceof \WC_Order || $order->get_created_via() === StartOrderDecision::CREATED_VIA) {
            // An express order is cancelled only by ExpireAbandonedExpressOrders, which asks Mollie first.
            return;
        }
        if (!$order->has_status('pending')) {
            return;
        }
        $mollieOrderService = $this->container->get(MollieOrderService::class);
        try {
            $checked = $mollieOrderService->doPaymentForOrder($order);
        } catch (OrderLockTimeout $busy) {
            // Busy elsewhere; the next run looks again.
            return;
        }
        if ($checked) {
            $order = wc_get_order($unpaid_order);
            if (!$order->has_status('pending')) {
                return;
            }
        }
        add_filter('mollie-payments-for-woocommerce_order_status_cancelled', static function ($newOrderStatus) {
            return SharedDataDictionary::STATUS_CANCELLED;
        });
        $order->update_status('cancelled', __('Unpaid order cancelled - time limit reached.', 'woocommerce'));
        $this->cancelOrderAtMollie($order->get_id());
    }

    /**
     * Old Payment return url callback
     *
     */
    public function onMollieReturn($container)
    {
        try {
            $order = self::orderByRequest();
        } catch (RuntimeException $exc) {
            $this->httpResponse->setHttpResponseCode($exc->getCode());
            $this->logger->debug(__METHOD__ . ":  {$exc->getMessage()}");
            return;
        }

        $gateway = wc_get_payment_gateway_by_order($order);
        $orderId = $order->get_id();
        $oldGatewayInstances = $container->get('__deprecated.gateway_helpers');
        $mollieGatewayHelper = $oldGatewayInstances[$gateway->id];

        if (!$gateway) {
            $gatewayName = $order->get_payment_method();

            $this->httpResponse->setHttpResponseCode(404);
            $this->logger->debug(
                __METHOD__ . ":  Could not find gateway {$gatewayName} for order {$orderId}."
            );
            return;
        }

        if (!(mollieWooCommerceIsMollieGateway($gateway->id))) {
            $this->httpResponse->setHttpResponseCode(400);
            $gatewayClass = get_class($gateway);
            $this->logger->debug(__METHOD__ . ": Invalid gateway {$gatewayClass} for this plugin. Order {$orderId}.");
            return;
        }

        $redirect_url = $mollieGatewayHelper->getReturnRedirectUrlForOrder($order);

        // Add utm_nooverride query string
        $redirect_url = add_query_arg(['utm_nooverride' => 1], $redirect_url);

        $this->logger->debug(__METHOD__ . ": Redirect url on return order {$gateway->id}, order {$orderId}: {$redirect_url}");

        wp_safe_redirect($redirect_url);
        die;
    }

    /**
     * New Payment return url callback
     *
     */
    public function mollieReturnRedirect($container)
    {
        if (isset($_GET['filter_flag'])) {
            $filterFlag = sanitize_text_field(wp_unslash($_GET['filter_flag']));
            if ($filterFlag === 'onMollieReturn') {
                self::onMollieReturn($container);
            }
        }
    }

    /**
     * @param WC_Order $order
     */
    public function onOrderDetails(WC_Order $order, ContainerInterface $container)
    {
        if (is_order_received_page()) {
            /**
             * Do not show instruction again below details on order received page
             * Instructions already displayed on top of order received page by $gateway->thankyou_page()
             *
             * @see MolliePaymentGatewayHandler::thankyou_page
             */
            return;
        }

        $gateway = wc_get_payment_gateway_by_order($order);

        if (!$gateway || !(mollieWooCommerceIsMollieGateway($gateway->id))) {
            return;
        }

        assert($gateway instanceof \WC_Payment_Gateway);

        $instructionsManager = $container->get(OrderInstructionsManager::class);
        $oldGatewayInstances = $container->get('__deprecated.gateway_helpers');
        $mollieGatewayHelper = $oldGatewayInstances[$gateway->id];
        $instructionsManager->displayInstructions(
            $gateway,
            $mollieGatewayHelper,
            $order
        );
    }
    /**
     * Ship all order lines and capture an order at Mollie.
     *
     */
    public function shipAndCaptureOrderAtMollie($order_id)
    {
        $order = wc_get_order($order_id);

        // Does WooCommerce order contain a Mollie payment?
        if (!$order || strstr($order->get_payment_method(), 'mollie_wc_gateway_') === false) {
            return;
        }

        // To disable automatic shipping and capturing of the Mollie order when a WooCommerce order status is updated to completed,
        // store an option 'mollie-payments-for-woocommerce_disableShipOrderAtMollie' with value 1
        if (apply_filters('mollie_wc_gateway_disable_ship_and_capture', get_option($this->pluginId . '_' . 'disableShipOrderAtMollie', '0') === '1', $order)) {
            return;
        }

        $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Try to process completed order for a potential capture at Mollie.');

        $mollie_transaction_id = $order->get_meta('_mollie_payment_id', true);
        if (!$mollie_transaction_id) {
            $mollie_transaction_id = $order->get_transaction_id();
        }
        if (!$mollie_transaction_id || strpos($mollie_transaction_id, 'tr_') !== 0) {
            $message = _x('Order contains Mollie payment method, but not a valid Mollie Transaction ID. Processing shipment & capture failed.', 'Order note info', 'mollie-payments-for-woocommerce');
            $order->add_order_note($message);
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' Order contains Mollie payment method, but not a valid Mollie Transaction ID. Processing shipment & capture failed.');
            return;
        }

        $apiKey = $this->settingsHelper->getApiKey();
        try {
            $mollie_transaction = $this->apiHelper->getApiClient($apiKey)->payments->get($mollie_transaction_id);

            // Check that payment is Paid or Authorized and can be captured
            if ($mollie_transaction->isCanceled()) {
                $message = _x('Transaction already canceled at Mollie, can not be shipped/captured.', 'Order note info', 'mollie-payments-for-woocommerce');
                $order->add_order_note($message);
                $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Payment already canceled at Mollie, can not be shipped/captured.');

                return;
            }

            if ($mollie_transaction->isPaid() || $mollie_transaction->isAuthorized()) {
                if ($mollie_transaction->isAuthorized()) {
                    ($this->container->get(CapturePayment::class))($order_id);
                } else {
                    $message = _x('Payment status is already paid at Mollie, can not be captured.', 'Order note info', 'mollie-payments-for-woocommerce');
                    $order->add_order_note($message);
                    $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Payment already completed at Mollie, can not be captured.');
                }

                return;
            }
            $message = _x('Transaction not paid or authorized at Mollie yet, can not be shipped.', 'Order note info', 'mollie-payments-for-woocommerce');
            $order->add_order_note($message);
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Payment not paid or authorized at Mollie yet, can not be shipped.');
        } catch (ApiException $e) {
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Processing shipment & capture failed, error: ' . $e->getMessage());
        }
    }

    /**
     * Cancel an order at Mollie.
     *
     */
    public function cancelOrderAtMollie($order_id)
    {
        $order = wc_get_order($order_id);

        // Does WooCommerce order contain a Mollie payment?
        if (strstr($order->get_payment_method(), 'mollie_wc_gateway_') === false) {
            return;
        }

        // To disable automatic canceling of the Mollie order when a WooCommerce order status is updated to canceled,
        // store an option 'mollie-payments-for-woocommerce_disableCancelOrderAtMollie' with value 1
        if (get_option($this->pluginId . '_' . 'disableCancelOrderAtMollie', '0') === '1') {
            return;
        }

        $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Try to process cancelled order at Mollie.');

        $mollie_payment_id = $order->get_meta('_mollie_payment_id', true);
        if (!$mollie_payment_id) {
            $mollie_payment_id = $order->get_transaction_id();
        }

        if (!$mollie_payment_id || strpos($mollie_payment_id, 'tr_') !== 0) {
            $message = _x('Order contains Mollie payment method, but not a valid Mollie Payment ID. Canceling order failed.', 'Order note info', 'mollie-payments-for-woocommerce');
            $order->add_order_note($message);
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Order contains Mollie payment method, but not a valid Mollie Payment ID. Canceling order failed.');

            return;
        }

        $apiKey = $this->settingsHelper->getApiKey();
        try {
            $apiClient = $this->apiHelper->getApiClient($apiKey);
            $mollie_payment = $apiClient->payments->get($mollie_payment_id);

            if ($mollie_payment->isCanceled()) {
                $message = _x('Order already canceled at Mollie, can not be canceled again.', 'Order note info', 'mollie-payments-for-woocommerce');
                $order->add_order_note($message);
                $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Order already canceled at Mollie, can not be canceled again.');

                return;
            }

            if ($mollie_payment->isAuthorized()) {
                $apiClient->payments->cancel($mollie_payment_id);
                $message = _x('Order also cancelled at Mollie.', 'Order note info', 'mollie-payments-for-woocommerce');
                $order->add_order_note($message);
                $this->logger->debug(
                    __METHOD__ . ' - ' . $order_id . ' - Order cancelled in WooCommerce, also cancelled at Mollie.'
                );

                return;
            }
            $message = _x('Order could not be canceled at Mollie, because order status is ', 'Order note info', 'mollie-payments-for-woocommerce');
            $order->add_order_note($message . $mollie_payment->status . '.');
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Order could not be canceled at Mollie, because order status is ' . $mollie_payment->status . '.');
        } catch (ApiException $e) {
            $this->logger->debug(__METHOD__ . ' - ' . $order_id . ' - Updating order to canceled at Mollie failed, error: ' . $e->getMessage());
        }

        return;
    }

    /**
     * Add/remove scheduled action to cancel orders on expiration date, and to clean up abandoned
     * express orders, which run on the same action
     * @param $paymentMethods
     * @param bool $expressCleanup Express is on, or one of its pending orders may be left.
     * @return void
     */
    public function handleExpiryDateCancelation($paymentMethods, bool $expressCleanup = false)
    {
        if (!CancelUnpaidSchedule::needed($this->gatewaySettings($paymentMethods), $expressCleanup)) {
            as_unschedule_action('mollie_woocommerce_cancel_unpaid_orders');
            return;
        }
        $canSchedule = function_exists('as_schedule_single_action');
        if ($canSchedule) {
            if (false === as_next_scheduled_action('mollie_woocommerce_cancel_unpaid_orders')) {
                as_schedule_recurring_action(time(), 600, 'mollie_woocommerce_cancel_unpaid_orders');
            }
            add_action(
                'mollie_woocommerce_cancel_unpaid_orders',
                [$this, 'cancelOrderOnExpiryDate'],
                11,
                0
            );
        }
    }

    /**
     * Check if there is any payment method that has the expiry date enabled
     * @param $paymentMethods
     * @return bool
     */
    public function IsExpiryDateEnabled($paymentMethods): bool
    {
        return CancelUnpaidSchedule::needed($this->gatewaySettings($paymentMethods), false);
    }

    /**
     * Each payment method's stored settings, as get_option() returns them.
     *
     * @param $paymentMethods
     * @return array<int, mixed>
     */
    private function gatewaySettings($paymentMethods): array
    {
        $settings = [];
        foreach ($paymentMethods as $paymentMethod) {
            $settings[] = get_option("mollie_wc_gateway_{$paymentMethod->getProperty('id')}_settings", false);
        }
        return $settings;
    }

    /**
     * Returns the order from the Request first by Id, if not by Key
     *
     * @return WC_Order|\WC_Order_Refund|true
     */
    public function orderByRequest()
    {
        $orderId = filter_input(INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $key = filter_input(INPUT_GET, 'key', FILTER_SANITIZE_SPECIAL_CHARS) ?? null;
        $order = wc_get_order($orderId);

        if (!$order) {
            $order = wc_get_order(wc_get_order_id_by_order_key($key));
        }

        if (!$order) {
            throw new RuntimeException(
                esc_html("Could not find order by order Id {$orderId}"),
                404
            );
        }

        if (!$order->key_is_valid($key)) {
            throw new RuntimeException(
                esc_html("Invalid key given. Key {$key} does not match the order id: {$orderId}"),
                401
            );
        }

        return $order;
    }
}
