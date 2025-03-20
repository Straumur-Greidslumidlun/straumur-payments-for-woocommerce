<?php
/**
 * Straumur Webhook Handler Class
 *
 * Handles incoming webhooks from Straumur’s payment system and updates orders accordingly.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function esc_html__;
use function esc_html;
use function sanitize_text_field;
use function sprintf;
use function wp_json_encode;
use function number_format;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure the settings class is available.
if ( ! class_exists( 'Straumur\Payments\WC_Straumur_Settings' ) ) {
	require_once __DIR__ . '/class-wc-straumur-settings.php';
}

/**
 * Class WC_Straumur_Webhook_Handler
 *
 * Manages registration of the webhook REST route and processes the incoming Straumur payloads.
 *
 * @since 1.0.0
 */
class WC_Straumur_Webhook_Handler {

	/**
	 * Initialize the webhook routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Register custom REST API routes for Straumur webhooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'straumur/v1',
			'/payment-callback',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_payment_callback' ],
				'permission_callback' => [ self::class, 'check_webhook_hmac' ],
			]
		);
	}

	/**
	 * Permission callback for verifying the HMAC signature.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return bool True if signature is valid, false otherwise.
	 */
	public static function check_webhook_hmac( WP_REST_Request $request ): bool {
		$body = $request->get_body();
		self::log_message( 'Incoming webhook: ' . $body );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			self::log_message( esc_html__( 'Invalid JSON.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}

		$signature = $data['hmacSignature'] ?? '';
		if ( empty( $signature ) ) {
			self::log_message( esc_html__( 'No hmacSignature provided.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}

		return self::validate_hmac_signature( $data, $signature );
	}

	/**
	 * Process the Straumur payment callback after HMAC validation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response Response with status 200.
	 */
	public static function handle_payment_callback( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_body();
		$data = json_decode( $body, true );

		// If success is explicitly false, treat it as a failed transaction.
		if ( isset( $data['success'] ) && 'false' === $data['success'] ) {
			self::handle_failed_webhook( $data );
			return new WP_REST_Response( null, 200 );
		}

		self::process_webhook_data( $data );
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Handle a failed transaction event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Decoded JSON payload from Straumur.
	 * @return void
	 */
	private static function handle_failed_webhook( array $data ): void {
		$order_id = isset( $data['merchantReference'] ) ? (int) $data['merchantReference'] : 0;
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$payfac_reference = $data['payfacReference'] ?? '';
		$reason           = $data['reason'] ?? esc_html__( 'Transaction failed', 'straumur-payments-for-woocommerce' );

		if ( 0 === strcasecmp( 'Refused', $reason ) ) {
			$note = esc_html__( 'Payment declined: The card was refused (declined).', 'straumur-payments-for-woocommerce' );
		} elseif ( 0 === strcasecmp( 'Expired Card', $reason ) ) {
			$note = esc_html__( 'Payment failed: The card has expired.', 'straumur-payments-for-woocommerce' );
		} elseif ( 0 === strcasecmp( '3D Not Authenticated', $reason ) ) {
			$note = esc_html__( 'Payment failed: 3D Secure verification failed.', 'straumur-payments-for-woocommerce' );
		} else {
			$event_type = strtolower( $data['additionalData']['eventType'] ?? 'unknown' );
			/* translators: 1: event type, 2: reason text, 3: payfac reference */
			$note = sprintf(
				esc_html__( 'Straumur %1$s failed: %2$s. Reference: %3$s', 'straumur-payments-for-woocommerce' ),
				esc_html( ucfirst( $event_type ) ),
				esc_html( $reason ),
				esc_html( $payfac_reference )
			);
		}

		$order->add_order_note( $note );
		$order->save();
		self::log_message( sprintf( 'Handled failed webhook for order %d: %s', $order_id, $note ) );
	}

	/**
	 * Process a successful (or partially successful) Straumur webhook.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Decoded JSON payload.
	 * @return void
	 */
	private static function process_webhook_data( array $data ): void {
		$order_id = isset( $data['merchantReference'] ) ? (int) $data['merchantReference'] : 0;
		if ( $order_id <= 0 ) {
			self::log_message( esc_html__( 'No merchantReference or invalid.', 'straumur-payments-for-woocommerce' ) );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::log_message(
				sprintf(
					esc_html__( 'No order found for merchantReference: %d', 'straumur-payments-for-woocommerce' ),
					$order_id
				)
			);
			return;
		}

		// If it's a tokenization event, handle the payment token save.
		if (
			isset( $data['additionalData']['eventType'] )
			&& 'tokenization' === strtolower( $data['additionalData']['eventType'] )
			&& ! empty( $data['additionalData']['token'] )
		) {
			self::save_token_data( $order, $data );
		}

		$payfac_reference = $data['payfacReference'] ?? '';
		$event_type       = strtolower( $data['additionalData']['eventType'] ?? 'unknown' );
		$original_payfac  = $data['additionalData']['originalPayfacReference'] ?? '';
		$raw_amount       = isset( $data['amount'] ) ? (int) $data['amount'] : 0;
		$event_key        = implode( ':', [ $payfac_reference, $event_type, $original_payfac, (string) $raw_amount ] );

		if ( self::is_already_processed( $order, $event_key ) ) {
			self::log_message( sprintf( 'Duplicate webhook event key "%s" - skipping', $event_key ) );
			return;
		}

		self::mark_as_processed( $order, $event_key );
		$order->update_meta_data( '_straumur_last_webhook', wp_json_encode( $data ) );

		$currency       = $data['currency'] ?? '';
		$display_amount = self::format_amount( $raw_amount, $currency );
		$order->save();

		$auth_code   = $data['additionalData']['authCode'] ?? '';
		$card_number = $data['additionalData']['cardNumber'] ?? '';
		$three_d_auth = $data['additionalData']['threeDAuthenticated'] ?? 'false';
		$three_d_text = ( 'true' === $three_d_auth )
			? esc_html__( 'verified by 3D Secure', 'straumur-payments-for-woocommerce' )
			: esc_html__( 'not verified by 3D Secure', 'straumur-payments-for-woocommerce' );

		// Determine if the merchant configured manual capture.
		$manual_capture = ( 'yes' === $order->get_meta( '_straumur_is_manual_capture' ) );

		switch ( $event_type ) {
			case 'authorization':
				if ( self::is_order_already_paid( $order ) ) {
					self::log_message(
						esc_html__( 'Straumur authorization ignored: order already paid.', 'straumur-payments-for-woocommerce' )
					);
					break;
				}

				if ( ! $manual_capture ) {
					self::handle_authorization_auto_capture( $order, $display_amount, $card_number, $three_d_text, $auth_code );
				} else {
					self::handle_authorization_manual_capture( $order, $display_amount, $card_number, $three_d_text, $auth_code );
				}
				break;

			case 'refund':
				if ( 'yes' === $order->get_meta( '_straumur_refund_requested' ) ) {
					self::handle_refund( $order, $display_amount, $payfac_reference );
				} elseif ( 'yes' === $order->get_meta( '_straumur_cancel_requested' ) ) {
					self::handle_cancellation( $order, $payfac_reference );
				} else {
					/* translators: %s: formatted refund or cancellation amount */
					$order->add_order_note(
						sprintf(
							esc_html__( 'Straumur refund/cancellation %s (unknown type)', 'straumur-payments-for-woocommerce' ),
							esc_html( $display_amount )
						)
					);
				}
				break;

			case 'capture':
				if ( self::is_order_already_paid( $order ) ) {
					self::log_message(
						esc_html__( 'Straumur capture ignored: order already paid.', 'straumur-payments-for-woocommerce' )
					);
					break;
				}
				self::handle_capture_event( $order, $display_amount, $payfac_reference );
				break;

			case 'tokenization':
				$card_summary = sanitize_text_field( $data['additionalData']['cardSummary'] ?? '' );
				$auth_code    = sanitize_text_field( $data['additionalData']['authCode'] ?? '' );
				$three_d_auth = sanitize_text_field( $data['additionalData']['threeDAuthenticated'] ?? 'false' );
				$three_d_text = ( 'true' === $three_d_auth )
					? esc_html__( 'verified by 3D Secure', 'straumur-payments-for-woocommerce' )
					: esc_html__( 'not verified by 3D Secure', 'straumur-payments-for-woocommerce' );

				/* translators: 1: last four digits of the card, 2: 3D Secure status text, 3: authorization code */
				$note = sprintf(
					esc_html__( 'Card ending in %1$s has been saved for automatic subscription payments, %2$s (Auth code: %3$s).', 'straumur-payments-for-woocommerce' ),
					esc_html( $card_summary ),
					esc_html( $three_d_text ),
					esc_html( $auth_code )
				);
				$order->add_order_note( $note );
				break;

			default:
				$order->add_order_note(
					esc_html__( 'Unknown Straumur event type received.', 'straumur-payments-for-woocommerce' )
				);
				break;
		}

		$order->save();
		self::log_message( sprintf( 'Order %d updated with Straumur webhook data.', $order->get_id() ) );
	}

	/**
	 * Save the token data from a tokenization event.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order The completed order object.
	 * @param array     $data  Decoded JSON payload.
	 * @return void
	 */
	private static function save_token_data( $order, array $data ): void {
		$token_value = sanitize_text_field( $data['additionalData']['token'] );
		$user_id     = $order->get_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( class_exists( '\WC_Payment_Token_CC' ) ) {
			$token     = new \WC_Payment_Token_CC();
			$card_type = sanitize_text_field( $data['additionalData']['paymentMethod'] ?? '' );
			$token->set_card_type( $card_type );

			$last4 = sanitize_text_field( $data['additionalData']['cardSummary'] ?? '' );
			$token->set_last4( $last4 );

			// Attempt to parse the card expiry from the "reason" field if present.
			$expiry_month = '00';
			$expiry_year  = '00';
			if ( ! empty( $data['reason'] ) ) {
				$parts = explode( ':', $data['reason'] );
				if ( count( $parts ) >= 3 ) {
					$expiry_parts = explode( '/', $parts[2] );
					if ( 2 === count( $expiry_parts ) ) {
						$expiry_month = trim( $expiry_parts[0] );
						$expiry_year  = trim( $expiry_parts[1] );
					}
				}
			}

			$token->set_expiry_month( $expiry_month );
			$token->set_expiry_year( $expiry_year );
		} else {
			// Fallback if \WC_Payment_Token_CC is not available.
			$token = new \WC_Payment_Token();
		}

		$token->set_gateway_id( 'straumur' );
		$token->set_token( $token_value );
		$token->set_user_id( $user_id );
		$token->set_default( true );
		$token->update_meta_data( 'subscription_only', 'yes' );
		$token->save();

		update_user_meta( $user_id, '_straumur_payment_token', $token->get_id() );

		self::log_message(
			sprintf(
				'Saved token for user %d: %s (Exp: %s/%s)',
				$user_id,
				$token_value,
				$token->get_expiry_month(),
				$token->get_expiry_year()
			)
		);
	}

	/**
	 * Check if an event key has already been processed for this order.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order     The order object.
	 * @param string    $event_key A unique string for this event.
	 * @return bool True if it was already processed, false otherwise.
	 */
	private static function is_already_processed( $order, string $event_key ): bool {
		$processed = $order->get_meta( '_straumur_processed_webhooks', true );
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}
		return in_array( $event_key, $processed, true );
	}

	/**
	 * Mark this webhook event as processed to avoid duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order     The order object.
	 * @param string    $event_key A unique string for this event.
	 * @return void
	 */
	private static function mark_as_processed( $order, string $event_key ): void {
		$processed = $order->get_meta( '_straumur_processed_webhooks', true );
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}
		$processed[] = $event_key;
		$order->update_meta_data( '_straumur_processed_webhooks', $processed );
		$order->save();
	}

	/**
	 * Determine if the order has already been marked paid (processing or completed).
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order The order object.
	 * @return bool True if paid, false otherwise.
	 */
	private static function is_order_already_paid( $order ): bool {
		return $order->has_status( [ 'processing', 'completed' ] );
	}

	/**
	 * Format a numeric amount (in minor units) with currency.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $raw_amount Minor units.
	 * @param string $currency   Currency code (e.g., "ISK", "USD").
	 * @return string Readable amount with currency code, or "N/A" if invalid.
	 */
	private static function format_amount( int $raw_amount, string $currency ): string {
		if ( $raw_amount <= 0 ) {
			return esc_html__( 'N/A', 'straumur-payments-for-woocommerce' );
		}
		if ( 'ISK' === $currency ) {
			// ISK typically has zero decimal places.
			return number_format( $raw_amount / 100, 0, ',', '.' ) . ' ISK';
		}
		// Default to two decimal places for other currencies.
		return number_format( $raw_amount / 100, 2, '.', '' ) . ' ' . $currency;
	}

	/**
	 * Conditionally mark an order as paid (processing or completed).
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order The order object.
	 * @param string    $note  Reason note to add to the order status update.
	 * @return void
	 */
	private static function maybe_mark_order_paid( $order, string $note ): void {
		$mark_as_complete = WC_Straumur_Settings::is_complete_order_on_payment();
		if ( $mark_as_complete || ! $order->needs_processing() ) {
			$order->update_status( 'completed', $note );
		} else {
			$order->update_status( 'processing', $note );
		}
	}

	/**
	 * Handle an authorization event with auto-capture.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order          The order object.
	 * @param string    $display_amount Formatted authorized amount.
	 * @param string    $card_number    Masked card number.
	 * @param string    $three_d_text   Text describing 3D Secure state.
	 * @param string    $auth_code      Authorization code.
	 * @return void
	 */
	private static function handle_authorization_auto_capture(
		$order,
		string $display_amount,
		string $card_number,
		string $three_d_text,
		string $auth_code
	): void {
		/* translators: 1: authorized amount, 2: masked card number, 3: 3D Secure text, 4: auth code */
		$note = sprintf(
			esc_html__( '%1$s was authorized to card %2$s, %3$s. Auth code: %4$s. Payment captured automatically.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $card_number ),
			esc_html( $three_d_text ),
			esc_html( $auth_code )
		);
		self::maybe_mark_order_paid( $order, $note );
	}

	/**
	 * Handle an authorization event set for manual capture.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order          The order object.
	 * @param string    $display_amount Formatted authorized amount.
	 * @param string    $card_number    Masked card number.
	 * @param string    $three_d_text   Text describing 3D Secure state.
	 * @param string    $auth_code      Authorization code.
	 * @return void
	 */
	private static function handle_authorization_manual_capture(
		$order,
		string $display_amount,
		string $card_number,
		string $three_d_text,
		string $auth_code
	): void {
		/* translators: 1: authorized amount, 2: masked card number, 3: 3D Secure text, 4: auth code */
		$note = sprintf(
			esc_html__( '%1$s was authorized to card %2$s, %3$s. Auth code: %4$s. Awaiting manual capture.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $card_number ),
			esc_html( $three_d_text ),
			esc_html( $auth_code )
		);
		$order->update_status( 'on-hold', $note );
	}

	/**
	 * Handle a capture event from Straumur (e.g., after manual capture).
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order           The order object.
	 * @param string    $display_amount  Formatted capture amount.
	 * @param string    $payfac_reference Payfac reference.
	 * @return void
	 */
	private static function handle_capture_event( $order, string $display_amount, string $payfac_reference ): void {
		/* translators: 1: captured amount, 2: payfac reference ID */
		$note = sprintf(
			esc_html__( 'Manual capture completed for %1$s via Straumur (reference: %2$s).', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $payfac_reference )
		);
		self::maybe_mark_order_paid( $order, $note );
	}

	/**
	 * Handle a confirmed cancellation event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order            The order object.
	 * @param string    $payfac_reference Payfac reference.
	 * @return void
	 */
	private static function handle_cancellation( $order, string $payfac_reference ): void {
		/* translators: %s: payfac reference */
		$note = sprintf(
			esc_html__( 'Cancellation confirmed by Straumur. Reference: %s.', 'straumur-payments-for-woocommerce' ),
			esc_html( $payfac_reference )
		);
		$order->add_order_note( $note );
		$order->delete_meta_data( '_straumur_cancel_requested' );
	}

	/**
	 * Handle a confirmed refund event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order            The order object.
	 * @param string    $display_amount   Formatted refunded amount.
	 * @param string    $payfac_reference Payfac reference.
	 * @return void
	 */
	private static function handle_refund( $order, string $display_amount, string $payfac_reference ): void {
		/* translators: 1: refunded amount, 2: payfac reference ID */
		$note = sprintf(
			esc_html__( 'A refund amount of %1$s has been processed by Straumur. Reference: %2$s.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $payfac_reference )
		);
		$order->add_order_note( $note );
		$order->delete_meta_data( '_straumur_refund_requested' );
	}

	/**
	 * Validate the HMAC signature in the webhook payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      The decoded JSON payload.
	 * @param string $signature The base64-encoded signature.
	 * @return bool True if valid, false otherwise.
	 */
	private static function validate_hmac_signature( array $data, string $signature ): bool {
		$hmac_key = WC_Straumur_Settings::get_hmac_key();
		if ( empty( $hmac_key ) ) {
			self::log_message( esc_html__( 'No HMAC secret configured in settings.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}

		$values = [
			$data['checkoutReference'] ?? '',
			$data['payfacReference']   ?? '',
			$data['merchantReference'] ?? '',
			$data['amount']            ?? '',
			$data['currency']          ?? '',
			$data['reason']            ?? '',
			$data['success']           ?? '',
		];
		$payload     = implode( ':', $values );
		$binary_key  = @hex2bin( $hmac_key );
		if ( false === $binary_key ) {
			self::log_message( esc_html__( 'Invalid HMAC key configured in settings.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}
		$computed_hash      = hash_hmac( 'sha256', $payload, $binary_key, true );
		$computed_signature = base64_encode( $computed_hash );

		return hash_equals( $computed_signature, $signature );
	}

	/**
	 * Log a message if WP_DEBUG is enabled, using WooCommerce's logger.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Debug or error message to log.
	 * @return void
	 */
	private static function log_message( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->debug( $message, [ 'source' => 'straumur_webhook' ] );
		}
	}
}

// Initialize the handler.
WC_Straumur_Webhook_Handler::init();
