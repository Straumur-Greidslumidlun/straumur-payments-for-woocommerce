<?php
/**
 * Straumur Kortalán Payment Gateway Class.
 *
 * A second payment option next to Straumur Payments. A shopper who picks it goes straight to
 * the Straumur Kortalán application instead of the Straumur payment page.
 *
 * @package Straumur\Payments
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use WC_Payment_Gateway;
use WC_Logger_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Straumur_Kortalan_Gateway
 *
 * API key, HMAC key and test mode are those of the Straumur Payments gateway, and so is the
 * terminal unless the Kortalán settings name another. The option works with that gateway
 * switched on or off. Kortalán is a loan: there are no subscriptions or saved payment details,
 * nothing to capture later, and refunds are not offered from WooCommerce.
 *
 * @since 2.3.0
 */
class WC_Straumur_Kortalan_Gateway extends WC_Payment_Gateway {

	/**
	 * Logger.
	 *
	 * @var WC_Logger_Interface
	 */
	private $logger;

	/**
	 * Logging context.
	 *
	 * @var array
	 */
	private $context = array( 'source' => 'straumur' );

	/**
	 * Constructor.
	 *
	 * @since 2.3.0
	 */
	public function __construct() {
		$this->logger = wc_get_logger();

		$this->id                 = WC_Straumur_Settings::KORTALAN_GATEWAY_ID;
		$this->method_title       = 'Straumur Kortalán';
		$this->method_description = esc_html__( 'Offer Straumur Kortalán as its own payment option. Shoppers go straight to the Kortalán application.', 'straumur-payments-for-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/images/kortalan.svg';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = WC_Straumur_Settings::is_kortalan_enabled() ? 'yes' : 'no';
		$this->title       = WC_Straumur_Settings::get_kortalan_title();
		$this->description = WC_Straumur_Settings::get_kortalan_description();

		add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_return' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Saving either Straumur settings page asks Straumur again whether the store has Kortalán.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( WC_Straumur_Kortalan::class, 'forget_availability' ), 20 );
		add_action( 'woocommerce_update_options_payment_gateways_straumur', array( WC_Straumur_Kortalan::class, 'forget_availability' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
	}

	/**
	 * Settings fields.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = WC_Straumur_Settings::get_kortalan_form_fields();
	}

	/**
	 * Settings page. The fields are rebuilt first so the connection summary reflects what was
	 * saved in this same request.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function admin_options() {
		$this->init_form_fields();
		parent::admin_options();

		// While the "use the Straumur Payments terminal" box is ticked, the terminal field is disabled and
		// shows that terminal. Unticked, it is editable and shows the Kortalán terminal entered so far.
		$script = sprintf(
			'(function(){var box=document.getElementById(%1$s),field=document.getElementById(%2$s);if(!box||!field){return;}var own=field.value;function show(){if(box.checked){if(!field.disabled){own=field.value;}field.value=field.getAttribute("data-card-terminal")||"";field.disabled=true;}else{field.disabled=false;field.value=own;}}box.addEventListener("change",show);show();})();',
			wp_json_encode( $this->get_field_key( 'inherit_terminal' ) ),
			wp_json_encode( $this->get_field_key( 'terminal_identifier' ) )
		);

		wp_register_script( 'straumur-kortalan-settings', false, array(), STRAUMUR_PAYMENTS_VERSION, true );
		wp_enqueue_script( 'straumur-kortalan-settings' );
		wp_add_inline_script( 'straumur-kortalan-settings', $script );
	}

	/**
	 * Save the "use the Straumur Payments terminal" box.
	 *
	 * Unticking it without entering a usable terminal would leave the option pointing nowhere,
	 * so that is refused and the choice stays as it was saved before.
	 *
	 * @since 2.3.0
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value, null when the box is unticked.
	 * @return string 'yes' or 'no'.
	 */
	public function validate_inherit_terminal_field( $key, $value ) {
		if ( null !== $value ) {
			return 'yes';
		}

		$terminal = $this->get_posted_terminal_identifier();
		// A refused save leaves the choice as it was.
		$previous = 'no' === $this->get_option( 'inherit_terminal', 'yes' ) ? 'no' : 'yes';

		if ( '' === $terminal ) {
			\WC_Admin_Settings::add_error(
				esc_html__( 'Enter the Kortalán Terminal Identifier, or keep using the terminal from the Straumur Payments settings.', 'straumur-payments-for-woocommerce' )
			);

			return $previous;
		}

		if ( ! self::is_plausible_terminal_identifier( $terminal ) ) {
			\WC_Admin_Settings::add_error(
				esc_html__( 'The Kortalán Terminal Identifier cannot contain spaces. Copy it again from the Straumur merchant portal.', 'straumur-payments-for-woocommerce' )
			);

			return $previous;
		}

		return 'no';
	}

	/**
	 * Save the Kortalán terminal identifier.
	 *
	 * The field is disabled, and so not posted, while the "use the Straumur Payments terminal"
	 * box is ticked: the terminal saved earlier is kept, so unticking the box brings it back.
	 * A value the box validation refused is not saved either.
	 *
	 * @since 2.3.0
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_terminal_identifier_field( $key, $value ) {
		$post_data = $this->get_post_data();
		$inherits  = isset( $post_data[ $this->get_field_key( 'inherit_terminal' ) ] );
		$terminal  = $this->get_posted_terminal_identifier();

		if ( $inherits || '' === $terminal || ! self::is_plausible_terminal_identifier( $terminal ) ) {
			return (string) $this->get_option( 'terminal_identifier', '' );
		}

		return $terminal;
	}

	/**
	 * The Kortalán terminal identifier in the posted settings form, trimmed.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	private function get_posted_terminal_identifier(): string {
		$post_data = $this->get_post_data();
		$terminal  = $post_data[ $this->get_field_key( 'terminal_identifier' ) ] ?? '';

		return is_string( $terminal ) ? trim( sanitize_text_field( wp_unslash( $terminal ) ) ) : '';
	}

	/**
	 * Whether a value can be a terminal identifier at all.
	 *
	 * Deliberately not a format check: Straumur may change what identifiers look like. It only
	 * catches what no identifier can be, a value with whitespace in it or an absurdly long one,
	 * which is what a bad paste looks like.
	 *
	 * @since 2.3.0
	 *
	 * @param string $terminal Trimmed value.
	 * @return bool
	 */
	public static function is_plausible_terminal_identifier( string $terminal ): bool {
		return '' !== $terminal && strlen( $terminal ) <= 64 && 1 !== preg_match( '/\s/', $terminal );
	}

	/**
	 * The Straumur Kortalán logo shown next to the option in the classic checkout.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public function get_icon(): string {
		$icon_html = sprintf(
			'<span class="straumur-payment-icons"><img src="%s" alt="%s" /></span>',
			esc_url( $this->icon ),
			esc_attr( 'Straumur Kortalán' )
		);

		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	/**
	 * Load the logo styles on the pages that list payment options.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function enqueue_frontend_styles(): void {
		if ( 'yes' !== $this->enabled || ! ( is_checkout() || is_cart() ) ) {
			return;
		}

		wp_enqueue_style(
			'straumur-payment-method',
			STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/css/straumur-payment-method.css',
			array(),
			STRAUMUR_PAYMENTS_VERSION
		);
	}

	/**
	 * Whether the option is shown at checkout.
	 *
	 * Both the classic and the block checkout list only the gateways for which this is true.
	 * Straumur is asked whether Kortalán is enabled for the terminal's store; the answer is cached.
	 * The basket is not part of the question: an amount Kortalán will not finance is refused when
	 * the payment is started, with a message to the shopper.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( '' === WC_Straumur_Settings::get_api_key() ) {
			return false;
		}

		return WC_Straumur_Kortalan::is_available();
	}

	/**
	 * Start the Kortalán payment and send the shopper to the Kortalán application.
	 *
	 * @since 2.3.0
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( esc_html__( 'Invalid order.', 'straumur-payments-for-woocommerce' ), 'error' );
			$this->logger->error( 'Invalid order: ' . $order_id, $this->context );

			return array( 'result' => 'failure' );
		}

		// A Kortalán loan cannot be authorised now and captured later, whatever the capture setting says.
		$order->update_meta_data( '_straumur_is_manual_capture', 'no' );
		$order->save();

		$amount   = (int) round( (float) $order->get_total() * 100 );
		$currency = (string) $order->get_currency();

		$return_url = add_query_arg(
			array(
				'wc-api'         => $this->id,
				'order_id'       => $order->get_id(),
				'key'            => $order->get_order_key(),
				'straumur_nonce' => wp_create_nonce( 'straumur_process_return' ),
			),
			home_url( '/' )
		);

		// Same handler for both ways back: it asks Straumur what happened and never trusts the URL.
		$abandon_url = add_query_arg( 'straumur_abandoned', '1', $return_url );

		$api     = new WC_Straumur_API();
		$payment = $api->create_kortalan_payment( $amount, $currency, $return_url, $abandon_url, (string) $order->get_id() );

		$checkout_reference = is_array( $payment ) && isset( $payment['checkoutReference'] )
			? sanitize_text_field( (string) $payment['checkoutReference'] )
			: '';
		$redirect_url       = is_array( $payment ) && isset( $payment['url'] )
			? esc_url_raw( (string) $payment['url'], array( 'https', 'http' ) )
			: '';

		if ( '' === $checkout_reference || '' === $redirect_url ) {
			$error_code = $api->get_last_error_code();

			// Straumur says the store does not have Kortalán: the cached yes no longer holds.
			if ( WC_Straumur_Kortalan::ERROR_NOT_CONFIGURED === $error_code ) {
				WC_Straumur_Kortalan::forget_availability();
			}

			wc_add_notice( WC_Straumur_Kortalan::start_error_message( $error_code ), 'error' );
			$this->logger->error(
				sprintf( 'Unable to start Straumur Kortalán payment for order %d. Error code: %s', $order_id, '' !== $error_code ? $error_code : 'none' ),
				$this->context
			);

			return array( 'result' => 'failure' );
		}

		// Remember which payment belongs to this order: process_return() accepts no other.
		$order->update_meta_data( WC_Straumur_Return_Guard::CHECKOUT_REFERENCE_META, $checkout_reference );
		$order->save();

		// The shopper may close the browser in the Kortalán application. Look once early, for a loan
		// that was approved while the webhook was lost, and again after the payment expires.
		WC_Straumur_Kortalan::schedule_check(
			$order->get_id(),
			$checkout_reference,
			WC_Straumur_Kortalan::EARLY_CHECK_DELAY,
			WC_Straumur_Kortalan::EARLY_CHECK
		);
		WC_Straumur_Kortalan::schedule_check(
			$order->get_id(),
			$checkout_reference,
			$api->get_checkout_expiry_seconds() + 5 * MINUTE_IN_SECONDS
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Handle the shopper coming back from the Kortalán application.
	 *
	 * Straumur is asked what happened; nothing in the URL is trusted beyond finding the order,
	 * which takes its key and the payment reference stored on it. When Kortalán approved, the
	 * order is marked paid here, so a shop whose webhook does not arrive is not left with a
	 * thanked shopper and an unpaid order. The webhook then finds it paid.
	 *
	 * The nonce belongs to the browser session that started the payment. A Kortalán application
	 * can end in another browser (phone, electronic ID), where it cannot verify. That shopper is
	 * still sent to the right page, but the order is not written to from such a request: the
	 * webhook or the follow-up check marks it paid.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function process_return(): void {
		$nonce_ok = isset( $_GET['straumur_nonce'] )
			&& false !== wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['straumur_nonce'] ) ),
				'straumur_process_return'
			);

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$order_key     = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$url_reference = isset( $_GET['checkoutReference'] )
			? sanitize_text_field( wp_unslash( $_GET['checkoutReference'] ) )
			: '';

		$checkout_reference = WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order_key, $url_reference, $this->id );

		if ( '' === $checkout_reference ) {
			$this->logger->warning(
				sprintf( 'Return for order %d refused: order key or payment session does not match.', $order_id ),
				$this->context
			);
			wc_add_notice(
				esc_html__( 'Unable to verify payment status. Please try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
			// Not the order's payment page: its URL contains the order key.
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		$api     = new WC_Straumur_API();
		$status  = $api->get_kortalan_status( $checkout_reference );
		$outcome = WC_Straumur_Kortalan::outcome( $status );

		if ( WC_Straumur_Kortalan::OUTCOME_PAID === $outcome ) {
			if ( $nonce_ok ) {
				WC_Straumur_Kortalan::record_paid( $order, WC_Straumur_Kortalan::payfac_reference( $status ) );
			} else {
				$this->logger->info(
					sprintf( 'Return for order %d came from another browser session; the order is left for the webhook to mark paid.', $order_id ),
					$this->context
				);
			}

			wc_add_notice(
				esc_html__( 'Thank you for your order! Your payment is being processed.', 'straumur-payments-for-woocommerce' ),
				'success'
			);

			$custom_success_url = WC_Straumur_Settings::get_custom_success_url();

			wp_safe_redirect( '' !== $custom_success_url ? $custom_success_url : $this->get_return_url( $order ) );
			exit;
		}

		if ( WC_Straumur_Kortalan::OUTCOME_UNKNOWN === $outcome ) {
			wc_add_notice(
				esc_html__( 'We could not confirm your Straumur Kortalán payment yet. If you completed the application, please do not pay again: your order will be updated shortly.', 'straumur-payments-for-woocommerce' ),
				'notice'
			);
		} else {
			wc_add_notice(
				esc_html__( 'Your Straumur Kortalán payment was not completed. Please choose another payment method or try again.', 'straumur-payments-for-woocommerce' ),
				'error'
			);
		}

		$checkout_url = $order->get_checkout_payment_url();
		wp_safe_redirect( $checkout_url ? $checkout_url : wc_get_cart_url() );
		exit;
	}
}
