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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class WC_Straumur_Webhook_Handler {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

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
     * Validates the HMAC before allowing the main callback to run.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public static function check_webhook_hmac( WP_REST_Request $request ): bool {
        $body = $request->get_body();
        self::log_message( 'Incoming webhook: ' . $body );

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            self::log_message( __( 'Invalid JSON.', 'straumur-payments-for-woocommerce' ) );
            return false;
        }

        $signature = $data['hmacSignature'] ?? '';
        if ( empty( $signature ) ) {
            self::log_message( __( 'No hmacSignature provided.', 'straumur-payments-for-woocommerce' ) );
            return false;
        }

        return self::validate_hmac_signature( $data, $signature );
    }

    /**
     * Handles the webhook request (runs only if HMAC is valid).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public static function handle_payment_callback( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_body();
        $data = json_decode( $body, true );

        self::log_message( __( 'Webhook authorized. Processing...', 'straumur-payments-for-woocommerce' ) );

        if ( isset( $data['success'] ) && 'false' === $data['success'] ) {
            self::handle_failed_webhook( $data );
            return new WP_REST_Response( null, 200 );
        }

        self::process_webhook_data( $data );
        return new WP_REST_Response( null, 200 );
    }

    /**
     * Handle failed webhook data.
     *
     * @param array $data Webhook data.
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
        // "Transaction failed" 
        $reason           = $data['reason'] ?? __( 'Transaction failed', 'straumur-payments-for-woocommerce' );
        $event_type       = strtolower( $data['additionalData']['eventType'] ?? 'unknown' );

        // Escape all dynamic data.
        $note = sprintf(
            /* translators: 1) event type, 2) reason string, 3) reference */
            esc_html__( 'Straumur %1$s failed: %2$s. Reference: %3$s', 'straumur-payments-for-woocommerce' ),
            esc_html( ucfirst( $event_type ) ),
            esc_html( $reason ),
            esc_html( $payfac_reference )
        );

        $order->add_order_note( $note );
        $order->save();
        self::log_message( sprintf( 'Handled failed webhook for order %d: %s', $order_id, $note ) );
    }

    /**
     * Process successful (or partially successful) webhook data.
     *
     * @param array $data Webhook data.
     */
    private static function process_webhook_data( array $data ): void {
        $order_id = isset( $data['merchantReference'] ) ? (int) $data['merchantReference'] : 0;
        if ( $order_id <= 0 ) {
            self::log_message( __( 'No merchantReference or invalid.', 'straumur-payments-for-woocommerce' ) );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            self::log_message( sprintf(
                /* translators: %d is order ID */
                __( 'No order found for merchantReference: %d', 'straumur-payments-for-woocommerce' ),
                $order_id
            ) );
            return;
        }

        $payfac_reference = $data['payfacReference'] ?? '';
        $event_type       = strtolower( $data['additionalData']['eventType'] ?? 'unknown' );
        $original_payfac  = $data['additionalData']['originalPayfacReference'] ?? '';
        $raw_amount       = isset( $data['amount'] ) ? (int) $data['amount'] : 0;
        $event_key        = implode( ':', [ $payfac_reference, $event_type, $original_payfac, (string) $raw_amount ] );

        if ( self::is_already_processed( $order, $event_key ) ) {
            self::log_message( sprintf(
                'Duplicate webhook event key "%s" - skipping',
                $event_key
            ) );
            return;
        }
        self::mark_as_processed( $order, $event_key );

        $order->update_meta_data( '_straumur_last_webhook', wp_json_encode( $data ) );

        $currency       = $data['currency'] ?? '';
        $display_amount = self::format_amount( $raw_amount, $currency );
        $order->save();

        // Additional data for the note.
        $auth_code      = $data['additionalData']['authCode']            ?? '';
        $card_number    = $data['additionalData']['cardNumber']          ?? '';
        $three_d_auth   = $data['additionalData']['threeDAuthenticated'] ?? 'false';
        $three_d_text   = ( 'true' === $three_d_auth )
            ? esc_html__( 'verified by 3D Secure', 'straumur-payments-for-woocommerce' )
            : esc_html__( 'not verified by 3D Secure', 'straumur-payments-for-woocommerce' );
        $manual_capture = ( 'yes' === $order->get_meta( '_straumur_is_manual_capture' ) );

        // Route based on event type.
        switch ( $event_type ) {
            case 'authorization':
                if ( ! $manual_capture ) {
                    self::handle_authorization_auto_capture(
                        $order,
                        $display_amount,
                        $card_number,
                        $three_d_text,
                        $auth_code
                    );
                } else {
                    self::handle_authorization_manual_capture(
                        $order,
                        $display_amount,
                        $card_number,
                        $three_d_text,
                        $auth_code
                    );
                }
                break;

            case 'refund':
                if ( 'yes' === $order->get_meta( '_straumur_refund_requested' ) ) {
                    self::handle_refund( $order, $display_amount, $payfac_reference );
                } elseif ( 'yes' === $order->get_meta( '_straumur_cancel_requested' ) ) {
                    self::handle_cancellation( $order, $payfac_reference );
                } else {
                    $order->add_order_note(
                        sprintf(
                            /* translators: unknown type */
                            esc_html__( 'Straumur refund/cancellation %s (unknown type)', 'straumur-payments-for-woocommerce' ),
                            esc_html( $display_amount )
                        )
                    );
                }
                break;

            case 'capture':
                self::handle_capture_event( $order, $display_amount, $payfac_reference );
                break;

            default:
                // Unknown event type; 
                $order->add_order_note(
                    esc_html__( 'Unknown Straumur event type received.', 'straumur-payments-for-woocommerce' )
                );
                break;
        }

        $order->save();
        self::log_message( sprintf( 'Order %d updated with Straumur webhook data.', $order->get_id() ) );
    }

    /**
     * Check if this webhook event has already been processed on this order.
     */
    private static function is_already_processed( $order, string $event_key ): bool {
        $processed = $order->get_meta( '_straumur_processed_webhooks', true );
        if ( ! is_array( $processed ) ) {
            $processed = [];
        }
        return in_array( $event_key, $processed, true );
    }

    /**
     * Mark this webhook event as processed on the order.
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
     * Format the amount to a readable currency string.
     */
    private static function format_amount( int $raw_amount, string $currency ): string {
        if ( $raw_amount <= 0 ) {
            return __( 'N/A', 'straumur-payments-for-woocommerce' );
        }
        if ( 'ISK' === $currency ) {
            // Thousands separated with . and no decimal.
            return number_format( $raw_amount / 100, 0, ',', '.' ) . ' ISK';
        }
        // Standard decimal formatting
        return number_format( $raw_amount / 100, 2, '.', '' ) . ' ' . $currency;
    }

    /**
     * Handle an authorization event in auto-capture mode.
     */
    private static function handle_authorization_auto_capture( $order, string $display_amount, string $card_number, string $three_d_text, string $auth_code ): void {
        $note = sprintf(
            /* translators: 1) captured amount, 2) card number, 3) 3D secure text, 4) auth code */
            esc_html__( '%1$s was successfully authorized to card %2$s, %3$s. Auth code: %4$s. The transaction has been captured.', 'straumur-payments-for-woocommerce' ),
            esc_html( $display_amount ),
            esc_html( $card_number ),
            esc_html( $three_d_text ),
            esc_html( $auth_code )
        );
        $order->update_status( 'processing', $note );
    }

    /**
     * Handle an authorization event in manual-capture mode.
     */
    private static function handle_authorization_manual_capture( $order, string $display_amount, string $card_number, string $three_d_text, string $auth_code ): void {
        $note = sprintf(
            /* translators: 1) authorized amount, 2) card number, 3) 3D secure text, 4) auth code */
            esc_html__( '%1$s was successfully authorized to card %2$s, %3$s. Auth code: %4$s. You can now cancel or capture this authorization.', 'straumur-payments-for-woocommerce' ),
            esc_html( $display_amount ),
            esc_html( $card_number ),
            esc_html( $three_d_text ),
            esc_html( $auth_code )
        );
        $order->update_status( 'on-hold', $note );
    }

    /**
     * Handle a capture event.
     */
    private static function handle_capture_event( $order, string $display_amount, string $payfac_reference ): void {
        $note = sprintf(
            /* translators: 1) captured amount, 2) payment reference ID */
            esc_html__( 'Manual capture completed for %1$s via Straumur. Reference: %2$s. Order status changed to Processing.', 'straumur-payments-for-woocommerce' ),
            esc_html( $display_amount ),
            esc_html( $payfac_reference )
        );
        $order->add_order_note( $note );
    }

    /**
     * Handle a cancellation event.
     */
    private static function handle_cancellation( $order, string $payfac_reference ): void {
        $note = sprintf(
            /* translators: %s is the payfac reference */
            esc_html__( 'Cancellation confirmed by Straumur. Reference: %s.', 'straumur-payments-for-woocommerce' ),
            esc_html( $payfac_reference )
        );
        $order->add_order_note( $note );
        $order->delete_meta_data( '_straumur_cancel_requested' );
    }

    /**
     * Handle a refund event.
     */
    private static function handle_refund( $order, string $display_amount, string $payfac_reference ): void {
        $note = sprintf(
            /* translators: 1) refunded amount, 2) payfac reference */
            esc_html__( 'A refund amount of %1$s has been processed by Straumur. Reference: %2$s.', 'straumur-payments-for-woocommerce' ),
            esc_html( $display_amount ),
            esc_html( $payfac_reference )
        );
        $order->add_order_note( $note );
        $order->delete_meta_data( '_straumur_refund_requested' );
    }

    /**
     * Validate the HMAC signature from Straumur.
     */
    private static function validate_hmac_signature( array $data, string $signature ): bool {
        $hmac_key = WC_Straumur_Settings::get_hmac_key();
        if ( empty( $hmac_key ) ) {
            self::log_message( __( 'No HMAC secret configured in settings.', 'straumur-payments-for-woocommerce' ) );
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
        $payload    = implode( ':', $values );
        $binary_key = @hex2bin( $hmac_key );
        if ( false === $binary_key ) {
            self::log_message( __( 'Invalid HMAC key configured in settings.', 'straumur-payments-for-woocommerce' ) );
            return false;
        }
        $computed_hash      = hash_hmac( 'sha256', $payload, $binary_key, true );
        $computed_signature = base64_encode( $computed_hash );

        return hash_equals( $computed_signature, $signature );
    }

    /**
     * Log helper function.
     */
    private static function log_message( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->debug( $message, [ 'source' => 'straumur_webhook' ] );
        }
    }
}

WC_Straumur_Webhook_Handler::init();
