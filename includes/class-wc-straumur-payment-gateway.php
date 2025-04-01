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
use WC_Logger_Interface;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WC_ABSPATH' ) ) {
	define( 'WC_ABSPATH', WP_PLUGIN_DIR . '/woocommerce/' );
}

if ( ! defined( 'STRAUMUR_PAYMENTS_PLUGIN_URL' ) ) {
	define( 'STRAUMUR_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

class WC_Straumur_Payment_Gateway extends WC_Payment_Gateway {

	private WC_Logger_Interface $logger;

	private string $terminal_identifier;
	private string $gateway_terminal_identifier;
	private string $api_key;
	private string $theme_key;
	private bool $authorize_only;
	private string $hmac_key;
	private bool $send_items;
	private string $abandon_url;
	private string $custom_success_url;

	private array $context = array( 'source' => 'straumur' );

	public function __construct() {
		$this->logger = wc_get_logger();

		$this->id                 = 'straumur';
		$this->method_title       = esc_html__( 'Straumur Payments', 'straumur-payments-for-woocommerce' );
		$this->method_description = esc_html__( 'Accept payments via Straumur Hosted Checkout.', 'straumur-payments-for-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/images/straumur-28x28.png';

		$this->supports = array(
			'products',
			'subscriptions',
			'wc-blocks',
			'wc-orders',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'multiple_subscriptions',
		);

		$this->init_form_fields();
		$this->init_settings_values();

		add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_return' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action( 'woocommerce_scheduled_subscription_payment_straumur', array( $this, 'process_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_subscription_payment_method_updated_to_straumur', array( $this, 'process_subscription_payment_method_change' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = WC_Straumur_Settings::get_form_fields();
	}

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
	 * Returns an instance of the Straumur API class.
	 *
	 * @return WC_Straumur_API
	 */
	private function get_api(): WC_Straumur_API {
		// ADDED
		// Pass $this->authorize_only if you need manual-capture logic at the API level:
		return new WC_Straumur_API( $this->authorize_only );
	}

	private function is_subscription_renewal_checkout( int $order_id ): bool {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return false;
		}

		return wcs_order_contains_renewal( $order_id ) || wcs_cart_contains_renewal();
	}

	private function get_order_items( WC_Order $order, int $expected_amount ): array {
		$items            = array();
		$calculated_total = 0;

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$line_total        = (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 );
			$items[]           = array(
				'Name'   => $item->get_name(),
				'Amount' => $line_total,
			);
			$calculated_total += $line_total;
		}

		if ( $order->get_shipping_total() > 0 ) {
			$shipping_cost     = (int) round( ( $order->get_shipping_total() + $order->get_shipping_tax() ) * 100 );
			$items[]           = array(
				'Name'   => esc_html__( 'Delivery', 'straumur-payments-for-woocommerce' ),
				'Amount' => $shipping_cost,
			);
			$calculated_total += $shipping_cost;
		}

		$difference = $expected_amount - $calculated_total;
		if ( $difference !== 0 && ! empty( $items ) ) {
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

			return array( 'result' => 'failure' );
		}

		// Use get_api() to obtain the API instance
		$api = $this->get_api();

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
			array(
				'wc-api'         => $this->id,
				'order_id'       => $order->get_id(),
				'straumur_nonce' => wp_create_nonce( 'straumur_process_return' ),
			),
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

			return array( 'result' => 'failure' );
		}

		// Straumur returns the Hosted Checkout URL.
		$redirect_url = $session['url'];

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Process a subscription payment (renewal) using the saved token.
	 *
	 * Uses the stored token to process automatic renewal payments for subscriptions.
	 * Handles different response scenarios including authorization, redirects, and failures.
	 *
	 * @since 1.0.0
	 *
	 * @param float    $amount The amount to be charged.
	 * @param WC_Order $order  The order object (renewal order).
	 *
	 * @return array Response with result status and redirect URL if needed.
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

			return array( 'result' => 'failure' );
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
			array(
				'wc-api'         => $this->id,
				'order_id'       => $order->get_id(),
				'straumur_nonce' => wp_create_nonce( 'straumur_process_return' ),
			),
			home_url( '/' )
		);

		// Process token payment via API (uses get_api()).
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
			// Save the payfacReference for future refund/capture operations
			if ( ! empty( $response['payfacReference'] ) ) {
				$payfac_reference = sanitize_text_field( $response['payfacReference'] );
				$order->update_meta_data( '_straumur_payfac_reference', $payfac_reference );
				$order->save();

				$this->logger->info(
					sprintf(
						'Saved payfacReference %s for subscription payment on order %d',
						$payfac_reference,
						$order->get_id()
					),
					$this->context
				);
			} else {
				$this->logger->warning(
					sprintf(
						'No payfacReference received for authorized subscription payment on order %d',
						$order->get_id()
					),
					$this->context
				);
			}

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

			return array( 'result' => 'success' );
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

			return array(
				'result'   => 'success',
				'redirect' => $redirect_url,
			);
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

			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Save the payment method (tokenization) for auto-renewals, if needed.
	 *
	 * Creates and stores a payment token for future subscription renewal payments.
	 * Always marks tokens as subscription_only since they're only used for recurring payments.
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
		$token->update_meta_data( 'subscription_only', 'yes' );

		$token->save();
	}

	/**
	 * Handle the return from Straumur's payment gateway
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

		$checkout_reference = isset( $_GET['checkoutReference'] )
			? sanitize_text_field( wp_unslash( $_GET['checkoutReference'] ) )
			: $order->get_meta( '_straumur_checkout_reference' );

		if ( empty( $checkout_reference ) ) {
			$checkout_url = $order->get_checkout_payment_url();
			wp_safe_redirect( $checkout_url ? $checkout_url : wc_get_cart_url() );
			exit;
		}

		if ( ! $order->get_meta( '_straumur_checkout_reference' ) ) {
			$order->update_meta_data( '_straumur_checkout_reference', $checkout_reference );
			$order->save();
		}

		$api             = $this->get_api();
		$status_response = $api->get_session_status( $checkout_reference );

		if ( ! $status_response ) {
			wc_add_notice(
				esc_html__( 'Unable to verify payment status. Please try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			$checkout_url = $order->get_checkout_payment_url();
			wp_safe_redirect( $checkout_url ? $checkout_url : wc_get_cart_url() );
			exit;
		}

		if ( isset( $status_response['payfacReference'] ) ) {
			$payfac_ref = sanitize_text_field( $status_response['payfacReference'] );
			$order->update_meta_data( '_straumur_payfac_reference', $payfac_ref );
			$order->save();

			wc_add_notice(
				esc_html__( 'Thank you for your order! Your payment is being processed.', 'straumur-payments-for-woocommerce' ),
				'success'
			);

			$redirect_url = ! empty( $this->custom_success_url )
				? $this->custom_success_url
				: $this->get_return_url( $order );

			wp_safe_redirect( $redirect_url );
			exit;
		} else {
			wc_add_notice(
				esc_html__( 'Your payment session was not completed. Please try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			$checkout_url = $order->get_checkout_payment_url();
			wp_safe_redirect( $checkout_url ? $checkout_url : wc_get_cart_url() );
			exit;
		}
	}
}
