<?php
/**
 * Straumur API Class
 *
 * Communicates with Straumur's API to handle payment sessions, status retrieval,
 * captures, cancellations, and reversals.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use Straumur\Payments\WC_Straumur_Settings;
use WC_Logger_Interface;
use WP_Error;

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
 * @since 1.0.0
 */
class WC_Straumur_API {

	/**
	 * Holds the singleton instance.
	 *
	 * @var WC_Straumur_API|null
	 */
	private static $instance = null;

	/**
	 * API key for authentication.
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
	 * Terminal identifier provided by Straumur (non-token transactions).
	 *
	 * @var string
	 */
	private $terminal_identifier;

	/**
	 * Gateway terminal identifier (used for tokenized payments).
	 *
	 * @var string
	 */
	private $gateway_terminal_identifier;

	/**
	 * Theme key for customizing the hosted checkout interface.
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
	 * Timeout for requests (in seconds).
	 *
	 * @var int
	 */
	private $timeout = 60;

	/**
	 * Base URL for the API endpoint.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger_Interface
	 */
	private $logger;

	/**
	 * Logging context array.
	 *
	 * @var array
	 */
	private $context = array(
		'source' => 'straumur-api',
	);

	/**
	 * Whether line items should be included in the hosted checkout request.
	 *
	 * @var bool
	 */
	private $send_items;

	/**
	 * The checkout expiry in fractional hours (e.g., 0.0833 for 5 minutes).
	 *
	 * @var float
	 */
	private $checkout_expiry;

	/**
	 * Get the singleton instance.
	 *
	 * @return WC_Straumur_API
	 */
	public static function instance(): WC_Straumur_API {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $authorize_only Whether to authorize only (no auto-capture).
	 */
	public function __construct( bool $authorize_only = false ) {
		$this->authorize_only              = $authorize_only;
		$this->api_key                     = WC_Straumur_Settings::get_api_key();
		$this->theme_key                   = WC_Straumur_Settings::get_theme_key();
		$this->terminal_identifier         = WC_Straumur_Settings::get_terminal_identifier();
		$this->gateway_terminal_identifier = WC_Straumur_Settings::get_gateway_terminal_identifier();
		$this->test_mode                   = WC_Straumur_Settings::is_test_mode();
		$this->send_items                  = WC_Straumur_Settings::send_items();

		// Retrieve checkout expiry from settings, ensuring it's within a valid range.
		$hours = (float) WC_Straumur_Settings::get_checkout_expiry();
		if ( $hours < 0.0833 ) {
			$hours = 0.0833;
		} elseif ( $hours > 24 ) {
			$hours = 24;
		}
		$this->checkout_expiry = $hours;

		// Determine the base URL (test vs. production).
		$production_url = WC_Straumur_Settings::get_production_url();
		$this->base_url = $this->test_mode
			? 'https://checkout-api.staging.straumur.is/api/v1/'
			: trailingslashit( $production_url );

		$this->logger = wc_get_logger();
	}

	/**
	 * Create a payment session for the hosted checkout.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount          Minor units (e.g. 50000 => 500.00).
	 * @param string $currency        ISO currency code (e.g., "ISK").
	 * @param string $return_url      URL to which Straumur will redirect after payment.
	 * @param string $reference       Merchant reference (order ID or similar).
	 * @param array  $items           Optional line items array.
	 * @param bool   $is_subscription True if this is a recurring subscription payment.
	 * @param string $abandon_url     Optional URL for the shopper if they abandon the payment.
	 *
	 * @return array|false Response array from Straumur on success, false otherwise.
	 */
	public function create_session(
		int $amount,
		string $currency,
		string $return_url,
		string $reference,
		array $items,
		bool $is_subscription = false,
		string $abandon_url = ''
	) {
		$endpoint   = 'hostedcheckout/';
		$expires_at = gmdate( 'Y-m-d\\TH:i:s.v\\Z', time() + (int) ( $this->checkout_expiry * HOUR_IN_SECONDS ) );

		$body = array(
			'amount'             => $amount,
			'currency'           => $currency,
			'returnUrl'          => $return_url,
			'reference'          => $reference,
			'terminalIdentifier' => $this->terminal_identifier,
			'expiresAt'          => $expires_at,
		);

		// Include line items if requested.
		if ( $this->send_items ) {
			$body['items'] = $items;
		}

		// Use the theme key only in production mode.
		if ( ! $this->test_mode && ! empty( $this->theme_key ) ) {
			$body['themeKey'] = $this->theme_key;
		}

		// If manual capture is requested.
		if ( $this->authorize_only ) {
			$body['isManualCapture'] = true;
		}

		// If subscription, set recurringProcessingModel.
		if ( $is_subscription ) {
			$body['recurringProcessingModel'] = 'Subscription';
		}

		// Include abandon URL if provided.
		if ( ! empty( $abandon_url ) ) {
			$body['abandonUrl'] = $abandon_url;
		}

		return $this->send_request( $endpoint, $body );
	}

	/**
	 * Retrieve the session status by checkout reference.
	 *
	 * @since 1.0.0
	 *
	 * @param string $checkout_reference The Straumur checkout reference ID.
	 * @return array|false Array on success, false otherwise.
	 */
	public function get_session_status( string $checkout_reference ) {
		$endpoint = "hostedcheckout/status/{$checkout_reference}";

		return $this->send_request( $endpoint, array(), 'GET' );
	}

	/**
	 * Capture an authorized payment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $payfac_reference Payfac reference from Straumur.
	 * @param string $reference        Your internal (merchant) reference.
	 * @param int    $amount           Amount to capture (minor units).
	 * @param string $currency         Currency code.
	 * @return array|false Array on success, or false on failure.
	 */
	public function capture( string $payfac_reference, string $reference, int $amount, string $currency ) {
		$body = array(
			'reference'       => $reference,
			'payfacReference' => $payfac_reference,
			'amount'          => $amount,
			'currency'        => $currency,
		);

		return $this->send_request( 'modification/capture', $body );
	}

	/**
	 * Reverse an authorization or transaction (partial or full).
	 *
	 * @since 1.0.0
	 *
	 * @param string $reference        Your internal reference.
	 * @param string $payfac_reference Payfac reference from Straumur.
	 * @return bool True if the request was successful, false otherwise.
	 */
	public function reverse( string $reference, string $payfac_reference ): bool {
		$body = array(
			'reference'       => $reference,
			'payfacReference' => $payfac_reference,
		);

		$response = $this->send_request( 'modification/reverse', $body );
		return (bool) $response;
	}

	/**
	 * Process a token-based subscription payment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token_value Token value stored in WooCommerce.
	 * @param int    $amount      Amount in minor units.
	 * @param string $currency    ISO currency code.
	 * @param string $reference   Unique order/merchant reference.
	 * @param string $shopper_ip  Shopper's IP address.
	 * @param string $origin      Origin URL (store domain).
	 * @param string $channel     Payment channel (e.g., 'Web').
	 * @param string $return_url  URL to handle 3DS or additional steps.
	 * @return array The Straumur API response as an associative array.
	 */
	public function process_token_payment(
		string $token_value,
		int $amount,
		string $currency,
		string $reference,
		string $shopper_ip,
		string $origin,
		string $channel,
		string $return_url
	): array {
		$body = array(
			'terminalIdentifier' => $this->gateway_terminal_identifier,
			'amount'             => $amount,
			'currency'           => $currency,
			'reference'          => $reference,
			'shopperIp'          => $shopper_ip,
			'origin'             => $origin,
			'channel'            => $channel,
			'returnUrl'          => $return_url,
			'tokenDetails'       => array(
				'tokenValue'               => $token_value,
				'recurringProcessingModel' => 'Subscription',
			),
		);

		$this->log(
			"Processing token payment for reference {$reference} and amount {$amount}",
			'info'
		);

		$endpoint = 'payment';
		$result   = $this->send_request( $endpoint, $body );

		if ( isset( $result['resultCode'] ) && 'Authorised' === $result['resultCode'] ) {
			// Process and normalize payment references for future operations like refunds
			$result = $this->normalize_payment_references( $result, $reference );
			$this->log( "Token payment authorised for reference {$reference}", 'info' );
		} elseif ( isset( $result['resultCode'] ) && 'RedirectShopper' === $result['resultCode'] ) {
			$this->log( "Token payment requires redirect for reference {$reference}", 'info' );
		} else {
			$this->log(
				"Token payment failed for reference {$reference} with response: " . wp_json_encode( $result ),
				'error'
			);
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Common function to send an API request to Straumur.
	 *
	 * @param string $endpoint
	 * @param array  $body
	 * @param string $method
	 * @return array|false
	 */
	private function send_request( string $endpoint, array $body = array(), string $method = 'POST' ) {
		$url = $this->base_url . $endpoint;

		$args = array(
			'headers' => $this->get_request_headers(),
			'method'  => $method,
			'timeout' => $this->timeout,
		);

		if ( 'GET' === $method && ! empty( $body ) ) {
			// Construct query string for GET requests
			$url                             = add_query_arg( $body, $url );
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		} elseif ( 'POST' === $method && ! empty( $body ) ) {
			// Encode body as JSON for POST requests
			$args['body'] = wp_json_encode( $body );
		}

		// Log outgoing request
		$this->log(
			wp_json_encode(
				array(
					'straumur_request' => array(
						'method'  => $method,
						'url'     => $url,
						'headers' => $args['headers'],
						'body'    => $body,
					),
				)
			),
			'info'
		);

		// Execute request
		$response = wp_remote_request( $url, $args );

		return $this->handle_response( $response, $url );
	}

	/**
	 * Handle the raw response from wp_remote_request().
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response The result of wp_remote_request().
	 * @param string         $url      The requested URL.
	 * @return array|false Decoded response on success, false on failure.
	 */
	private function handle_response( $response, string $url ) {
		if ( is_wp_error( $response ) ) {
			$this->log(
				wp_json_encode(
					array(
						'straumur_response' => array(
							'url'   => $url,
							'error' => $response->get_error_message(),
						),
					)
				),
				'error'
			);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$this->log(
			wp_json_encode(
				array(
					'straumur_response' => array(
						'url'  => $url,
						'code' => $response_code,
						'body' => $response_body,
					),
				)
			),
			'info'
		);

		$response_data = json_decode( $response_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->log(
				'JSON decode error: ' . json_last_error_msg(),
				'error'
			);
			return false;
		}

		// Return data only if HTTP status is 2xx.
		if ( $response_code >= 200 && $response_code < 300 ) {
			return $response_data;
		}

		return false;
	}

	/**
	 * Build request headers, including the X-API-key.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of headers.
	 */
	private function get_request_headers(): array {
		return array(
			'Content-Type' => 'application/json',
			'X-API-key'    => $this->api_key,
		);
	}

	/**
	 * Normalize payment references in the API response.
	 *
	 * This function ensures that payfacReference is available in the response,
	 * which is required for refunding subscription payments.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $response  The API response.
	 * @param string $reference The merchant reference.
	 * @return array The normalized response.
	 */
	private function normalize_payment_references( array $response, string $reference ): array {
		// Some API responses use pspReference instead of payfacReference
		if ( ! isset( $response['payfacReference'] ) && isset( $response['pspReference'] ) ) {
			$response['payfacReference'] = $response['pspReference'];
			$this->log(
				"Normalized pspReference to payfacReference ({$response['payfacReference']}) for reference {$reference}",
				'info'
			);
		}

		if ( ! isset( $response['payfacReference'] ) && isset( $response['additionalData']['payfacReference'] ) ) {
			$response['payfacReference'] = $response['additionalData']['payfacReference'];
			$this->log(
				"Extracted payfacReference ({$response['payfacReference']}) from additionalData for reference {$reference}",
				'info'
			);
		}

		if ( isset( $response['payfacReference'] ) ) {
			$this->log(
				"Payment has payfacReference: {$response['payfacReference']} for merchant reference {$reference}",
				'info'
			);
		} else {
			$this->log(
				"No payfacReference found in response for reference {$reference}. Refunds may not be possible.",
				'warning'
			);
		}

		return $response;
	}

	/**
	 * Log a message using WooCommerce's logger if WP_DEBUG is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (e.g., 'info', 'error').
	 * @return void
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( method_exists( $this->logger, $level ) ) {
				$this->logger->{$level}( $message, $this->context );
			} else {
				$this->logger->info( $message, $this->context );
			}
		}
	}
}
