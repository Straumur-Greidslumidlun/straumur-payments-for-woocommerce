<?php
/**
 * Straumur Kortalán helper.
 *
 * What the Straumur Kortalán gateway knows about a payment outside the checkout request itself:
 * whether Kortalán can be offered for an amount, what a status answer means for an order, and
 * the follow-up check on an order whose shopper never came back.
 *
 * @package Straumur\Payments
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Straumur_Kortalan
 *
 * @since 2.3.0
 */
final class WC_Straumur_Kortalan {

	/**
	 * Action Scheduler hook of the follow-up check on a pending order.
	 */
	const CHECK_ACTION = 'straumur_kortalan_check_order';

	/**
	 * Action Scheduler group of the follow-up checks.
	 */
	const CHECK_GROUP = 'straumur-payments';

	/**
	 * Kortalán confirmed the loan.
	 */
	const OUTCOME_PAID = 'paid';

	/**
	 * The payment can no longer be completed: refused, cancelled or expired.
	 */
	const OUTCOME_FAILED = 'failed';

	/**
	 * The shopper may still be in the Kortalán application.
	 */
	const OUTCOME_WAITING = 'waiting';

	/**
	 * Straumur did not answer, or answered with a status this version does not know.
	 */
	const OUTCOME_UNKNOWN = 'unknown';

	/**
	 * Straumur error codes a refused request can carry (Checkout API validation errors).
	 */
	const ERROR_NO_CURRENCY_CONTRACT = '2011';
	const ERROR_NOT_CONFIGURED       = '2040';
	const ERROR_NOT_AVAILABLE        = '2042';

	/**
	 * How many times a pending order is checked before the merchant is asked to look at it.
	 */
	const MAX_CHECKS = 5;

	/**
	 * Seconds between two checks of the same order.
	 */
	const RECHECK_DELAY = 900;

	/**
	 * Seconds after the payment was started at which an order is first looked at. This check
	 * only ever marks an order paid: it exists for the loan that was approved while the webhook
	 * was lost and the shopper never came back, so the order is not left unpaid until the
	 * payment session expires.
	 */
	const EARLY_CHECK_DELAY = 900;

	/**
	 * Attempt number of the early check. It is not part of the chain of checks after expiry.
	 */
	const EARLY_CHECK = 0;

	/**
	 * Seconds to wait for an availability answer. The question is asked while the checkout page
	 * is built, so a slow answer is treated as no answer.
	 */
	const AVAILABILITY_TIMEOUT = 3;

	/**
	 * Seconds a yes or no about availability is reused. The answer depends only on the terminal,
	 * amount and currency, and the checkout asks again on every refresh.
	 */
	const AVAILABILITY_TTL = 600;

	/**
	 * Seconds an unanswered availability question is treated as a no before it is asked again.
	 */
	const AVAILABILITY_ERROR_TTL = 120;

	/**
	 * Whether Straumur Kortalán can be offered for an amount and currency.
	 *
	 * Only an explicit yes offers the option: an option that then fails is worse for the shopper
	 * than one fewer option. That includes Straumur not answering.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO currency code.
	 * @return bool
	 */
	public static function is_available( int $amount, string $currency ): bool {
		if ( $amount <= 0 || '' === $currency ) {
			return false;
		}

		$cache_key = self::availability_cache_key( $amount, $currency );
		$cached    = get_transient( $cache_key );

		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}

		$api      = new WC_Straumur_API();
		$response = $api->get_kortalan_availability( $amount, $currency );

		if ( ! is_array( $response ) ) {
			set_transient( $cache_key, 'no', self::AVAILABILITY_ERROR_TTL );
			return false;
		}

		$available = self::is_available_answer( $response );
		set_transient( $cache_key, $available ? 'yes' : 'no', self::AVAILABILITY_TTL );

		return $available;
	}

	/**
	 * Whether an availability response is an explicit yes.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $response Decoded response, or false.
	 * @return bool
	 */
	public static function is_available_answer( $response ): bool {
		return is_array( $response ) && isset( $response['available'] ) && true === $response['available'];
	}

	/**
	 * Transient name for one availability answer.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	public static function availability_cache_key( int $amount, string $currency ): string {
		$scope = implode(
			'|',
			array(
				WC_Straumur_Settings::get_kortalan_terminal_identifier(),
				WC_Straumur_Settings::is_test_mode() ? 'test' : 'live',
				(string) $amount,
				strtoupper( $currency ),
			)
		);

		return 'straumur_kor_avail_' . md5( $scope );
	}

	/**
	 * What a status answer means for the order.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $status_response Decoded status response, or false.
	 * @return string One of the OUTCOME_ constants.
	 */
	public static function outcome( $status_response ): string {
		if ( ! is_array( $status_response ) || ! isset( $status_response['status'] ) || ! is_string( $status_response['status'] ) ) {
			return self::OUTCOME_UNKNOWN;
		}

		switch ( strtolower( $status_response['status'] ) ) {
			case 'completed':
				return self::OUTCOME_PAID;

			case 'expired':
			case 'refused':
			case 'cancelled':
			case 'failed':
				return self::OUTCOME_FAILED;

			case 'new':
			case 'inprogress':
				return self::OUTCOME_WAITING;

			default:
				return self::OUTCOME_UNKNOWN;
		}
	}

	/**
	 * What to tell the shopper when Straumur refuses to start a Kortalán payment.
	 *
	 * @since 2.3.0
	 *
	 * @param string $error_code Straumur error code, '' when Straumur did not answer.
	 * @return string
	 */
	public static function start_error_message( string $error_code ): string {
		if ( self::ERROR_NOT_AVAILABLE === $error_code || self::ERROR_NO_CURRENCY_CONTRACT === $error_code ) {
			return esc_html__( 'Straumur Kortalán is not available for this order amount. Please choose another payment method.', 'straumur-payments-for-woocommerce' );
		}

		if ( self::ERROR_NOT_CONFIGURED === $error_code ) {
			return esc_html__( 'Straumur Kortalán is not available in this shop at the moment. Please choose another payment method.', 'straumur-payments-for-woocommerce' );
		}

		return esc_html__( 'Unable to start the Straumur Kortalán payment. Please try again or choose another payment method.', 'straumur-payments-for-woocommerce' );
	}

	/**
	 * Mark an order paid from a status answer, when the webhook has not already done so.
	 *
	 * The webhook is what normally marks the order paid. This is for a shop whose webhook did
	 * not arrive: the status comes from Straumur over the shop's own authenticated request.
	 *
	 * @since 2.3.0
	 *
	 * @param \WC_Order $order            The order.
	 * @param string    $payfac_reference Straumur reference of the payment, '' when not given.
	 * @return bool True when this call marked the order paid.
	 */
	public static function record_paid( $order, string $payfac_reference ): bool {
		if ( '' !== $payfac_reference ) {
			$order->update_meta_data( '_straumur_payfac_reference', $payfac_reference );
		}

		if ( $order->is_paid() ) {
			$order->save();
			return false;
		}

		$order->payment_complete( $payfac_reference );
		$order->add_order_note(
			sprintf(
				/* translators: %s: payment reference */
				esc_html__( 'Straumur confirmed the Straumur Kortalán payment. Reference: %s.', 'straumur-payments-for-woocommerce' ),
				esc_html( $payfac_reference )
			)
		);

		if ( WC_Straumur_Settings::is_complete_order_on_payment() || ! $order->needs_processing() ) {
			$order->update_status( 'completed' );
		}

		$order->save();

		return true;
	}

	/**
	 * The Straumur reference of the payment in a status answer, '' when there is none.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $status_response Decoded status response, or false.
	 * @return string
	 */
	public static function payfac_reference( $status_response ): string {
		return is_array( $status_response ) && isset( $status_response['payfacReference'] ) && is_scalar( $status_response['payfacReference'] )
			? sanitize_text_field( (string) $status_response['payfacReference'] )
			: '';
	}

	/**
	 * Schedule a check of an order whose shopper may never come back.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $order_id           The order.
	 * @param string $checkout_reference The payment the check is for.
	 * @param int    $delay              Seconds from now.
	 * @param int    $attempt            Which check this is: EARLY_CHECK, or 1 and up after expiry.
	 * @return void
	 */
	public static function schedule_check( int $order_id, string $checkout_reference, int $delay, int $attempt = 1 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + max( 60, $delay ),
			self::CHECK_ACTION,
			array( $order_id, $checkout_reference, $attempt ),
			self::CHECK_GROUP
		);
	}

	/**
	 * Resolve an order that is still unpaid after its Kortalán payment should have finished.
	 *
	 * Runs from Action Scheduler. Does nothing when the order has moved on: paid by the webhook
	 * or the return, or paid for again with another payment (a new attempt has its own reference).
	 *
	 * A cancelled order is still looked at. WooCommerce cancels unpaid orders after its
	 * "hold stock" time, by default sooner than the payment session expires, and the loan may
	 * have been approved all the same. Such an order is marked paid, as the webhook would have.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $order_id           The order.
	 * @param string $checkout_reference The payment the check was scheduled for.
	 * @param int    $attempt            Which check this is: EARLY_CHECK, or 1 and up after expiry.
	 * @return void
	 */
	public static function check_order( $order_id, $checkout_reference, $attempt = 1 ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$checkout_reference = (string) $checkout_reference;
		$attempt            = max( self::EARLY_CHECK, (int) $attempt );

		if ( WC_Straumur_Settings::KORTALAN_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		if ( '' === $checkout_reference || (string) $order->get_meta( WC_Straumur_Return_Guard::CHECKOUT_REFERENCE_META ) !== $checkout_reference ) {
			return;
		}

		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
			return;
		}

		$was_cancelled = $order->has_status( 'cancelled' );

		$api     = new WC_Straumur_API();
		$status  = $api->get_kortalan_status( $checkout_reference );
		$outcome = self::outcome( $status );

		if ( self::OUTCOME_PAID === $outcome ) {
			if ( self::record_paid( $order, self::payfac_reference( $status ) ) && $was_cancelled ) {
				$order->add_order_note(
					esc_html__( 'This order had been cancelled as unpaid, but the Straumur Kortalán payment went through, so it was marked paid. Check stock before fulfilling it.', 'straumur-payments-for-woocommerce' )
				);
			}
			return;
		}

		// The early check only looks for an approved loan. A cancelled order needs nothing more either.
		if ( self::EARLY_CHECK === $attempt ) {
			return;
		}

		if ( $was_cancelled ) {
			// The shopper may still be in the application: keep looking until the chain ends.
			if ( self::OUTCOME_FAILED !== $outcome && $attempt < self::MAX_CHECKS ) {
				self::schedule_check( $order->get_id(), $checkout_reference, self::RECHECK_DELAY, $attempt + 1 );
			}
			return;
		}

		if ( self::OUTCOME_FAILED === $outcome ) {
			$order->update_status(
				'cancelled',
				esc_html__( 'The Straumur Kortalán payment was not completed. Order cancelled.', 'straumur-payments-for-woocommerce' )
			);
			return;
		}

		if ( $attempt < self::MAX_CHECKS ) {
			self::schedule_check( $order->get_id(), $checkout_reference, self::RECHECK_DELAY, $attempt + 1 );
			return;
		}

		$order->add_order_note(
			esc_html__( 'Straumur could not confirm whether the Straumur Kortalán payment went through. Look the payment up in the Straumur merchant portal before fulfilling or cancelling this order.', 'straumur-payments-for-woocommerce' )
		);
	}
}
