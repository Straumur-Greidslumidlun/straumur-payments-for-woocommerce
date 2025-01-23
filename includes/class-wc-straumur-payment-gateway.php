<?php
/**
 * Straumur Payment Gateway Class.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WC_Payment_Gateway;
use WC_Order;
use WC_Logger;
use WP_Error;
use function esc_html__;
use function wc_add_notice;
use function wc_get_logger;
use function wc_get_order;
use function wc_price;
use function wp_safe_redirect;
use function get_woocommerce_currency;

/**
 * Class WC_Straumur_Payment_Gateway
 *
 * Handles integration with Straumur Hosted Checkout, including initiating payment sessions,
 * and handling return callbacks.
 *
 * @since 1.0.0
 */
class WC_Straumur_Payment_Gateway extends WC_Payment_Gateway
{
    /**
     * WooCommerce logger instance.
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Log context array.
     *
     * @var array
     */
    private $context = ['source' => 'straumur-payments'];

    /**
     * Terminal identifier.
     *
     * @var string
     */
    private $terminal_identifier = '';

    /**
     * API key for Straumur.
     *
     * @var string
     */
    private $api_key = '';

    /**
     * Template/Theme key for customizing the checkout.
     *
     * @var string
     */
    private $theme_key = '';

    /**
     * Whether to only authorize payment (and manually capture later).
     *
     * @var bool
     */
    private $authorize_only = false;

    /**
     * HMAC secret key for webhook validation.
     *
     * @var string
     */
    private $hmac_key = '';

    /**
     * Whether items should be sent to the hosted checkout.
     *
     * @var bool
     */
    private $send_items = false;

    /**
     * Constructor for the gateway.
     *
     * Sets up the basic gateway properties, loads settings, and hooks into WooCommerce.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->id                 = 'straumur';
        $this->method_title       = esc_html__('Straumur Payments', 'straumur-payments-for-woocommerce');
        $this->method_description = esc_html__('Accept payments via Straumur Hosted Checkout.', 'straumur-payments-for-woocommerce');
        $this->has_fields         = false;

        $this->supports           = [
            'products',
            'wc-blocks',
            'add_payment_method',
        ];

        $this->logger = wc_get_logger();

        $this->init_form_fields();

        // Load values from WC_Straumur_Settings.
        $this->title               = WC_Straumur_Settings::get_title();
        $this->description         = WC_Straumur_Settings::get_description();
        $this->enabled             = WC_Straumur_Settings::is_enabled() ? 'yes' : 'no';
        $this->terminal_identifier = WC_Straumur_Settings::get_terminal_identifier();
        $this->api_key             = WC_Straumur_Settings::get_api_key();
        $this->theme_key           = WC_Straumur_Settings::get_theme_key();
        $this->authorize_only      = WC_Straumur_Settings::is_authorize_only();
        $this->hmac_key            = WC_Straumur_Settings::get_hmac_key();
        $this->send_items          = WC_Straumur_Settings::send_items();

        // Hook return URL action (for ?wc-api=straumur).
        add_action('woocommerce_api_' . $this->id, [$this, 'process_return']);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * Initialize form fields for the settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function init_form_fields(): void
    {
        $this->form_fields = WC_Straumur_Settings::get_form_fields();
    }

    /**
     * Validate the production URL field.
     *
     * Ensures that the production URL is not empty when test mode is disabled.
     *
     * @since 1.0.0
     *
     * @param string $key   Option key.
     * @param string $value Option value.
     * @return string
     */
    public function validate_production_url_field(string $key, string $value): string
    {
        $test_mode = WC_Straumur_Settings::is_test_mode();
        return WC_Straumur_Settings::validate_production_url_field($test_mode, $value);
    }

    /**
     * A pre-configured instance of WC_Straumur_API.
     *
     * @since 1.0.0
     * @return WC_Straumur_API
     */
    private function get_api(): WC_Straumur_API
    {
        return new WC_Straumur_API($this->authorize_only, $this->send_items);
    }

    /**
     * Retrieve order items and optionally adjust their total to match the expected amount.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order          The order object.
     * @param int      $expected_amount Amount in minor units to match.
     * @return array Order items array.
     */
    private function getOrderItems(WC_Order $order, int $expected_amount): array
    {
        $items            = [];
        $calculated_total = 0;

        foreach ($order->get_items() as $item) {
            $product_name = $item->get_name();
            $line_total   = (int) round(($item->get_total() + $item->get_total_tax()) * 100);

            $items[] = [
                'Name'   => $product_name,
                'Amount' => $line_total,
            ];

            $calculated_total += $line_total;
        }

        if ($order->get_shipping_total() > 0) {
            $shipping_cost = (int) round(($order->get_shipping_total() + $order->get_shipping_tax()) * 100);
            $items[] = ['Name' => 'Delivery', 'Amount' => $shipping_cost];
            $calculated_total += $shipping_cost;
        }

        $difference = $expected_amount - $calculated_total;
        if (0 !== $difference && count($items) > 0) {
            $items[count($items) - 1]['Amount'] += $difference;
        }

        return $items;
    }

    /**
     * Process the payment and return the redirect URL to the payment page.
     *
     * @since 1.0.0
     *
     * @param int $order_id The WooCommerce order ID.
     * @return array|WP_Error
     */
    public function process_payment($order_id)
    {

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(esc_html__('Invalid order.', 'straumur-payments-for-woocommerce'), 'error');
            $this->logger->error('Invalid order: ' . $order_id, $this->context);
            return ['result' => 'failure'];
        }

        $api = $this->get_api();

        $order->update_meta_data('_straumur_is_manual_capture', $this->authorize_only ? 'yes' : 'no');
        $order->save();

        $amount     = (int) ($order->get_total() * 100);
        $currency   = get_woocommerce_currency();
        $reference  = $order->get_order_number();

 
        $items = $this->getOrderItems($order, $amount);

  
        $return_url = add_query_arg(
            [
                'wc-api'         => $this->id,
                'order_id'       => $order->get_id(),
                'straumur_nonce' => wp_create_nonce('straumur_process_return'),
            ],
            home_url('/')
        );

        if (is_wp_error($items)) {
            wc_add_notice(
                esc_html__('Payment error: ', 'straumur-payments-for-woocommerce') . $items->get_error_message(),
                'error'
            );
            $this->logger->error(
                'Payment error: ' . $items->get_error_message() . ' for order ' . $order_id,
                $this->context
            );
            return ['result' => 'failure'];
        }

        // Create session via API. The API class will decide whether to include items or not.
        $session = $api->create_session($amount, $currency, $return_url, $reference, $items);

        if (!$session || !isset($session['url'])) {
            wc_add_notice(
                esc_html__('Payment error: Unable to initiate payment session.', 'straumur-payments-for-woocommerce'),
                'error'
            );
            $this->logger->error('Payment error: Unable to initiate payment session for order ' . $order_id, $this->context);
            return ['result' => 'failure'];
        }

        $redirect_url = $session['url'];

        return [
            'result'   => 'success',
            'redirect' => $redirect_url,
        ];
    }

    /**
     * Handle the return from the payment gateway.
     *
     * If payment initiated (payfacReference found), set order to pending and show thank you page.
     * Otherwise, redirect user back to payment page or cart if that fails.
     *
     * @since 1.0.0
     * @return void
     */
    public function process_return(): void
    {
        if (
            ! isset($_GET['straumur_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['straumur_nonce'])),
                'straumur_process_return'
            )
        ) {
            wp_die(esc_html(__('Nonce verification failed.', 'straumur-payments-for-woocommerce')));
        }

        $order_id = 0;
        if (isset($_GET['order_id'])) {
            $order_id = absint(wp_unslash($_GET['order_id']));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $checkout_reference = '';
        if (isset($_GET['checkoutReference'])) {
            $checkout_reference = sanitize_text_field(wp_unslash($_GET['checkoutReference']));
        }

        if (empty($checkout_reference)) {
            $checkout_reference = $order->get_meta('_straumur_checkout_reference');
        } else {
            if (!$order->get_meta('_straumur_checkout_reference')) {
                $order->update_meta_data('_straumur_checkout_reference', $checkout_reference);
                $order->save();
            }
        }

        if (empty($checkout_reference)) {
            $payment_url = $order->get_checkout_payment_url();
            if (empty($payment_url)) {
                $payment_url = wc_get_cart_url();
            }
            wp_safe_redirect($payment_url);
            exit;
        }

        $api             = $this->get_api();
        $status_response = $api->get_session_status($checkout_reference);
        if (!$status_response) {
            // Mark order failed, show user a notice, redirect.
            $this->update_order_status($order, 'failed', 'Unable to fetch payment status via Straumur.');
            wc_add_notice(
                __('There was an issue retrieving your payment status. Please try again.', 'straumur-payments-for-woocommerce'),
                'error'
            );

            $payment_url = $order->get_checkout_payment_url();
            if (empty($payment_url)) {
                $payment_url = wc_get_cart_url();
            }
            wp_safe_redirect($payment_url);
            exit;
        }

        if (isset($status_response['payfacReference'])) {
            $payfac_ref = sanitize_text_field($status_response['payfacReference']);
            $order->update_meta_data('_straumur_payfac_reference', $payfac_ref);
            $order->save();

            $reserve_expiry = $order->get_meta('_straumur_stock_reserve_expires');
            if (empty($reserve_expiry)) {
                $order->update_meta_data('_straumur_stock_reserve_expires', time() + 3600);
                $order->save();
            }

            $this->update_order_status($order, 'pending', 'Payment pending. Awaiting confirmation from Straumur.');

            $note = sprintf(
                    /* translators: %s: The Payfac reference number for the transaction. */
                __('Payment pending, awaiting payment confirmation. Straumur Reference: %s', 'straumur-payments-for-woocommerce'),
                $payfac_ref
            );
            $order->add_order_note($note);

            wc_add_notice(
                __('Thank you for your order! Your payment is currently being processed.', 'straumur-payments-for-woocommerce'),
                'success'
            );

            $redirect_url = $this->get_return_url($order);
            if (empty($redirect_url)) {
                $redirect_url = $order->get_checkout_payment_url();
                if (empty($redirect_url)) {
                    $redirect_url = wc_get_cart_url();
                }
            }
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            wc_add_notice(
                __('Your payment session has not completed. Please try again.', 'straumur-payments-for-woocommerce'),
                'error'
            );

            $payment_url = $order->get_checkout_payment_url();
            if (empty($payment_url)) {
                $payment_url = wc_get_cart_url();
            }
            wp_safe_redirect($payment_url);
            exit;
        }
    }

    /**
     * Update the order status and add a status note.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order  The order to update.
     * @param string   $status New order status (e.g. 'failed', 'pending').
     * @param string   $note   Note explaining the status update.
     * @return void
     */
    private function update_order_status(WC_Order $order, string $status, string $note): void
    {
        $order->update_status($status, $note);
    }

    /**
     * Process and save admin options.
     *
     * @since 1.0.0
     * @return bool
     */
    public function process_admin_options(): bool
    {
        return parent::process_admin_options();
    }

    /**
     * Log an info message.
     *
     * @param string $message The message to log.
     * @return void
     */
    private function log_info(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->logger->info($message, $this->context);
        }
    }

    /**
     * Log an error message.
     *
     * @param string $message The message to log.
     * @return void
     */
    private function log_error(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->logger->error($message, $this->context);
        }
    }
}
