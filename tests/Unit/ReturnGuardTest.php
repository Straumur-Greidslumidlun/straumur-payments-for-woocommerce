<?php
/**
 * Tests for the check on returns from Hosted Checkout.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

namespace Straumur\Payments\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Straumur\Payments\WC_Straumur_Return_Guard;
use WC_Order;

/**
 * A return may only use the payment session this shop created for the order, and only when it
 * carries that order's key.
 */
final class ReturnGuardTest extends TestCase {

	private const OWN_SESSION   = 'own-session-17';
	private const OTHER_SESSION = 'other-session-18';

	private function order_with_session( int $id = 17, string $payment_method = 'straumur' ): WC_Order {
		$order = new WC_Order( $id, $payment_method );
		$order->update_meta_data( WC_Straumur_Return_Guard::CHECKOUT_REFERENCE_META, self::OWN_SESSION );

		return $order;
	}

	public function test_return_with_the_order_key_and_its_own_session_is_accepted(): void {
		$order = $this->order_with_session();

		$this->assertSame(
			self::OWN_SESSION,
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order->get_order_key(), self::OWN_SESSION )
		);
	}

	public function test_return_without_a_checkout_reference_uses_the_stored_session(): void {
		$order = $this->order_with_session();

		$this->assertSame(
			self::OWN_SESSION,
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order->get_order_key(), '' )
		);
	}

	public function test_session_of_another_order_is_refused_even_with_the_order_key(): void {
		$order = $this->order_with_session();

		$this->assertSame(
			'',
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order->get_order_key(), self::OTHER_SESSION )
		);
	}

	public function test_return_without_the_order_key_is_refused(): void {
		$order = $this->order_with_session();

		$this->assertSame( '', WC_Straumur_Return_Guard::bound_checkout_reference( $order, '', self::OWN_SESSION ) );
	}

	public function test_return_with_the_key_of_another_order_is_refused(): void {
		$order = $this->order_with_session( 17 );
		$other = $this->order_with_session( 18 );

		$this->assertSame(
			'',
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $other->get_order_key(), self::OWN_SESSION )
		);
	}

	public function test_order_without_a_stored_session_is_refused(): void {
		// An order whose session was created before 2.2.4, or one that never went to Straumur.
		$order = new WC_Order( 17 );

		$this->assertSame(
			'',
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order->get_order_key(), self::OTHER_SESSION )
		);
	}

	public function test_order_paid_with_another_gateway_is_refused(): void {
		$order = $this->order_with_session( 17, 'bacs' );

		$this->assertSame(
			'',
			WC_Straumur_Return_Guard::bound_checkout_reference( $order, $order->get_order_key(), self::OWN_SESSION )
		);
	}
}
