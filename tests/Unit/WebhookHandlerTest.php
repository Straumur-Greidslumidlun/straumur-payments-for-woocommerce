<?php
/**
 * Tests for the Straumur webhook endpoint.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

namespace Straumur\Payments\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Straumur\Payments\WC_Straumur_Settings;
use Straumur\Payments\WC_Straumur_Webhook_Handler;
use Test_Logger;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Sends webhooks through the endpoint the way WordPress's REST server does: the permission
 * callback (signature check) first, and the callback only when it passes.
 */
final class WebhookHandlerTest extends TestCase {

	private const HMAC_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** @var array<int, WC_Order> Orders wc_get_order() can find, by ID. */
	private array $orders = array();

	private Test_Logger $logger;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orders = array();
		$this->logger = new Test_Logger();
		$this->use_settings( array( 'hmac_key' => self::HMAC_KEY ) );

		// The handler checks $wpdb before deciding whether to use a transaction.
		$GLOBALS['wpdb'] = new \stdClass();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'sanitize_text_field' => static function ( $value ) {
					return trim( (string) $value );
				},
				'absint'              => static function ( $value ) {
					return abs( (int) $value );
				},
				'wp_json_encode'      => static function ( $value ) {
					return json_encode( $value );
				},
				'wp_parse_url'        => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				},
				'is_wp_error'         => static function ( $thing ) {
					return $thing instanceof WP_Error;
				},
				'current_time'        => '2026-11-09 12:00:00',
				'wc_get_logger'       => function () {
					return $this->logger;
				},
				'wc_get_order'        => function ( $id ) {
					return $this->orders[ $id ] ?? false;
				},
			)
		);
	}

	protected function tearDown(): void {
		$this->use_settings( array() );
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Events that are not for an order of this shop: 200, nothing changed.
	// -----------------------------------------------------------------

	public function test_signed_event_without_merchant_or_checkout_reference_is_acknowledged(): void {
		// A terminal payment or payment link: Straumur signs the missing references as "".
		$payload = $this->event();
		unset( $payload['merchantReference'], $payload['checkoutReference'] );

		$response = $this->send( $payload );

		$this->assert_ignored( $response );
		$this->assertStringContainsString( 'Ignored webhook (no_reference)', $this->logger->text() );
	}

	public function test_signed_event_with_null_references_is_acknowledged(): void {
		$payload                      = $this->event();
		$payload['merchantReference'] = null;
		$payload['checkoutReference'] = null;

		$this->assert_ignored( $this->send( $payload ) );
	}

	/**
	 * References that are not an order ID, including ones that start with one: before 2.2.3
	 * "742eac9d-..." was read as order 742.
	 *
	 * @return array<string, array{string}>
	 */
	public static function references_that_are_not_order_ids(): array {
		return array(
			'uuid starting with digits' => array( '742eac9d-30b1-4c55-9a3e-1f0c2b7d8e11' ),
			'digits then letters'       => array( '742abc' ),
			'negative number'           => array( '-742' ),
			'leading zero'              => array( '0742' ),
			'exponent'                  => array( '7e2' ),
			'surrounding space'         => array( ' 742' ),
			'trailing newline'          => array( "742\n" ),
			'decimal'                   => array( '742.0' ),
			'zero'                      => array( '0' ),
			'too long'                  => array( '7420000000000000000000' ),
			'text'                      => array( 'POS-RECEIPT-0001' ),
		);
	}

	#[DataProvider( 'references_that_are_not_order_ids' )]
	public function test_reference_that_is_not_an_order_id_is_acknowledged_and_no_order_touched( string $reference ): void {
		$order               = new WC_Order( 742 );
		$this->orders[742]   = $order;
		$this->orders[7]     = new WC_Order( 7 );
		$this->orders[700]   = new WC_Order( 700 );

		$response = $this->send( $this->event( array( 'merchantReference' => $reference ) ) );

		$this->assert_ignored( $response );
		$this->assertTrue( $order->is_untouched() );
		$this->assertTrue( $this->orders[7]->is_untouched() );
		$this->assertTrue( $this->orders[700]->is_untouched() );
		$this->assertStringContainsString( 'Ignored webhook (not_an_order_id)', $this->logger->text() );
	}

	public function test_event_for_an_order_this_shop_does_not_have_is_acknowledged(): void {
		$response = $this->send( $this->event( array( 'merchantReference' => '4470' ) ) );

		$this->assert_ignored( $response );
		$this->assertStringContainsString( 'Ignored webhook (order_not_found)', $this->logger->text() );
	}

	public function test_event_for_an_order_paid_with_another_gateway_does_not_touch_it(): void {
		// A foreign payment whose reference happens to equal an unpaid bank transfer order.
		$order             = new WC_Order( 1234, 'bacs' );
		$this->orders[1234] = $order;

		$response = $this->send( $this->event( array( 'merchantReference' => '1234' ) ) );

		$this->assert_ignored( $response );
		$this->assertTrue( $order->is_untouched() );
		$this->assertFalse( $order->paid );
		$this->assertStringContainsString( 'Ignored webhook (other_payment_method)', $this->logger->text() );
	}

	public function test_failed_event_for_an_order_this_shop_does_not_have_is_acknowledged(): void {
		$payload = $this->event(
			array(
				'merchantReference' => 'a1b2c3',
				'success'           => 'false',
				'reason'            => 'Refused',
			)
		);

		$this->assert_ignored( $this->send( $payload ) );
	}

	public function test_ignored_event_log_holds_no_card_data(): void {
		$this->send( $this->event( array( 'merchantReference' => '742eac9d-30b1' ) ) );

		$log = $this->logger->text();
		$this->assertStringContainsString( 'Ignored webhook', $log );
		$this->assertStringNotContainsString( '411111', $log );
		$this->assertStringNotContainsString( 'A1B2C3', $log );
		$this->assertStringNotContainsString( 'hmacSignature', $log );
	}

	// -----------------------------------------------------------------
	// Requests that fail verification keep their 4xx and change nothing.
	// -----------------------------------------------------------------

	public function test_wrong_signature_is_rejected_with_403_and_order_untouched(): void {
		$order            = new WC_Order( 55 );
		$this->orders[55] = $order;

		$payload                  = $this->event( array( 'merchantReference' => '55' ) );
		$payload['hmacSignature'] = $this->sign( $payload, str_repeat( 'ab', 32 ) );

		$response = $this->send( $payload, false );

		$this->assertSame( 403, $this->status_of( $response ) );
		$this->assertSame( 'straumur_invalid_signature', $response->get_error_code() );
		$this->assertTrue( $order->is_untouched() );
	}

	public function test_signature_over_other_values_is_rejected(): void {
		// Moving an event to another order invalidates its signature.
		$this->orders[56]             = new WC_Order( 56 );
		$payload                      = $this->event( array( 'merchantReference' => '55' ) );
		$payload['hmacSignature']     = $this->sign( $payload );
		$payload['merchantReference'] = '56';

		$response = $this->send( $payload, false );

		$this->assertSame( 403, $this->status_of( $response ) );
		$this->assertTrue( $this->orders[56]->is_untouched() );
	}

	public function test_event_without_merchant_reference_and_wrong_signature_is_rejected(): void {
		$payload = $this->event();
		unset( $payload['merchantReference'] );
		$payload['hmacSignature'] = base64_encode( str_repeat( "\0", 32 ) );

		$this->assertSame( 403, $this->status_of( $this->send( $payload, false ) ) );
	}

	public function test_missing_signature_is_rejected_with_400(): void {
		$payload = $this->event();
		unset( $payload['hmacSignature'] );

		$response = $this->send( $payload, false );

		$this->assertSame( 400, $this->status_of( $response ) );
		$this->assertSame( 'straumur_missing_field', $response->get_error_code() );
	}

	public function test_non_string_signature_is_rejected_with_400(): void {
		$payload                  = $this->event();
		$payload['hmacSignature'] = array( 'not', 'a', 'signature' );

		$this->assertSame( 400, $this->status_of( $this->send( $payload, false ) ) );
	}

	public function test_missing_hmac_key_in_settings_rejects_with_403(): void {
		$this->use_settings( array() );

		$this->assertSame( 403, $this->status_of( $this->send( $this->event() ) ) );
	}

	public function test_invalid_json_is_rejected_with_400(): void {
		$request = new WP_REST_Request( 'POST', '/straumur/v1/payment-callback' );
		$request->set_body( '{not json' );

		$result = WC_Straumur_Webhook_Handler::check_webhook_hmac( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------
	// Events for this shop's orders are processed as before.
	// -----------------------------------------------------------------

	public function test_authorization_for_a_straumur_order_marks_it_paid(): void {
		$order             = new WC_Order( 1001 );
		$this->orders[1001] = $order;

		$response = $this->send( $this->event( array( 'merchantReference' => '1001' ) ) );

		$this->assertSame( 200, $this->status_of( $response ) );
		$this->assertArrayNotHasKey( 'ignored', $response->get_data() );
		$this->assertTrue( $order->paid );
		$this->assertStringContainsString( 'Payment captured automatically', implode( "\n", $order->notes ) );
	}

	public function test_numeric_merchant_reference_in_json_is_an_order_id(): void {
		$order              = new WC_Order( 1002 );
		$this->orders[1002] = $order;

		$payload                      = $this->event( array( 'merchantReference' => '1002' ) );
		$payload['merchantReference'] = 1002;

		$this->assertSame( 200, $this->status_of( $this->send( $payload ) ) );
		$this->assertTrue( $order->paid );
	}

	public function test_failed_event_for_a_straumur_order_records_the_failure(): void {
		$order              = new WC_Order( 1003 );
		$this->orders[1003] = $order;

		$payload = $this->event(
			array(
				'merchantReference' => '1003',
				'success'           => 'false',
				'reason'            => 'Refused',
			)
		);

		$response = $this->send( $payload );

		$this->assertSame( 200, $this->status_of( $response ) );
		$this->assertFalse( $order->paid );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertStringContainsString( 'refused', implode( "\n", $order->notes ) );
		$this->assertNotSame( '', $order->get_meta( '_straumur_last_failure' ) );
	}

	public function test_processing_error_answers_500_so_straumur_retries(): void {
		$order               = new WC_Order( 1004 );
		$order->fail_on_save = new \RuntimeException( 'Deadlock found' );
		$this->orders[1004]  = $order;

		$payload = $this->event(
			array(
				'merchantReference' => '1004',
				'success'           => 'false',
				'reason'            => 'Refused',
			)
		);

		$response = $this->send( $payload );

		$this->assertSame( 500, $this->status_of( $response ) );
		$this->assertSame( 'straumur_processing_error', $response->get_error_code() );
	}

	public function test_type_error_while_processing_answers_500(): void {
		$order             = new WC_Order( 1005 );
		$order->fail_on_save = new \TypeError( 'Unexpected type' );
		$this->orders[1005] = $order;

		$payload = $this->event(
			array(
				'merchantReference' => '1005',
				'success'           => 'false',
			)
		);

		$this->assertSame( 500, $this->status_of( $this->send( $payload ) ) );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * A successful card authorization as Straumur sends it.
	 *
	 * @param array<string, mixed> $overrides Top-level fields to replace.
	 * @return array<string, mixed>
	 */
	private function event( array $overrides = array() ): array {
		return array_merge(
			array(
				'checkoutReference' => 'c0ffee00-0000-4000-8000-000000000001',
				'payfacReference'   => 'PF123456789',
				'merchantReference' => '1',
				'amount'            => '1000',
				'currency'          => 'ISK',
				'reason'            => '',
				'success'           => 'true',
				'hmacSignature'     => '',
				'additionalData'    => array(
					'eventType'           => 'Authorization',
					'paymentMethod'       => 'VI',
					'cardNumber'          => '411111******1111',
					'authCode'            => 'A1B2C3',
					'threeDAuthenticated' => 'true',
				),
			),
			$overrides
		);
	}

	/**
	 * Sign the payload the way Straumur's backend does (HmacHelpers.GetHmacSignature): the
	 * signed fields joined with ':', a missing or null field as an empty string, HMAC-SHA256
	 * with the hex key, base64.
	 */
	private function sign( array $payload, string $hex_key = self::HMAC_KEY ): string {
		$fields = array( 'checkoutReference', 'payfacReference', 'merchantReference', 'amount', 'currency', 'reason', 'success' );
		$values = array();
		foreach ( $fields as $field ) {
			$values[] = (string) ( $payload[ $field ] ?? '' );
		}

		return base64_encode( hash_hmac( 'sha256', implode( ':', $values ), (string) hex2bin( $hex_key ), true ) );
	}

	/**
	 * Run the payload through the permission callback and, if it passes, the callback.
	 *
	 * @param array<string, mixed> $payload Webhook body.
	 * @param bool                 $sign    Whether to sign the payload with the shop's key first.
	 * @return WP_REST_Response|WP_Error
	 */
	private function send( array $payload, bool $sign = true ) {
		if ( $sign ) {
			$payload['hmacSignature'] = $this->sign( $payload );
		}

		$request = new WP_REST_Request( 'POST', '/straumur/v1/payment-callback' );
		$request->set_body( (string) json_encode( $payload ) );

		$permission = WC_Straumur_Webhook_Handler::check_webhook_hmac( $request );
		if ( true !== $permission ) {
			return $permission;
		}

		return WC_Straumur_Webhook_Handler::handle_payment_callback( $request );
	}

	/**
	 * @param WP_REST_Response|WP_Error $response
	 */
	private function status_of( $response ): int {
		if ( $response instanceof WP_Error ) {
			return $response->get_error_data()['status'];
		}

		return $response->get_status();
	}

	/**
	 * @param WP_REST_Response|WP_Error $response
	 */
	private function assert_ignored( $response ): void {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'message' => 'Ignored: not an order of this shop.',
				'ignored' => true,
			),
			$response->get_data()
		);
	}

	/**
	 * Replace the stored gateway settings. WC_Straumur_Settings caches the option per request.
	 *
	 * @param array<string, mixed> $settings Gateway settings.
	 */
	private function use_settings( array $settings ): void {
		$cache = new \ReflectionProperty( WC_Straumur_Settings::class, 'cached_settings' );
		$cache->setValue( null, array() );

		Functions\when( 'get_option' )->justReturn( $settings );
	}
}
