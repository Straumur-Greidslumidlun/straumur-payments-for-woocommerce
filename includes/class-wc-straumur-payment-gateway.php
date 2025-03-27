<?php
/**
 * Straumur Payment Gateway Class.
 *
 * Integrates Straumur Hosted Checkout into WooCommerce, handling payment sessions,
 * return callbacks, and optional subscription payments.
 *
 * WC Subscriptions Support: yes
 * WC tested up to: 9.7
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use WC_Payment_Gateway;
use WC_Order;
use WC_Logger;
use WP_Error;
use WC_Payment_Token;
use function esc_html__;
use function wc_add_notice;
use function wc_get_logger;
use function wc_get_order;
use function wc_price;
use function wp_safe_redirect;
use function get_woocommerce_currency;

if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
	require_once WC_ABSPATH . 'includes/abstracts/abstract-wc-payment-tokens.php';
}

/**
 * Class WC_Straumur_Payment_Gateway
 *
 * @since 1.0.0
 */
class WC_Straumur_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger
	 */
	private WC_Logger $logger;

	/**
	 * Log context array.
	 *
	 * @var array<string, string>
	 */
	private array $context = [
		'source' => 'straumur-payments',
	];

	/**
	 * Terminal identifier.
	 *
	 * @var string
	 */
	private string $terminal_identifier = '';

	/**
	 * Gateway Terminal identifier.
	 *
	 * @var string
	 */
	private string $gateway_terminal_identifier = '';

	/**
	 * API key for Straumur.
	 *
	 * @var string
	 */
	private string $api_key = '';

	/**
	 * Template/Theme key for customizing the checkout.
	 *
	 * @var string
	 */
	private string $theme_key = '';

	/**
	 * Whether to only authorize payment (and manually capture later).
	 *
	 * @var bool
	 */
	private bool $authorize_only = false;

	/**
	 * HMAC secret key for webhook validation.
	 *
	 * @var string
	 */
	private string $hmac_key = '';

	/**
	 * Whether items should be sent to the hosted checkout.
	 *
	 * @var bool
	 */
	private bool $send_items = false;

	/**
	 * Abandon URL for the payment session.
	 *
	 * @var string
	 */
	private string $abandon_url = '';

	/**
	 * Custom success URL for the payment process.
	 *
	 * @var string
	 */
	private string $custom_success_url = '';

	/**
	 * Constructor for the gateway.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = wc_get_logger();

		$this->id                 = 'straumur';
		$this->method_title       = esc_html__( 'Straumur Payments', 'straumur-payments-for-woocommerce' );
		$this->method_description = esc_html__( 'Accept payments via Straumur Hosted Checkout.', 'straumur-payments-for-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/images/straumur-128x128.png';

		/*
		 * Mark the supports array.
		 * Includes 'wc-blocks' for block-based checkout and 'wc-orders' for HPOS compatibility.
		 * If you need subscriptions, ensure 'subscriptions' is included and your plugin
		 * properly implements the necessary subscription hooks.
		 */
		$this->supports = [
			'products',
			'subscriptions',
			'wc-blocks',
			'wc-orders', // Enable High-Performance Order Storage / HPOS support.
		];

		// Load settings fields from the custom settings class.
		$this->init_form_fields();

		// Apply settings to local properties.
		$this->init_settings_values();

		// Hook return URL action (for ?wc-api=straumur).
		add_action( 'woocommerce_api_' . $this->id, [ $this, 'process_return' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		// Subscription payment hooks.
		add_action( 'woocommerce_scheduled_subscription_payment_straumur', [ $this, 'process_subscription_payment' ], 10, 2 );
		add_action( 'woocommerce_subscription_payment_method_updated_to_straumur', [ $this, 'process_subscription_payment_method_change' ], 10, 1 );
	}

	/**
	 * Initialize form fields for the settings page.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = WC_Straumur_Settings::get_form_fields();
	}

	/**
	 * Load settings into local gateway properties.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_settings_values(): void {
		$this->title                       = WC_Straumur_Settings::get_title();
		$this->description                 = WC_Straumur_Settings::get_description();
		$this->enabled                     = WC_Straumur_Settings::is_enabled() ? 'yes' : 'no';
		$this->terminal_identifier         = WC_Straumur_Settings::get_terminal_identifier();
		$this->gateway_terminal_identifier = WC_Straumur_Settings::get_gateway_terminal_identifier();
		$this->api_key                     = WC_Straumur_Settings::get_api_key();
		$this->theme_key                   = WC_Straumur_Settings::get_theme_key();
		$this->authorize_only              = WC_Straumur_Settings::is_authorize_only();
		$this->hmac_key                    = WC_Straumur_Settings::get_hmac_key();
		$this->send_items                  = WC_Straumur_Settings::send_items();
		$this->abandon_url                 = WC_Straumur_Settings::get_abandon_url();
		$this->custom_success_url          = WC_Straumur_Settings::get_custom_success_url();
	}

	/**
	 * Validate the production URL field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Option key.
	 * @param string $value Option value.
	 *
	 * @return string
	 */
	public function validate_production_url_field( string $key, string $value ): string {
		$test_mode = WC_Straumur_Settings::is_test_mode();

		return WC_Straumur_Settings::validate_production_url_field( $test_mode, $value );
	}

	/**
	 * Get a pre-configured instance of WC_Straumur_API.
	 *
	 * @since 1.0.0
	 * @return WC_Straumur_API
	 */
	private function get_api(): WC_Straumur_API {
		return new WC_Straumur_API( $this->authorize_only );
	}

	/**
	 * Retrieve line items for the order to send to Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order          Order object.
	 * @param int      $expected_amount Minor units total.
	 *
	 * @return array[] {
	 *     @type array {
	 *         @type string 'Name'   Item name.
	 *         @type int    'Amount' Line item total in minor units.
	 *     }
	 * }
	 */
	private function get_order_items( WC_Order $order, int $expected_amount ): array {
		$items            = [];
		$calculated_total = 0;

		foreach ( $order->get_items() as $item ) {
			$line_total   = (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 );
			$product_name = $item->get_name();

			$items[] = [
				'Name'   => $product_name,
				'Amount' => $line_total,
			];
			$calculated_total += $line_total;
		}

		if ( $order->get_shipping_total() > 0 ) {
			$shipping_cost = (int) round( ( $order->get_shipping_total() + $order->get_shipping_tax() ) * 100 );
			$items[]       = [
				'Name'   => esc_html__( 'Delivery', 'straumur-payments-for-woocommerce' ),
				'Amount' => $shipping_cost,
			];
			$calculated_total += $shipping_cost;
		}

		// Adjust last line item if there's any minor difference due to rounding.
		$difference = $expected_amount - $calculated_total;
		if ( 0 !== $difference && ! empty( $items ) ) {
			$items[ count( $items ) - 1 ]['Amount'] += $difference;
		}

		return $items;
	}

	/**
	 * Handle the payment process and return a redirect URL to Straumur's hosted checkout.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id The WooCommerce order ID.
	 *
	 * @return array|WP_Error
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice(
				esc_html__( 'Invalid order.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			$this->logger->error( 'Invalid order: ' . $order_id, $this->context );

			return [ 'result' => 'failure' ];
		}

		$api = $this->get_api();

		// Save whether it's manual capture.
		$order->update_meta_data( '_straumur_is_manual_capture', $this->authorize_only ? 'yes' : 'no' );
		$order->save();

		// Convert order total to minor units.
		$amount    = (int) round( $order->get_total() * 100 );
		$currency  = get_woocommerce_currency();
		$reference = $order->get_order_number();

		// Build line items if needed.
		$items = $this->get_order_items( $order, $amount );

		// Build return URL.
		$return_url = add_query_arg(
			[
				'wc-api'         => $this->id,
				'order_id'       => $order->get_id(),
				'straumur_nonce' => wp_create_nonce( 'straumur_process_return' ),
			],
			home_url( '/' )
		);

		// Check if the order is a subscription.
		$is_subscription = false;
		if (
			function_exists( 'wcs_order_contains_subscription' )
			&& wcs_order_contains_subscription( $order_id )
		) {
			$is_subscription = true;
		}

		// Create session with Straumur.
		$session = $api->create_session(
			$amount,
			$currency,
			$return_url,
			$reference,
			$items,
			$is_subscription,
			$this->abandon_url
		);

		if ( ! $session || ! isset( $session['url'] ) ) {
			wc_add_notice(
				esc_html__( 'Payment error: Unable to initiate payment session.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			$this->logger->error(
				'Payment error: Unable to initiate payment session for order ' . $order_id,
				$this->context
			);

			return [ 'result' => 'failure' ];
		}

		// Straumur returns the Hosted Checkout URL.
		$redirect_url = $session['url'];

		return [
			'result'   => 'success',
			'redirect' => $redirect_url,
		];
	}

	/**
	 * Process a subscription payment (renewal) using the saved token.
	 *
	 * @since 1.0.0
	 *
	 * @param float    $amount The amount to be charged.
	 * @param WC_Order $order  The order object (renewal order).
	 *
	 * @return array
	 */
	public function process_subscription_payment( $amount, WC_Order $order ): array {
		$this->logger->info(
			sprintf(
				'Processing subscription payment of %s for order %d',
				$amount,
				$order->get_id()
			),
			$this->context
		);

		// Retrieve the customer's default payment token for this gateway.
		$tokens        = \WC_Payment_Tokens::get_customer_tokens( $order->get_user_id(), $this->id );
		$default_token = null;

		if ( ! empty( $tokens ) ) {
			foreach ( $tokens as $token ) {
				if ( method_exists( $token, 'is_default' ) && $token->is_default() ) {
					$default_token = $token;
					break;
				}
			}
		}

		if ( ! $default_token ) {
			$this->logger->error(
				'No default token found for order ' . $order->get_id(),
				$this->context
			);

			return [ 'result' => 'failure' ];
		}
		$token_value = $default_token->get_token();

		// Convert amount to minor units.
		$amount_minor = (int) round( $amount * 100 );
		$currency     = get_woocommerce_currency();
		$reference    = $order->get_order_number();

		// Retrieve shopper IP (if available) and origin.
		$shopper_ip = method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '';
		$origin     = home_url( '/' );

		// Build return URL in case additional action is required.
		$return_url = add_query_arg(
			[
				'wc-api'         => $this->id,
				'order_id'       => $order->get_id(),
				'straumur_nonce' => wp_create_nonce( 'straumur_process_return' ),
			],
			home_url( '/' )
		);

		// Process token payment via API.
		$api      = $this->get_api();
		$response = $api->process_token_payment(
			$token_value,
			$amount_minor,
			$currency,
			$reference,
			$shopper_ip,
			$origin,
			'Web',
			$return_url
		);

		if ( isset( $response['resultCode'] ) && 'Authorised' === $response['resultCode'] ) {
			// Determine if the order should be marked completed or just payment complete.
			$mark_as_complete = WC_Straumur_Settings::is_complete_order_on_payment();
			if ( ! $order->needs_processing() ) {
				$mark_as_complete = true;
			}

			if ( $mark_as_complete ) {
				$order->update_status(
					'completed',
					esc_html__( 'Subscription renewal authorized via token payment.', 'straumur-payments-for-woocommerce' )
				);
			} else {
				$order->payment_complete();
				$order->add_order_note(
					esc_html__( 'Subscription renewal authorized via token payment.', 'straumur-payments-for-woocommerce' )
				);
			}

			$this->logger->info(
				'Subscription payment authorized for order ' . $order->get_id(),
				$this->context
			);

			return [ 'result' => 'success' ];
		} elseif ( isset( $response['resultCode'] ) && 'RedirectShopper' === $response['resultCode'] ) {
			$redirect_url = $response['redirect']['url'] ?? '';
			$order->add_order_note(
				sprintf(
					/* translators: %s: redirect URL for additional payment steps. */
					esc_html__( 'Subscription renewal requires redirect: %s', 'straumur-payments-for-woocommerce' ),
					$redirect_url
				)
			);
			$this->logger->info(
				'Subscription payment requires redirect for order ' . $order->get_id(),
				$this->context
			);

			return [
				'result'   => 'success',
				'redirect' => $redirect_url,
			];
		} else {
			$order->update_status(
				'failed',
				esc_html__( 'Token payment failed.', 'straumur-payments-for-woocommerce' )
			);
			$this->logger->error(
				'Subscription payment failed for order ' . $order->get_id()
				. '. Response: ' . wp_json_encode( $response ),
				$this->context
			);

			return [ 'result' => 'failure' ];
		}
	}

	/**
	 * Save the payment method (tokenization) for auto-renewals, if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param int|WC_Order $order The completed order object or order ID.
	 *
	 * @return void
	 */
	public function save_payment_method( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		if ( class_exists( 'WC_Payment_Token_CC' ) ) {
			$token = new \WC_Payment_Token_CC();
		} else {
			$this->logger->error( 'WC_Payment_Token_CC class does not exist.', $this->context );
			return;
		}

		$token->set_gateway_id( $this->id );
		$token->set_token( 'SAVED_TOKEN_FROM_STRAUMUR' );
		$token->set_user_id( $order->get_user_id() );
		$token->set_default( true );
		$token->save();
	}

	/**
	 * Handle the return from Straumur's payment gateway (callback).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function process_return(): void {
		if (
			! isset( $_GET['straumur_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['straumur_nonce'] ) ),
				'straumur_process_return'
			)
		) {
			wp_die( esc_html__( 'Nonce verification failed.', 'straumur-payments-for-woocommerce' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		// Retrieve checkout reference from query string or order meta.
		$checkout_reference = isset( $_GET['checkoutReference'] )
			? sanitize_text_field( wp_unslash( $_GET['checkoutReference'] ) )
			: $order->get_meta( '_straumur_checkout_reference' );

		if ( empty( $checkout_reference ) ) {
			$payment_url = $order->get_checkout_payment_url() ?: wc_get_cart_url();
			wp_safe_redirect( $payment_url );
			exit;
		}

		// If we haven't saved it yet, do so now.
		if ( ! $order->get_meta( '_straumur_checkout_reference' ) ) {
			$order->update_meta_data( '_straumur_checkout_reference', $checkout_reference );
			$order->save();
		}

		// Query Straumur for current payment session status.
		$api             = $this->get_api();
		$status_response = $api->get_session_status( $checkout_reference );

		if ( ! $status_response ) {
			// Could not retrieve status; mark order failed and notify shopper.
			$order->update_status(
				'failed',
				esc_html__( 'Unable to fetch payment status via Straumur.', 'straumur-payments-for-woocommerce' )
			);
			wc_add_notice(
				esc_html__( 'There was an issue retrieving your payment status. Please try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			wp_safe_redirect( $order->get_checkout_payment_url() ?: wc_get_cart_url() );
			exit;
		}

		// If Straumur returned a 'payfacReference', the payment is pending or authorized.
		if ( isset( $status_response['payfacReference'] ) ) {
    $payfac_ref = sanitize_text_field( $status_response['payfacReference'] );
    $order->update_meta_data( '_straumur_payfac_reference', $payfac_ref );
    $order->save();

    // Instead of updating the status, just add an order note.
    $order->add_order_note(
        sprintf(
            esc_html__( 'Payment pending, awaiting confirmation. Straumur Reference: %s', 'straumur-payments-for-woocommerce' ),
            $payfac_ref
        )
    );

    wc_add_notice(
        esc_html__( 'Thank you for your order! Your payment is currently being processed.', 'straumur-payments-for-woocommerce' ),
        'success'
    );

    $redirect_url = ! empty( $this->custom_success_url )
        ? $this->custom_success_url
        : $this->get_return_url( $order );

    wp_safe_redirect( $redirect_url );
    exit;
		} else {
			// Payment not completed or user canceled on Straumur's side.
			wc_add_notice(
				esc_html__( 'Your payment session was not completed. Please try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			wp_safe_redirect( $order->get_checkout_payment_url() ?: wc_get_cart_url() );
			exit;
		}
	}

	/**
	 * Process and save admin options (from the WC settings page).
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function process_admin_options(): bool {
		return parent::process_admin_options();
	}

}
