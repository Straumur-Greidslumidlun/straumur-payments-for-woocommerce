<?php
/**
 * Straumur API Class
 *
 * Handles communication with the Straumur payment API, including session creation,
 * status retrieval, captures, cancellations, and reversals.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

use Straumur\Payments\WC_Straumur_Settings;
use WC_Logger_Interface;
use WP_Error;
use function get_option;
use function wc_get_logger;
use function trailingslashit;
use function wp_remote_request;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_body;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function is_wp_error;
use function wp_json_encode;

/**
 * Class WC_Straumur_API
 *
 * Communicates with Straumur's API to handle payment sessions, status retrieval,
 * captures, cancellations, and reversals.
 *
 * @since 1.0.0
 */
class WC_Straumur_API
{
    /**
     * The API key for authentication.
     *
     * @var string
     */
    private $api_key;

    /**
     * Whether test mode is active.
     *
     * @var bool
     */
    private $test_mode;

    /**
     * The terminal identifier provided by Straumur.
     *
     * @var string
     */
    private $terminal_identifier;

    /**
     * The theme key for customizing the checkout.
     *
     * @var string
     */
    private $theme_key;

    /**
     * If true, payments are authorized only and require manual capture.
     *
     * @var bool
     */
    private $authorize_only;

    /**
     * Timeout for requests in seconds.
     *
     * @var int
     */
    private $timeout = 60;

    /**
     * Base URL for the API.
     *
     * @var string
     */
    private $base_url;

    /**
     * Logger instance.
     *
     * @var WC_Logger_Interface
     */
    private $logger;

    /**
     * Log context.
     *
     * @var array
     */
    private $context = ['source' => 'straumur-payments'];
/**
 * Whether we should send items with the request.
 *
 * @var bool
 */
private $send_items;
    /**
     * Constructor.
     *
     * Initializes API settings from Straumur settings and sets up logging.
     *
     * @param bool $authorize_only Whether to authorize only (not auto-capture).
     */
    public function __construct(bool $authorize_only = false)
    {
        $this->authorize_only      = $authorize_only;
        $this->api_key             = WC_Straumur_Settings::get_api_key();
        $this->theme_key           = WC_Straumur_Settings::get_theme_key();
        $this->terminal_identifier = WC_Straumur_Settings::get_terminal_identifier();
        $this->test_mode           = WC_Straumur_Settings::is_test_mode();
        $this->send_items = WC_Straumur_Settings::send_items();

        $production_url = WC_Straumur_Settings::get_production_url();
        $this->base_url = $this->test_mode
            ? 'https://checkout-api.staging.straumur.is/api/v1/'
            : trailingslashit($production_url);

        $this->logger = wc_get_logger();
    }

    /**
     * Create a payment session.
     *
     * @param int    $amount     Amount in minor units.
     * @param string $currency   Currency code.
     * @param string $return_url URL to redirect customer after payment.
     * @param string $reference  Order reference.
     * @param array  $items      Line items for the transaction.
     * @return array|false Response data or false on failure.
     */
public function create_session( int $amount, string $currency, string $return_url, string $reference, array $items ) {
    $endpoint = 'hostedcheckout/';

    $body = [
        'amount'             => $amount,
        'currency'           => $currency,
        'returnUrl'          => $return_url,
        'reference'          => $reference,
        'terminalIdentifier' => $this->terminal_identifier,
    ];

    if ( $this->send_items ) {
        $body['items'] = $items;
    }

    if ( ! $this->test_mode && ! empty( $this->theme_key ) ) {
        $body['themeKey'] = $this->theme_key;
    }

    if ( $this->authorize_only ) {
        $body['IsManualCapture'] = true;
    }

    return $this->send_request( $endpoint, $body );
}

    /**
     * Get the session status by checkout reference.
     *
     * @param string $checkout_reference The checkout reference.
     * @return array|false Response data or false on failure.
     */
    public function get_session_status(string $checkout_reference)
    {
        $endpoint = "hostedcheckout/status/{$checkout_reference}";
        return $this->send_request($endpoint, [], 'POST');
    }

    /**
     * Capture an authorized payment.
     *
     * @param string $payfac_reference Payfac reference.
     * @param string $reference        Order reference.
     * @param int    $amount           Amount in minor units.
     * @param string $currency         Currency code.
     * @return array|false
     */
    public function capture(string $payfac_reference, string $reference, int $amount, string $currency)
    {
        $body = [
            'reference'       => $reference,
            'payfacReference' => $payfac_reference,
            'amount'          => $amount,
            'currency'        => $currency,
        ];

        return $this->send_request('modification/capture', $body);
    }



    /**
     * Reverse an authorization.
     *
     * @param string $reference        Order reference.
     * @param string $payfac_reference Payfac reference.
     * @return bool True on success, false on failure.
     */
    public function reverse(string $reference, string $payfac_reference): bool
    {
        $body = [
            'reference'       => $reference,
            'payfacReference' => $payfac_reference,
        ];

        $response = $this->send_request('modification/reverse', $body);
        return (bool)$response;
    }

    /**
     * Send an API request to Straumur.
     *
     * @param string $endpoint
     * @param array  $body
     * @param string $method
     * @return array|false
     */
    private function send_request(string $endpoint, array $body = [], string $method = 'POST')
    {
        $url = $this->base_url . $endpoint;

        $args = [
            'headers' => $this->get_request_headers(),
            'body'    => wp_json_encode($body),
            'method'  => $method,
            'timeout' => $this->timeout,
        ];

        $response = wp_remote_request($url, $args);
        return $this->handle_response($response, $url);
    }

    /**
     * Handle the raw response from wp_remote_request.
     *
     * @param array|WP_Error $response
     * @param string         $url
     * @return array|false
     */
    private function handle_response($response, string $url)
    {
        if (is_wp_error($response)) {
            $this->log('API error: ' . $response->get_error_message() . " for $url", 'error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $this->log("Response ($response_code) Body: " . $response_body, 'info');

        $response_data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('JSON decode error: ' . json_last_error_msg(), 'error');
            return false;
        }

        if ($response_code >= 200 && $response_code < 300) {
            return $response_data;
        }

        return false;
    }

    /**
     * Create request headers.
     *
     * @return array
     */
    private function get_request_headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-API-key'    => $this->api_key,
        ];
    }

    /**
     * Log a message using WooCommerce logger.
     *
     * @param string $message
     * @param string $level
     */
    private function log(string $message, string $level = 'info'): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (method_exists($this->logger, $level)) {
                $this->logger->$level($message, $this->context);
            } else {
                $this->logger->info($message, $this->context);
            }
        }
    }
}
