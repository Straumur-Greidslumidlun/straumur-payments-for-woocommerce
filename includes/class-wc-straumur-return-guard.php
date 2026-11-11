<?php
/**
 * Straumur Return Guard.
 *
 * Decides whether a shopper returning from Hosted Checkout may act on an order.
 *
 * @package Straumur\Payments
 * @since   2.2.4
 */

declare(strict_types=1);

namespace Straumur\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The return URL is public: anyone can request it with any order ID. A return is therefore
 * only accepted when it carries the order's own key, and the only payment session it can
 * read is the one this shop created for that order.
 */
class WC_Straumur_Return_Guard {

	/**
	 * Order meta holding the checkout reference of the session created for the order.
	 */
	const CHECKOUT_REFERENCE_META = '_straumur_checkout_reference';

	/**
	 * The checkout reference a return may use for an order, or '' when the return must be refused.
	 *
	 * @since 2.2.4
	 *
	 * @param \WC_Order $order         The order named in the return URL.
	 * @param string    $order_key     The order key sent with the return.
	 * @param string    $url_reference The checkoutReference sent with the return, '' when absent.
	 * @return string The checkout reference stored on the order, or '' to refuse the return.
	 */
	public static function bound_checkout_reference( $order, string $order_key, string $url_reference ): string {
		$expected_key = (string) $order->get_order_key();

		if ( '' === $expected_key || '' === $order_key || ! hash_equals( $expected_key, $order_key ) ) {
			return '';
		}

		if ( 'straumur' !== $order->get_payment_method() ) {
			return '';
		}

		$stored_reference = (string) $order->get_meta( self::CHECKOUT_REFERENCE_META );

		if ( '' === $stored_reference ) {
			return '';
		}

		// Straumur adds the reference to the return URL. It is never trusted, only compared.
		if ( '' !== $url_reference && ! hash_equals( $stored_reference, $url_reference ) ) {
			return '';
		}

		return $stored_reference;
	}
}
