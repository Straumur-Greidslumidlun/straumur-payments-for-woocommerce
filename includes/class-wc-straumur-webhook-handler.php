<?php

/**
 * Straumur Webhook Handler Class
 *
 * Handles incoming webhooks from Straumur's payment system and updates orders accordingly.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

use function esc_html__;
use function esc_html;
use function sanitize_text_field;
use function sprintf;
use function wp_json_encode;
use function number_format;
use function absint;

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
	 * Webhook event types
	 */
	public const EVENT_AUTHORIZATION = 'authorization';
	public const EVENT_CAPTURE       = 'capture';
	public const EVENT_REFUND        = 'refund';
	public const EVENT_TOKENIZATION  = 'tokenization';

	/**
	 * Initialize the webhook routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
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
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_payment_callback' ),
				'permission_callback' => array( self::class, 'check_webhook_hmac' ),
			)
		);
	}

	/**
	 * Permission callback for verifying the HMAC signature.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return true|WP_Error True if signature is valid, WP_Error otherwise.
	 */
	public static function check_webhook_hmac( WP_REST_Request $request ) {
		$body = $request->get_body();

		// Only log a redacted version of the payload without sensitive data
		$log_data = json_decode( $body, true );
		if ( is_array( $log_data ) ) {
			if ( isset( $log_data['additionalData']['cardNumber'] ) ) {
				$log_data['additionalData']['cardNumber'] = '[REDACTED]';
			}
			if ( isset( $log_data['additionalData']['token'] ) ) {
				$log_data['additionalData']['token'] = '[REDACTED]';
			}
			self::log_message( 'Incoming webhook: ' . wp_json_encode( $log_data ) );
		} else {
			self::log_message( 'Incoming webhook: Invalid JSON payload' );
		}

		// Parse JSON payload
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			self::log_message( esc_html__( 'Invalid JSON payload.', 'straumur-payments-for-woocommerce' ) );
			return new WP_Error(
				'straumur_invalid_json',
				esc_html__( 'Invalid JSON payload.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// Verify required fields exist
		$required_fields = array( 'hmacSignature', 'checkoutReference', 'payfacReference', 'merchantReference' );
		foreach ( $required_fields as $field ) {
			if ( empty( $data[ $field ] ) ) {
				self::log_message(
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Required field missing: %s.', 'straumur-payments-for-woocommerce' ),
						$field
					)
				);
				return new WP_Error(
					'straumur_missing_field',
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Required field missing: %s.', 'straumur-payments-for-woocommerce' ),
						$field
					),
					array( 'status' => 400 )
				);
			}
		}

		$signature = $data['hmacSignature'];

		// Validate the HMAC signature
		$is_valid = self::validate_hmac_signature( $data, $signature );

		if ( ! $is_valid ) {
			return new WP_Error(
				'straumur_invalid_signature',
				esc_html__( 'Invalid HMAC signature.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Process the Straumur payment callback after HMAC validation.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request {
	 *     The incoming REST request. Expects a JSON body with:
	 *     @type string $hmacSignature      Base64-encoded signature.
	 *     @type string $merchantReference  The WooCommerce order ID.
	 *     @type bool   $success            Indicates if the payment succeeded.
	 *     @type array  $additionalData     Additional event data from Straumur.
	 * }
	 * @return WP_REST_Response|WP_Error Response with appropriate status.
	 */
	public static function handle_payment_callback( WP_REST_Request $request ) {
		$body = $request->get_body();
		$data = json_decode( $body, true );

		// Process in a try-catch block to ensure we catch all exceptions
		try {
			// Process with a database transaction if available
			global $wpdb;
			$transaction_started = false;

			if ( method_exists( $wpdb, 'query' ) && ! defined( 'WP_DEBUG' ) && function_exists( 'wc_transaction_query' ) ) {
				wc_transaction_query( 'start' );
				$transaction_started = true;
			}

			// If success is explicitly false, treat it as a failed transaction
			if ( isset( $data['success'] ) && ( 'false' === $data['success'] || false === $data['success'] ) ) {
				$result = self::handle_failed_webhook( $data );

				if ( is_wp_error( $result ) ) {
					if ( $transaction_started ) {
						wc_transaction_query( 'rollback' );
					}
					return $result;
				}

				if ( $transaction_started ) {
					wc_transaction_query( 'commit' );
				}

				return new WP_REST_Response(
					array( 'message' => esc_html__( 'Failed payment processed successfully.', 'straumur-payments-for-woocommerce' ) ),
					200
				);
			}

			// Process the webhook for successful payments
			$result = self::process_webhook_data( $data );

			if ( is_wp_error( $result ) ) {
				if ( $transaction_started ) {
					wc_transaction_query( 'rollback' );
				}
				return $result;
			}

			if ( $transaction_started ) {
				wc_transaction_query( 'commit' );
			}

			return new WP_REST_Response(
				array( 'message' => esc_html__( 'Webhook processed successfully.', 'straumur-payments-for-woocommerce' ) ),
				200
			);

		} catch ( \Exception $e ) {
			self::log_message( 'Exception processing webhook: ' . $e->getMessage() );

			if ( isset( $transaction_started ) && $transaction_started ) {
				wc_transaction_query( 'rollback' );
			}

			return new WP_Error(
				'straumur_processing_error',
				esc_html__( 'Error processing webhook.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Handle a failed transaction event from Straumur.
	 *
	 * Expects $data to contain at least 'merchantReference', 'payfacReference', and 'reason'.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data {
	 *     Decoded JSON payload from Straumur.
	 *
	 *     @type string $merchantReference WooCommerce order ID.
	 *     @type string $payfacReference   Payfac reference for the transaction.
	 *     @type string $reason            Reason for failure, e.g. 'Refused', 'Expired Card'.
	 *     @type mixed  $additionalData    Additional optional data.
	 * }
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function handle_failed_webhook( array $data ) {
		// Validate the merchant reference (order ID)
		$order_id = isset( $data['merchantReference'] ) ? absint( $data['merchantReference'] ) : 0;
		if ( $order_id <= 0 ) {
			self::log_message( esc_html__( 'Invalid or missing order ID in failed webhook.', 'straumur-payments-for-woocommerce' ) );
			return new WP_Error(
				'straumur_invalid_order',
				esc_html__( 'Invalid or missing order ID.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// Get the order
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::log_message(
				sprintf(
					/* translators: %d: order ID */
					esc_html__( 'Order %d not found for failed webhook.', 'straumur-payments-for-woocommerce' ),
					$order_id
				)
			);
			return new WP_Error(
				'straumur_order_not_found',
				esc_html__( 'Order not found.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		// Get payment details
		$payfac_reference = isset( $data['payfacReference'] ) ? sanitize_text_field( $data['payfacReference'] ) : '';
		$reason           = isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : esc_html__( 'Transaction failed', 'straumur-payments-for-woocommerce' );
		$additional_data  = isset( $data['additionalData'] ) && is_array( $data['additionalData'] ) ? $data['additionalData'] : array();
		$event_type       = isset( $additional_data['eventType'] ) ? sanitize_text_field( strtolower( $additional_data['eventType'] ) ) : 'unknown';

		// Create failure note based on reason
		if ( 0 === strcasecmp( 'Refused', $reason ) ) {
			$note = esc_html__( 'Payment declined: The card was refused (declined).', 'straumur-payments-for-woocommerce' );
		} elseif ( 0 === strcasecmp( 'Expired Card', $reason ) ) {
			$note = esc_html__( 'Payment failed: The card has expired.', 'straumur-payments-for-woocommerce' );
		} elseif ( 0 === strcasecmp( '3D Not Authenticated', $reason ) ) {
			$note = esc_html__( 'Payment failed: 3D Secure verification failed.', 'straumur-payments-for-woocommerce' );
		} else {
			/* translators: 1: event type, 2: reason text, 3: payfac reference */
			$note = sprintf(
				/* translators: 1: event type, 2: reason text, 3: payfac reference */
				esc_html__( 'Straumur %1$s failed: %2$s. Reference: %3$s', 'straumur-payments-for-woocommerce' ),
				esc_html( ucfirst( $event_type ) ),
				esc_html( $reason ),
				esc_html( $payfac_reference )
			);
		}

		// Store fail data as order meta for future reference
		$order->update_meta_data(
			'_straumur_last_failure',
			wp_json_encode(
				array(
					'timestamp'        => current_time( 'mysql' ),
					'reason'           => $reason,
					'payfac_reference' => $payfac_reference,
					'event_type'       => $event_type,
				)
			)
		);

		// Add the failure note to the order
		$order->add_order_note( $note );
		$order->save();

		self::log_message( sprintf( 'Handled failed webhook for order %d: %s', $order_id, $note ) );

		return true;
	}

	/**
	 * Process a successful (or partially successful) Straumur webhook.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Decoded JSON payload.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function process_webhook_data( array $data ) {
		// Validate the merchant reference (order ID)
		$order_id = isset( $data['merchantReference'] ) ? absint( $data['merchantReference'] ) : 0;
		if ( $order_id <= 0 ) {
			self::log_message( esc_html__( 'No merchantReference or invalid.', 'straumur-payments-for-woocommerce' ) );
			return new WP_Error(
				'straumur_invalid_order',
				esc_html__( 'Invalid or missing order ID.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// Get the order
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::log_message(
				sprintf(
					/* translators: %d: order ID */
					esc_html__( 'No order found for merchantReference: %d', 'straumur-payments-for-woocommerce' ),
					$order_id
				)
			);
			return new WP_Error(
				'straumur_order_not_found',
				esc_html__( 'Order not found.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		// Get payment details
		$additional_data  = isset( $data['additionalData'] ) && is_array( $data['additionalData'] ) ? $data['additionalData'] : array();
		$event_type       = isset( $additional_data['eventType'] ) ? sanitize_text_field( strtolower( $additional_data['eventType'] ) ) : 'unknown';
		$payfac_reference = isset( $data['payfacReference'] ) ? sanitize_text_field( $data['payfacReference'] ) : '';
		$original_payfac  = isset( $additional_data['originalPayfacReference'] ) ? sanitize_text_field( $additional_data['originalPayfacReference'] ) : '';
		$raw_amount       = isset( $data['amount'] ) ? absint( $data['amount'] ) : 0;

		// Create a unique event identifier using SHA-256 hash of key components
		$event_data = array(
			'payfacReference'         => $payfac_reference,
			'eventType'               => $event_type,
			'originalPayfacReference' => $original_payfac,
			'amount'                  => $raw_amount,
			'timestamp'               => isset( $data['timestamp'] ) ? sanitize_text_field( $data['timestamp'] ) : '',
		);
		$event_key  = hash( 'sha256', wp_json_encode( $event_data ) );

		// Check if this event was already processed
		if ( self::is_already_processed( $order, $event_key ) ) {
			self::log_message( sprintf( 'Duplicate webhook event key "%s" - skipping', $event_key ) );
			return true; // Successfully ignored duplicate
		}

		// If it's a tokenization event, handle the payment token save
		if ( self::EVENT_TOKENIZATION === $event_type && ! empty( $additional_data['token'] ) ) {
			$token_result = self::save_token_data( $order, $data );
			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}
		}

		// Mark as processed and store webhook data
		self::mark_as_processed( $order, $event_key );

		// Store a sanitized version of the webhook data
		$sanitized_data = self::sanitize_webhook_data( $data );
		$order->update_meta_data( '_straumur_last_webhook', wp_json_encode( $sanitized_data ) );

		// Format amount for display
		$currency       = isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : '';
		$display_amount = self::format_amount( $raw_amount, $currency );

		// Process based on event type
		switch ( $event_type ) {
			case self::EVENT_AUTHORIZATION:
				return self::handle_authorization_event( $order, $data, $display_amount );

			case self::EVENT_REFUND:
				return self::handle_refund_event( $order, $data, $display_amount, $payfac_reference );

			case self::EVENT_CAPTURE:
				return self::handle_capture_event( $order, $display_amount, $payfac_reference );

			case self::EVENT_TOKENIZATION:
				return self::handle_tokenization_event( $order, $data );

			default:
				$order->add_order_note(
					sprintf(
						/* translators: %s: event type */
						esc_html__( 'Unknown Straumur event type received: %s.', 'straumur-payments-for-woocommerce' ),
						esc_html( $event_type )
					)
				);
				$order->save();

				self::log_message(
					sprintf(
						'Unknown event type "%s" for order %d',
						$event_type,
						$order->get_id()
					)
				);
				return true;
		}
	}

	/**
	 * Handle an authorization event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order          The order object.
	 * @param array     $data           Webhook data.
	 * @param string    $display_amount Formatted amount for display.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function handle_authorization_event( $order, array $data, string $display_amount ) {
		// Get payment details
		$additional_data = isset( $data['additionalData'] ) && is_array( $data['additionalData'] )
		? $data['additionalData'] : array();

		$card_number = isset( $additional_data['cardNumber'] )
		? sanitize_text_field( $additional_data['cardNumber'] ) : '';

		$auth_code = isset( $additional_data['authCode'] )
		? sanitize_text_field( $additional_data['authCode'] ) : '';

		$three_d_auth = isset( $additional_data['threeDAuthenticated'] )
		? sanitize_text_field( $additional_data['threeDAuthenticated'] ) : 'false';

		$three_d_text = ( 'true' === $three_d_auth )
		? esc_html__( 'verified by 3D Secure', 'straumur-payments-for-woocommerce' )
		: esc_html__( 'not verified by 3D Secure', 'straumur-payments-for-woocommerce' );

		// Check if manual capture is enabled
		$manual_capture = ( 'yes' === $order->get_meta( '_straumur_is_manual_capture' ) );

		if ( ! $manual_capture ) {
			return self::handle_authorization_auto_capture(
				$order,
				$display_amount,
				$card_number,
				$three_d_text,
				$auth_code
			);
		} else {
			return self::handle_authorization_manual_capture(
				$order,
				$display_amount,
				$card_number,
				$three_d_text,
				$auth_code
			);
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
	 * @return true True on success.
	 */
	private static function handle_authorization_auto_capture(
		$order,
		string $display_amount,
		string $card_number,
		string $three_d_text,
		string $auth_code
	): bool {
		$note = sprintf(
			/* translators: 1: authorized amount, 2: masked card number, 3: 3D Secure text, 4: auth code */
			esc_html__( '%1$s was authorized to card %2$s, %3$s. Auth code: %4$s. Payment captured automatically.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $card_number ),
			esc_html( $three_d_text ),
			esc_html( $auth_code )
		);

		self::maybe_mark_order_paid( $order, $note );
		return true;
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
	 * @return true True on success.
	 */
	private static function handle_authorization_manual_capture(
		$order,
		string $display_amount,
		string $card_number,
		string $three_d_text,
		string $auth_code
	): bool {
		$note = sprintf(
			/* translators: 1: authorized amount, 2: masked card number, 3: 3D Secure text, 4: auth code */
			esc_html__( '%1$s was authorized to card %2$s, %3$s. Auth code: %4$s. Awaiting manual capture.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $card_number ),
			esc_html( $three_d_text ),
			esc_html( $auth_code )
		);

		$order->update_status( 'on-hold', $note );
		$order->save();

		return true;
	}

	/**
	 * Handle a refund event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order            The order object.
	 * @param array     $data             Webhook data.
	 * @param string    $display_amount   Formatted amount.
	 * @param string    $payfac_reference Payfac reference ID.
	 * @return true True on success.
	 */
	private static function handle_refund_event( $order, array $data, string $display_amount, string $payfac_reference ): bool {
		if ( 'yes' === $order->get_meta( '_straumur_refund_requested' ) ) {
			return self::handle_refund( $order, $display_amount, $payfac_reference );
		} elseif ( 'yes' === $order->get_meta( '_straumur_cancel_requested' ) ) {
			return self::handle_cancellation( $order, $payfac_reference );
		} else {
			/* translators: %s: formatted refund or cancellation amount */
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted refund or cancellation amount */
					esc_html__( 'Straumur refund/cancellation %s (unknown type)', 'straumur-payments-for-woocommerce' ),
					esc_html( $display_amount )
				)
			);
			$order->save();

			return true;
		}
	}

	/**
	 * Handle a confirmed refund event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order            The order object.
	 * @param string    $display_amount   Formatted refunded amount.
	 * @param string    $payfac_reference Payfac reference.
	 * @return true True on success.
	 */
	private static function handle_refund( $order, string $display_amount, string $payfac_reference ): bool {
		$note = sprintf(
			/* translators: 1: refunded amount, 2: payfac reference ID */
			esc_html__( 'A refund amount of %1$s has been processed by Straumur. Reference: %2$s.', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $payfac_reference )
		);

		$order->add_order_note( $note );
		$order->delete_meta_data( '_straumur_refund_requested' );
		$order->save();

		self::log_message(
			sprintf(
				'Refund processed for order %d: %s',
				$order->get_id(),
				$display_amount
			)
		);

		return true;
	}

	/**
	 * Handle a confirmed cancellation event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order            The order object.
	 * @param string    $payfac_reference Payfac reference.
	 * @return true True on success.
	 */
	private static function handle_cancellation( $order, string $payfac_reference ): bool {
		$note = sprintf(
			/* translators: %s: payfac reference */
			esc_html__( 'Cancellation confirmed by Straumur. Reference: %s.', 'straumur-payments-for-woocommerce' ),
			esc_html( $payfac_reference )
		);

		$order->add_order_note( $note );
		$order->delete_meta_data( '_straumur_cancel_requested' );
		$order->save();

		self::log_message(
			sprintf(
				'Cancellation processed for order %d',
				$order->get_id()
			)
		);

		return true;
	}

	/**
	 * Handle a capture event from Straumur (e.g., after manual capture).
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order           The order object.
	 * @param string    $display_amount  Formatted capture amount.
	 * @param string    $payfac_reference Payfac reference.
	 * @return true True on success.
	 */
	private static function handle_capture_event( $order, string $display_amount, string $payfac_reference ): bool {
		$note = sprintf(
		/* translators: 1: captured amount, 2: payfac reference ID */
			esc_html__( 'Manual capture completed for %1$s via Straumur (reference: %2$s).', 'straumur-payments-for-woocommerce' ),
			esc_html( $display_amount ),
			esc_html( $payfac_reference )
		);

		self::maybe_mark_order_paid( $order, $note );

		self::log_message(
			sprintf(
				'Capture processed for order %d: %s',
				$order->get_id(),
				$display_amount
			)
		);

		return true;
	}

	/**
	 * Handle a tokenization event from Straumur.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order The order object.
	 * @param array     $data  Webhook data.
	 * @return true True on success.
	 */
	private static function handle_tokenization_event( $order, array $data ): bool {
		$additional_data = isset( $data['additionalData'] ) && is_array( $data['additionalData'] )
			? $data['additionalData'] : array();

		$card_summary = isset( $additional_data['cardSummary'] )
			? sanitize_text_field( $additional_data['cardSummary'] ) : '';

		$auth_code = isset( $additional_data['authCode'] )
			? sanitize_text_field( $additional_data['authCode'] ) : '';

		$three_d_auth = isset( $additional_data['threeDAuthenticated'] )
			? sanitize_text_field( $additional_data['threeDAuthenticated'] ) : 'false';

		$three_d_text = ( 'true' === $three_d_auth )
			? esc_html__( 'verified by 3D Secure', 'straumur-payments-for-woocommerce' )
			: esc_html__( 'not verified by 3D Secure', 'straumur-payments-for-woocommerce' );

		$note = sprintf(
			/* translators: 1: last four digits of the card, 2: 3D Secure status text, 3: authorization code */
			esc_html__( 'Card ending in %1$s has been saved for automatic subscription payments, %2$s (Auth code: %3$s).', 'straumur-payments-for-woocommerce' ),
			esc_html( $card_summary ),
			esc_html( $three_d_text ),
			esc_html( $auth_code )
		);

		$order->add_order_note( $note );
		$order->save();

		return true;
	}

	/**
	 * Save the token data from a tokenization event.
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Order $order The completed order object.
	 * @param array     $data  Decoded JSON payload.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function save_token_data( $order, array $data ) {
		$additional_data = isset( $data['additionalData'] ) && is_array( $data['additionalData'] )
			? $data['additionalData'] : array();

		if ( empty( $additional_data['token'] ) ) {
			return new WP_Error(
				'straumur_missing_token',
				esc_html__( 'Token value is missing in tokenization event.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$token_value = sanitize_text_field( $additional_data['token'] );
		$user_id     = $order->get_user_id();

		if ( ! $user_id ) {
			self::log_message(
				sprintf(
					'Cannot save token for order %d: No user associated with order',
					$order->get_id()
				)
			);
			return true; // Not an error, just skip token saving
		}

		try {
			if ( class_exists( '\WC_Payment_Token_CC' ) ) {
				$token = new \WC_Payment_Token_CC();

				$card_type = isset( $additional_data['paymentMethod'] )
					? sanitize_text_field( $additional_data['paymentMethod'] ) : '';
				$token->set_card_type( $card_type );

				$last4 = isset( $additional_data['cardSummary'] )
					? sanitize_text_field( $additional_data['cardSummary'] ) : '';
				$token->set_last4( $last4 );

				// Attempt to parse the card expiry from the "reason" field if present
				$expiry_month = '00';
				$expiry_year  = '00';

				if ( ! empty( $data['reason'] ) ) {
					$parts = explode( ':', $data['reason'] );
					if ( count( $parts ) >= 3 ) {
						$expiry_parts = explode( '/', $parts[2] );
						if ( 2 === count( $expiry_parts ) ) {
							$expiry_month = sanitize_text_field( trim( $expiry_parts[0] ) );
							$expiry_year  = sanitize_text_field( trim( $expiry_parts[1] ) );
						}
					}
				}

				$token->set_expiry_month( $expiry_month );
				$token->set_expiry_year( $expiry_year );
			} else {
				// Fallback if \WC_Payment_Token_CC is not available
				$token = new \WC_Payment_Token();
			}

			$token->set_gateway_id( 'straumur' );
			$token->set_token( $token_value );
			$token->set_user_id( $user_id );
			$token->set_default( true );
			$token->update_meta_data( 'subscription_only', 'yes' );
			$token->save();

			update_user_meta( $user_id, '_straumur_payment_token', $token->get_id() );

			// Log with partial token for security
			$masked_token = substr( $token_value, 0, 4 ) . '...' . substr( $token_value, -4 );
			self::log_message(
				sprintf(
					'Saved token for user %d: %s (Exp: %s/%s)',
					$user_id,
					$masked_token,
					$token->get_expiry_month(),
					$token->get_expiry_year()
				)
			);

			return true;

		} catch ( \Exception $e ) {
			self::log_message(
				sprintf(
					'Error saving token for order %d: %s',
					$order->get_id(),
					$e->getMessage()
				)
			);

			return new WP_Error(
				'straumur_token_save_error',
				esc_html__( 'Error saving payment token.', 'straumur-payments-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}
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
			$processed = array();
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
			$processed = array();
		}

		// Add the event key to the processed list
		$processed[] = $event_key;

		// Keep only the last 20 events to prevent meta data from growing too large
		if ( count( $processed ) > 20 ) {
			$processed = array_slice( $processed, -20 );
		}

		$order->update_meta_data( '_straumur_processed_webhooks', $processed );
	}

	/**
	 * Sanitize webhook data for storage, removing sensitive information.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data The raw webhook data.
	 * @return array Sanitized data.
	 */
	private static function sanitize_webhook_data( array $data ): array {
		$sanitized = array();

		// Safe fields to copy directly
		$safe_fields = array(
			'merchantReference',
			'payfacReference',
			'checkoutReference',
			'amount',
			'currency',
			'reason',
			'success',
			'timestamp',
		);

		foreach ( $safe_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $data[ $field ] );
			}
		}

		// Handle additional data separately to redact sensitive info
		if ( isset( $data['additionalData'] ) && is_array( $data['additionalData'] ) ) {
			$sanitized['additionalData'] = array();

			// Copy most fields
			foreach ( $data['additionalData'] as $key => $value ) {
				// Skip or redact sensitive fields
				if ( in_array( $key, array( 'token', 'cardNumber' ), true ) ) {
					continue; // Skip completely
				}

				$sanitized['additionalData'][ $key ] = sanitize_text_field( $value );
			}

			// Add redacted versions of sensitive fields
			if ( isset( $data['additionalData']['cardNumber'] ) ) {
				$card                                      = sanitize_text_field( $data['additionalData']['cardNumber'] );
				$sanitized['additionalData']['cardNumber'] = preg_replace( '/\d(?=\d{4})/', '*', $card );
			}
		}

		return $sanitized;
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
			// ISK typically has zero decimal places
			return number_format( $raw_amount / 100, 0, ',', '.' ) . ' ISK';
		}

		// Default to two decimal places for other currencies
		return number_format( $raw_amount / 100, 2, '.', '' ) . ' ' . $currency;
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

		// Validate the HMAC key is a valid hex string
		if ( ! ctype_xdigit( $hmac_key ) ) {
			self::log_message( esc_html__( 'Invalid HMAC key format. Must be a hexadecimal string.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}

		// Prepare values for HMAC calculation
		$values = array(
			$data['checkoutReference'] ?? '',
			$data['payfacReference'] ?? '',
			$data['merchantReference'] ?? '',
			$data['amount'] ?? '',
			$data['currency'] ?? '',
			$data['reason'] ?? '',
			$data['success'] ?? '',
		);

		$payload = implode( ':', $values );

		// Convert hex key to binary safely
		try {
			$binary_key = hex2bin( $hmac_key );
			if ( false === $binary_key ) {
				throw new \Exception( 'Invalid hex value' );
			}
		} catch ( \Exception $e ) {
			self::log_message( esc_html__( 'Invalid HMAC key configured in settings.', 'straumur-payments-for-woocommerce' ) );
			return false;
		}

		// Calculate HMAC
		$computed_hash = hash_hmac( 'sha256', $payload, $binary_key, true );
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed for API data encoding, not for obfuscation
		$computed_signature = base64_encode( $computed_hash );

		// Compare signatures
		$result = hash_equals( $computed_signature, $signature );

		if ( ! $result ) {
			self::log_message( esc_html__( 'HMAC signature validation failed.', 'straumur-payments-for-woocommerce' ) );
		}

		return $result;
	}

	/**
	 * Log a message if conditions are met, using WooCommerce's logger.
	 *
	 * - Errors are always logged.
	 * - Warnings (and below) are only logged if WP_DEBUG is true.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Debug or error message to log.
	 * @param string $level   Log level (info, warning, error).
	 * @return void
	 */
	private static function log_message( string $message, string $level = 'info' ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		// Always log errors. Warnings/info are only logged if WP_DEBUG is on.
		if (
			'error' === $level
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $level, array( 'warning', 'info' ), true ) )
		) {
			if ( ! method_exists( $logger, $level ) ) {
				$level = 'info';
			}
			$logger->{$level}( $message, array( 'source' => 'straumur_webhook' ) );
		}
	}
}

// Initialize the handler.
WC_Straumur_Webhook_Handler::init();
