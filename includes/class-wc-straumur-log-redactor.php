<?php
/**
 * Straumur Log Redactor
 *
 * Reduces requests, responses and webhooks to the fields needed to troubleshoot a payment
 * before they are written to the WooCommerce log.
 *
 * @package Straumur\Payments
 * @since   2.2.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use function wp_parse_url;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Straumur_Log_Redactor
 *
 * Works from an allow-list, so a field Straumur adds later stays out of the logs until it is
 * added here. Never add credentials (API key, HMAC key, theme key), card or token data,
 * shopper details (IP, name, address, email) or URLs that grant access (payment page,
 * return URL with the order key).
 *
 * @since 2.2.0
 */
final class WC_Straumur_Log_Redactor {

	/**
	 * Fields that may be logged, at the top level or inside additionalData.
	 *
	 * @since 2.2.0
	 * @var string[]
	 */
	private const LOGGABLE_FIELDS = array(
		'reference',
		'merchantReference',
		'checkoutReference',
		'payfacReference',
		'originalPayfacReference',
		'pspReference',
		'amount',
		'currency',
		'resultCode',
		'reason',
		'success',
		'responseIdentifier',
		'timestamp',
		'eventType',
		'paymentMethod',
		'threeDAuthenticated',
		'isManualCapture',
		'recurringProcessingModel',
		'terminalIdentifier',
	);

	/**
	 * Reduce a request body, response or webhook payload to its loggable fields.
	 *
	 * @since 2.2.0
	 *
	 * @param array $data Decoded payload.
	 * @return array Loggable fields only; cart items are reduced to a count.
	 */
	public static function summarize( array $data ): array {
		$summary = self::pick( $data );

		if ( isset( $data['additionalData'] ) && is_array( $data['additionalData'] ) ) {
			$additional = self::pick( $data['additionalData'] );
			if ( ! empty( $additional ) ) {
				$summary['additionalData'] = $additional;
			}
		}

		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$summary['itemCount'] = count( $data['items'] );
		}

		return $summary;
	}

	/**
	 * Path of a request URL, without host or query string.
	 *
	 * @since 2.2.0
	 *
	 * @param string $url Full request URL.
	 * @return string URL path, or an empty string if it cannot be parsed.
	 */
	public static function endpoint( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Copy the allow-listed scalar fields of one level of a payload.
	 *
	 * @since 2.2.0
	 *
	 * @param array $data One level of a decoded payload.
	 * @return array
	 */
	private static function pick( array $data ): array {
		$picked = array();
		foreach ( self::LOGGABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) && ( is_scalar( $data[ $field ] ) || null === $data[ $field ] ) ) {
				$picked[ $field ] = $data[ $field ];
			}
		}
		return $picked;
	}
}
