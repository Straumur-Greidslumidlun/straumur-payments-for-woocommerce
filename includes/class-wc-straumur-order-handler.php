<?php

/**
 * Straumur Order Handler Class
 *
 * Handles order status transitions for Straumur payments.
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Order;
use WP_Error;
use WC_Logger;

use function wc_get_logger;
use function wc_get_order;
use function round;
use function get_woocommerce_currency;

class WC_Straumur_Order_Handler {

	/**
	 * Logger instance.
	 *
	 * @var \WC_Logger
	 */
	private WC_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->logger = wc_get_logger();
	}

	/**
	 * Handle cancellation (On-hold -> Cancelled).
	 *
	 * @param int $order_id Order ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function handle_cancellation( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', __( 'Invalid order.', 'straumur-payments-for-woocommerce' ) );
		}

		if ( $order->get_payment_method() !== 'straumur' ) {
			return new WP_Error( 'not_straumur', __( 'Order not paid with Straumur.', 'straumur-payments-for-woocommerce' ) );
		}

		$payfac_reference = $order->get_meta( '_straumur_payfac_reference' );
		$reference        = $order->get_order_number();

		if ( empty( $payfac_reference ) || empty( $reference ) ) {
			return new WP_Error( 'no_reference', __( 'Cannot cancel: missing payment references.', 'straumur-payments-for-woocommerce' ) );
		}

		$api = new WC_Straumur_API();
		$this->logger->info( "Attempting Straumur payment cancellation for order #{$order_id}", array( 'source' => 'straumur-payments-for-woocommerce' ) );

		$response = $api->reverse( $reference, $payfac_reference );
		if ( $response ) {
			// Mark as "cancel requested" so that the webhook can recognize it as a cancellation event
			$order->update_meta_data( '_straumur_cancel_requested', 'yes' );
			$order->save();

			$order->add_order_note( __( 'Straumur payment cancellation request sent', 'straumur-payments-for-woocommerce' ) );
			return true;
		}

		$this->logger->error( "Cancellation request failed for order #{$order_id}", array( 'source' => 'straumur-payments' ) );
		return new WP_Error( 'cancellation_failed', __( 'Failed to send cancellation request to Straumur.', 'straumur-payments-for-woocommerce' ) );
	}

	/**
	 * Handle capture (On-hold -> Processing).
	 *
	 * @param int $order_id Order ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function handle_capture( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', __( 'Invalid order.', 'straumur-payments-for-woocommerce' ) );
		}

		if ( $order->get_payment_method() !== 'straumur' ) {
			return new WP_Error( 'not_straumur', __( 'Order not paid with Straumur.', 'straumur-payments-for-woocommerce' ) );
		}

		$payfac_reference = $order->get_meta( '_straumur_payfac_reference' );
		$reference        = $order->get_order_number();
		if ( empty( $payfac_reference ) || empty( $reference ) ) {
			return new WP_Error( 'no_reference', __( 'Cannot capture: missing payment references.', 'straumur-payments-for-woocommerce' ) );
		}

		$amount       = (float) $order->get_total();
		$amount_minor = (int) round( $amount * 100 );
		$currency     = get_woocommerce_currency();

		$api = new WC_Straumur_API();

		$response = $api->capture( $payfac_reference, $reference, $amount_minor, $currency );
		if ( $response ) {
			$order->payment_complete();
			$order->add_order_note( __( 'Straumur payment capture request sent', 'straumur-payments-for-woocommerce' ) );
			return true;
		}

		$this->logger->error( "Capture request failed for order #{$order_id}", array( 'source' => 'straumur-payments' ) );
		return new WP_Error( 'capture_failed', __( 'Failed to send capture request to Straumur.', 'straumur-payments-for-woocommerce' ) );
	}

	/**
	 * Handle refund (Processing -> Refunded).
	 *
	 * @param int $order_id Order ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function handle_refund( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', __( 'Invalid order.', 'straumur-payments-for-woocommerce' ) );
		}

		// Ensure order was paid with Straumur
		if ( $order->get_payment_method() !== 'straumur' ) {
			return new WP_Error( 'not_straumur', __( 'Order not paid with Straumur.', 'straumur-payments-for-woocommerce' ) );
		}

		$payfac_reference = $order->get_meta( '_straumur_payfac_reference' );
		$reference        = $order->get_order_number();

		if ( empty( $payfac_reference ) || empty( $reference ) ) {
			return new WP_Error( 'no_reference', __( 'Cannot refund: missing payment references.', 'straumur-payments-for-woocommerce' ) );
		}

		$api = new WC_Straumur_API();
		$this->logger->info( "Attempting Straumur payment refund for order #{$order_id}", array( 'source' => 'straumur-payments' ) );

		// Based on instructions, refund also uses reverse call
		$response = $api->reverse( $reference, $payfac_reference );
		if ( $response ) {
			// Mark as "refund requested" so that the webhook can recognize it as a refund event
			$order->update_meta_data( '_straumur_refund_requested', 'yes' );
			$order->save();

			$order->add_order_note( __( 'Straumur refund request has been sent to Straumur.', 'straumur-payments-for-woocommerce' ) );
			$this->logger->info( "Refund request successful for order #{$order_id}", array( 'source' => 'straumur-payments' ) );

			// Check if this order is related to a subscription and cancel it
			if ( function_exists( 'wcs_order_contains_subscription' ) || function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$subscriptions = function_exists( 'wcs_get_subscriptions_for_order' ) ?
					wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) ) : array();

				if ( ! empty( $subscriptions ) ) {
					foreach ( $subscriptions as $subscription ) {
						if ( $subscription->has_status( 'active' ) ) {
							$subscription->update_status( 'cancelled', __( 'Subscription cancelled due to refunded payment.', 'straumur-payments-for-woocommerce' ) );
							$this->logger->info( "Subscription #{$subscription->get_id()} cancelled due to refund of order #{$order_id}", array( 'source' => 'straumur-payments' ) );
						}
					}
				}
			}

			return true;
		}

		$this->logger->error( "Refund request failed for order #{$order_id}", array( 'source' => 'straumur-payments' ) );
		return new WP_Error( 'refund_failed', __( 'Failed to send refund request to Straumur.', 'straumur-payments-for-woocommerce' ) );
	}
}
