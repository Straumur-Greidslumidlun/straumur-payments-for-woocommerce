<?php
/**
 * Tests for what the Straumur Kortalán gateway reads from Straumur's answers.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

namespace Straumur\Payments\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Straumur\Payments\WC_Straumur_Kortalan;
use Straumur\Payments\WC_Straumur_Settings;
use WC_Order;

/**
 * The option is offered only on an explicit yes, an order is resolved only on a status the
 * plugin knows, and an order the webhook already paid is not paid again.
 */
final class KortalanTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		// WC_Straumur_Settings caches the option per request.
		$cache = new \ReflectionProperty( WC_Straumur_Settings::class, 'cached_settings' );
		$cache->setValue( null, array() );

		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Availability: only an explicit yes shows the option.
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{mixed, bool}>
	 */
	public static function availability_answers(): array {
		return array(
			'listed'                  => array( array( 'paymentMethods' => array( 'Cards', 'Kortalan' ) ), true ),
			'listed in another case'  => array( array( 'paymentMethods' => array( 'kortalan' ) ), true ),
			'not listed'              => array( array( 'paymentMethods' => array( 'Cards', 'ApplePay' ) ), false ),
			'empty list'              => array( array( 'paymentMethods' => array() ), false ),
			'list is not a list'      => array( array( 'paymentMethods' => 'Kortalan' ), false ),
			'only a longer name'      => array( array( 'paymentMethods' => array( 'KortalanPos' ) ), false ),
			'entry is not a string'   => array( array( 'paymentMethods' => array( true ) ), false ),
			'field missing'           => array( array( 'responseIdentifier' => 'abc' ), false ),
			'no answer from Straumur' => array( false, false ),
		);
	}

	/**
	 * @param mixed $response
	 */
	#[DataProvider( 'availability_answers' )]
	public function test_only_a_store_that_lists_kortalan_makes_it_available( $response, bool $expected ): void {
		$this->assertSame( $expected, WC_Straumur_Kortalan::is_available_answer( $response ) );
	}

	public function test_without_a_terminal_it_is_never_available_and_straumur_is_not_asked(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		// No get_transient() stub: reaching the cache or the API would fail the test.
		$this->assertFalse( WC_Straumur_Kortalan::is_available() );
	}

	public function test_a_cached_answer_is_used_without_asking_straumur(): void {
		Functions\when( 'get_option' )->justReturn( array( 'terminal_identifier' => 'abc123', 'test_mode' => 'yes' ) );
		Functions\when( 'get_transient' )->justReturn( 'yes' );

		$this->assertTrue( WC_Straumur_Kortalan::is_available() );
	}

	public function test_cache_key_differs_by_mode(): void {
		Functions\when( 'get_option' )->justReturn( array( 'terminal_identifier' => 'abc123', 'test_mode' => 'yes' ) );
		$test_key = WC_Straumur_Kortalan::availability_cache_key();

		$cache = new \ReflectionProperty( WC_Straumur_Settings::class, 'cached_settings' );
		$cache->setValue( null, array() );
		Functions\when( 'get_option' )->justReturn( array( 'terminal_identifier' => 'abc123', 'test_mode' => 'no' ) );

		$this->assertNotSame( $test_key, WC_Straumur_Kortalan::availability_cache_key() );
		// Transient names are limited to 172 characters.
		$this->assertLessThan( 172, strlen( $test_key ) );
	}

	// -----------------------------------------------------------------
	// Terminal: Kortalán's own when set, otherwise the card terminal.
	// -----------------------------------------------------------------

	/**
	 * Stub the two settings options separately.
	 *
	 * @param array $main     Straumur Payments settings.
	 * @param array $kortalan Straumur Kortalán settings.
	 */
	private function stub_settings( array $main, array $kortalan ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( $main, $kortalan ) {
				return WC_Straumur_Settings::KORTALAN_OPTION_KEY === $name ? $kortalan : $main;
			}
		);
	}

	public function test_kortalan_uses_the_card_terminal_when_it_has_none_of_its_own(): void {
		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array( 'enabled' => 'yes' ) );

		$this->assertSame( 'card111', WC_Straumur_Settings::get_kortalan_terminal_identifier() );
	}

	public function test_kortalan_uses_its_own_terminal_when_one_is_set(): void {
		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array( 'inherit_terminal' => 'no', 'terminal_identifier' => ' kor222 ' ) );

		$this->assertSame( 'kor222', WC_Straumur_Settings::get_kortalan_terminal_identifier() );
	}

	public function test_a_blank_kortalan_terminal_falls_back_to_the_card_terminal(): void {
		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array( 'inherit_terminal' => 'no', 'terminal_identifier' => '   ' ) );

		$this->assertSame( 'card111', WC_Straumur_Settings::get_kortalan_terminal_identifier() );
	}

	public function test_a_kortalan_terminal_is_ignored_while_the_card_terminal_box_is_ticked(): void {
		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array( 'inherit_terminal' => 'yes', 'terminal_identifier' => 'kor222' ) );

		$this->assertSame( 'card111', WC_Straumur_Settings::get_kortalan_terminal_identifier() );
	}

	public function test_availability_is_cached_per_kortalan_terminal(): void {
		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array() );
		$on_card_terminal = WC_Straumur_Kortalan::availability_cache_key();

		$this->stub_settings( array( 'terminal_identifier' => 'card111' ), array( 'inherit_terminal' => 'no', 'terminal_identifier' => 'kor222' ) );

		$this->assertNotSame( $on_card_terminal, WC_Straumur_Kortalan::availability_cache_key() );
	}

	// -----------------------------------------------------------------
	// Status answers.
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function status_answers(): array {
		return array(
			'completed'          => array( array( 'status' => 'Completed', 'payfacReference' => 'PF1' ), WC_Straumur_Kortalan::OUTCOME_PAID ),
			'still open'         => array( array( 'status' => 'New' ), WC_Straumur_Kortalan::OUTCOME_WAITING ),
			'expired'            => array( array( 'status' => 'Expired' ), WC_Straumur_Kortalan::OUTCOME_FAILED ),
			'refused'            => array( array( 'status' => 'Refused' ), WC_Straumur_Kortalan::OUTCOME_FAILED ),
			'cancelled'          => array( array( 'status' => 'Cancelled' ), WC_Straumur_Kortalan::OUTCOME_FAILED ),
			'status not known'   => array( array( 'status' => 'SomethingNew' ), WC_Straumur_Kortalan::OUTCOME_UNKNOWN ),
			'status missing'     => array( array( 'payfacReference' => 'PF1' ), WC_Straumur_Kortalan::OUTCOME_UNKNOWN ),
			'status not a string' => array( array( 'status' => true ), WC_Straumur_Kortalan::OUTCOME_UNKNOWN ),
			'no answer'          => array( false, WC_Straumur_Kortalan::OUTCOME_UNKNOWN ),
		);
	}

	/**
	 * @param mixed $response
	 */
	#[DataProvider( 'status_answers' )]
	public function test_status_answer_maps_to_an_outcome( $response, string $expected ): void {
		$this->assertSame( $expected, WC_Straumur_Kortalan::outcome( $response ) );
	}

	// -----------------------------------------------------------------
	// Marking an order paid from a status answer.
	// -----------------------------------------------------------------

	public function test_unpaid_order_is_marked_paid_with_a_kortalan_note(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$order = new WC_Order( 2001, WC_Straumur_Settings::KORTALAN_GATEWAY_ID );

		$this->assertTrue( WC_Straumur_Kortalan::record_paid( $order, 'PF777' ) );

		$this->assertTrue( $order->paid );
		$this->assertSame( 'PF777', $order->get_meta( '_straumur_payfac_reference' ) );
		$this->assertSame(
			array( 'Straumur confirmed the Straumur Kortalán payment. Reference: PF777.' ),
			$order->notes
		);
	}

	public function test_order_the_webhook_already_paid_gets_no_second_note(): void {
		$order = new WC_Order( 2002, WC_Straumur_Settings::KORTALAN_GATEWAY_ID );
		$order->payment_complete();

		$this->assertFalse( WC_Straumur_Kortalan::record_paid( $order, 'PF778' ) );

		$this->assertSame( array(), $order->notes );
		$this->assertSame( 'PF778', $order->get_meta( '_straumur_payfac_reference' ) );
	}

	// -----------------------------------------------------------------
	// What the shopper is told when the payment cannot be started.
	// -----------------------------------------------------------------

	public function test_shopper_is_asked_to_pick_another_method_when_the_amount_is_not_financed(): void {
		$message = WC_Straumur_Kortalan::start_error_message( WC_Straumur_Kortalan::ERROR_NOT_AVAILABLE );

		$this->assertStringContainsString( 'not available for this order amount', $message );
		$this->assertStringContainsString( 'choose another payment method', $message );
	}

	public function test_every_start_error_asks_for_another_payment_method(): void {
		foreach ( array( '', '2040', '2011', '9999' ) as $code ) {
			$this->assertStringContainsString( 'another payment method', WC_Straumur_Kortalan::start_error_message( $code ) );
		}
	}

	public function test_both_gateway_ids_are_this_plugins(): void {
		$this->assertTrue( WC_Straumur_Settings::is_straumur_gateway( 'straumur' ) );
		$this->assertTrue( WC_Straumur_Settings::is_straumur_gateway( 'straumur_kortalan' ) );
		$this->assertFalse( WC_Straumur_Settings::is_straumur_gateway( 'bacs' ) );
		$this->assertFalse( WC_Straumur_Settings::is_straumur_gateway( '' ) );
	}
}
