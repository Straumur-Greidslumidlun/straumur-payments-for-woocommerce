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

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

use function add_action;
use function register_rest_route;
use function wc_get_order;
use function wc_get_logger;
use function wp_json_encode;
use function json_decode;
use function sanitize_text_field;
use function __return_true;
use function defined;
use function hash_equals;
use function base64_encode;
use function hex2bin;
use function hash_hmac;

class WC_Straumur_Webhook_Handler
{
    /**
     * Initialize the webhook handler by registering REST routes.
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Register the REST API route for the webhook.
     */
    public static function register_routes(): void
    {
        register_rest_route(
            'straumur/v1',
            '/payment-callback',
            [
                'methods'             => WP_REST_Server::CREATABLE, // POST
                'callback'            => [self::class, 'handle_payment_callback'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Handle the webhook request.
     *
     * @param WP_REST_Request $request Incoming REST request.
     *
     * @return WP_REST_Response
     */
    public static function handle_payment_callback(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $data = json_decode($body, true);

        // Always log incoming webhook for development
        self::log_message('Incoming webhook: ' . $body);

        if (! is_array($data)) {
            self::log_message('Webhook received with invalid JSON payload.');
            return new WP_REST_Response(null, 200);
        }

        $signature = $data['hmacSignature'] ?? '';

        if (empty($signature)) {
            self::log_message('Webhook received without hmacSignature field.');
            return new WP_REST_Response(null, 200);
        }

        $is_valid = self::validate_hmac_signature($data, $signature);
        if (! $is_valid) {
            self::log_message('Invalid HMAC signature for webhook.');
            return new WP_REST_Response(null, 200);
        }


        if (isset($data['success']) && 'false' === $data['success']) {
            self::handle_failed_webhook($data);
            return new WP_REST_Response(null, 200);
        }

        // Valid webhook with success=true
        self::process_webhook_data($data);

        // Return empty 200 response
        return new WP_REST_Response(null, 200);
    }


    private static function handle_failed_webhook(array $data): void
    {
        $order_id = isset($data['merchantReference']) ? (int) $data['merchantReference'] : 0;
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $payfac_reference = $data['payfacReference'] ?? '';
        $reason           = $data['reason'] ?? 'Transaction failed';
        $event_type       = strtolower($data['additionalData']['eventType'] ?? 'unknown');

        $note = sprintf(
            'Straumur %s failed: %s. Reference: %s',
            ucfirst($event_type), 
            $reason,              
            $payfac_reference
        );

        $order->add_order_note($note);
        $order->save();

        self::log_message("Handled failed webhook for order {$order_id}: {$note}");
    }

    /**
     * Process the webhook data and update the corresponding order.
     *
     * @param array $data The webhook payload array.
     *
     * @return void
     */
    private static function process_webhook_data(array $data): void
    {
        $order_id = isset($data['merchantReference']) ? (int) $data['merchantReference'] : 0;
        if ($order_id <= 0) {
            self::log_message('No merchantReference provided or invalid. Cannot associate webhook with an order.');
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            self::log_message("No order found for merchantReference: {$order_id}");
            return;
        }

        $payfac_reference  = $data['payfacReference']                          ?? '';
        $event_type        = strtolower($data['additionalData']['eventType']   ?? 'unknown');
        $original_payfac   = $data['additionalData']['originalPayfacReference'] ?? '';
        $raw_amount        = isset($data['amount']) ? (int) $data['amount'] : 0;

 
        $event_key = implode(':', [
            $payfac_reference,   
            $event_type,         
            $original_payfac,    
            (string)$raw_amount, 
        ]);

        if (self::is_already_processed($order, $event_key)) {
            self::log_message("Duplicate webhook event key '{$event_key}' - skipping");
            return;
        }
        self::mark_as_processed($order, $event_key);

        $order->update_meta_data('_straumur_last_webhook', wp_json_encode($data));

        $currency       = $data['currency'] ?? '';
        $display_amount = self::format_amount($raw_amount, $currency);

        $order->save();

        $auth_code      = $data['additionalData']['authCode']            ?? '';
        $card_number    = $data['additionalData']['cardNumber']          ?? '';
        $three_d_auth   = $data['additionalData']['threeDAuthenticated'] ?? 'false';
        $three_d_text   = ('true' === $three_d_auth) ? 'verified by 3D Secure' : 'not verified by 3D Secure';
        $manual_capture = ('yes' === $order->get_meta('_straumur_is_manual_capture'));

        switch ($event_type) {
            case 'authorization':
                if (! $manual_capture) {
                    // auto-capture
                    self::handle_authorization_auto_capture(
                        $order,
                        $display_amount,
                        $card_number,
                        $three_d_text,
                        $auth_code,
                        $payfac_reference
                    );
                } else {
                    // manual capture
                    self::handle_authorization_manual_capture(
                        $order,
                        $display_amount,
                        $card_number,
                        $three_d_text,
                        $auth_code,
                        $payfac_reference
                    );
                }
                break;

            case 'refund':
                // Distinguish between refund vs. cancellation
                if ('yes' === $order->get_meta('_straumur_refund_requested')) {
                    self::handle_refund($order, $display_amount, $payfac_reference);
                } elseif ('yes' === $order->get_meta('_straumur_cancel_requested')) {
                    self::handle_cancellation($order, $payfac_reference);
                } else {
                    $unknown_refund_message = sprintf(
                        'Straumur refund/cancellation %s (unknown type)',
                        $display_amount
                    );
                    $order->add_order_note($unknown_refund_message);
                }
                break;

       case 'capture':
    self::handle_capture_event($order, $display_amount, $payfac_reference);
    break;

            default:
                $order->add_order_note(__('Unknown Straumur event type received.', 'straumur-payments-for-woocommerce'));
                break;
        }

        $order->save();
        self::log_message('Order ' . $order->get_id() . ' updated with Straumur webhook data.');
    }

    /**
     * Check if we have processed this "event key" before for this order.
     *
     * @param \WC_Order $order      The order instance.
     * @param string    $event_key  The unique key (payfacRef + eventType + originalRef + amount).
     *
     * @return bool True if already processed, false otherwise.
     */
    private static function is_already_processed($order, string $event_key): bool
    {
        $processed = $order->get_meta('_straumur_processed_webhooks', true);
        if (! is_array($processed)) {
            $processed = [];
        }
        return in_array($event_key, $processed, true);
    }

    /**
     * Mark this event key as processed for this order.
     *
     * @param \WC_Order $order     The order instance.
     * @param string    $event_key The unique event key.
     *
     * @return void
     */
    private static function mark_as_processed($order, string $event_key): void
    {
        $processed = $order->get_meta('_straumur_processed_webhooks', true);
        if (! is_array($processed)) {
            $processed = [];
        }
        $processed[] = $event_key;
        $order->update_meta_data('_straumur_processed_webhooks', $processed);
        $order->save();
    }

    /**
     * Format the amount for display, 
     *
     * @param int    $raw_amount The amount in minor units.
     * @param string $currency   The currency code (e.g., "ISK").
     *
     * @return string Formatted amount, e.g. "56.000 ISK" or "12.34 USD".
     */
    private static function format_amount(int $raw_amount, string $currency): string
    {
        if ($raw_amount <= 0) {
            return 'N/A';
        }

        // Example for ISK => no decimals, e.g. "56.000 ISK"
        if ('ISK' === $currency) {
            $amount_major = number_format($raw_amount / 100, 0, ',', '.');
            return $amount_major . ' ISK';
        }

        // Fallback for other currencies => 2 decimals
        $amount_major = number_format($raw_amount / 100, 2, '.', '');
        return $amount_major . ' ' . $currency;
    }

    /**
     * If manual_capture == false => auto-capture scenario.
     */
    private static function handle_authorization_auto_capture(
        \WC_Order $order,
        string $display_amount,
        string $card_number,
        string $three_d_text,
        string $auth_code,
    ): void {
        $note = sprintf(
            "%s was successfully authorized to card %s, %s.\nAuth code is %s.\n\n".
            "This transaction has been captured and can be refunded if needed.",
            $display_amount,
            $card_number,
            $three_d_text,
            $auth_code,
        );

        $order->update_status('processing', $note);
    }

    /**
     * If manual_capture == true => only authorize (not auto-capture).
     */
    private static function handle_authorization_manual_capture(
        \WC_Order $order,
        string $display_amount,
        string $card_number,
        string $three_d_text,
        string $auth_code,
    ): void {
        $note = sprintf(
            "%s was successfully authorized to card %s, %s.\nAuth code is %s.\n\n".
            "This authorization can be cancelled or captured.",
            $display_amount,
            $card_number,
            $three_d_text,
            $auth_code,
        );

        $order->update_status('on-hold', $note);
    }
private static function handle_capture_event(\WC_Order $order, string $display_amount, string $payfac_reference): void
{
    $note = sprintf(
        "Manual capture completed for %s via Straumur. Reference: %s.\n\n" .
        "Order status changed to Processing. This transaction can now be refunded if needed.",
        $display_amount,
        $payfac_reference
    );

        $order->add_order_note($note);
}
    /**
     * Cancels the transaction.
     */
    private static function handle_cancellation(\WC_Order $order, string $payfac_reference): void
    {
        $note = sprintf(
            "Cancellation confirmed by Straumur. Reference: %s.",
            $payfac_reference
        );
        $order->add_order_note($note);
        $order->delete_meta_data('_straumur_cancel_requested');
    }

    /**
     * Process a refund.
     */
    private static function handle_refund(\WC_Order $order, string $display_amount, string $payfac_reference): void
    {
        $note = sprintf(
            "A refund amount of %s has been processed by Straumur. Reference: %s",
            $display_amount,
            $payfac_reference
        );
        $order->add_order_note($note);
        $order->delete_meta_data('_straumur_refund_requested');

    }

    /**
     * Validate the HMAC signature against the payload.
     */
    private static function validate_hmac_signature(array $data, string $signature): bool
    {
        $hmac_key = WC_Straumur_Settings::get_hmac_key();
        if (empty($hmac_key)) {
            self::log_message('No HMAC secret configured in settings.');
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

        $payload = implode(':', $values);

        $binary_key = @hex2bin($hmac_key);
        if (false === $binary_key) {
            self::log_message('Invalid HMAC key configured in settings.');
            return false;
        }

        $computed_hash      = hash_hmac('sha256', $payload, $binary_key, true);
        $computed_signature = base64_encode($computed_hash);

        return hash_equals($computed_signature, $signature);
    }

    /**
     * Log message using WooCommerce logger (debug level) only if WP_DEBUG is true.
     */
    private static function log_message(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, ['source' => 'straumur_webhook']);
        }
    }
}

WC_Straumur_Webhook_Handler::init();
