<?php
/**
 * Straumur Settings Class
 *
 * Provides and validates the settings fields used by the Straumur payment gateway.dsd
 *
 * @package Straumur\Payments
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

use WC_Admin_Settings;
use function esc_html__;
use function esc_url_raw;
use function home_url;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Straumur_Settings
 *
 * @since 1.0.0
 */
class WC_Straumur_Settings {

	/**
	 * Option key for Straumur settings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static string $option_key = 'woocommerce_straumur_settings';

	/**
	 * Caches the Straumur settings to prevent repeated database calls.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private static array $cached_settings = array();

	/**
	 * Retrieve the gateway form fields for the settings page (all fields).
	 * We'll split these into tabs in the gateway class.
	 *
	 * @since 1.0.0
	 * @return array Settings fields.
	 */
	public static function get_form_fields(): array {
		$webhook_url = home_url( '/wp-json/straumur/v1/payment-callback' );

		return array(
			'enabled'                     => array(
				'title'   => esc_html__( 'Enable/Disable', 'straumur-payments-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => esc_html__( 'Enable Straumur Payments', 'straumur-payments-for-woocommerce' ),
				'default' => 'yes',
			),
			'test_mode'                   => array(
				'title'       => esc_html__( 'Test Mode', 'straumur-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Enable Test Mode', 'straumur-payments-for-woocommerce' ),
				'default'     => 'yes',
				'description' => esc_html__( 'If enabled, the gateway will use the sandbox URL.', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => false,
			),
			'title'                       => array(
				'title'       => esc_html__( 'Title', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__(
					'This controls the title which the user sees during checkout.',
					'straumur-payments-for-woocommerce'
				),
				'default'     => esc_html__( 'Straumur Payments', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'                 => array(
				'title'       => esc_html__( 'Description', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__(
					'This controls the description which the user sees during checkout.',
					'straumur-payments-for-woocommerce'
				),
				'default'     => esc_html__( 'Pay via Straumur Hosted Checkout.', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'theme_key'                   => array(
				'title'       => esc_html__( 'Theme key', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'Theme key, logo colors, etc.', 'straumur-payments-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'authorize_only'              => array(
				'title'       => esc_html__( 'Authorize Only (Manual Capture)', 'straumur-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Enable authorize only mode. Payments will require manual capture.', 'straumur-payments-for-woocommerce' ),
				'default'     => 'no',
				'description' => esc_html__( 'If enabled, payments will be authorized but not captured automatically.', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => false,
			),
			'complete_order_on_payment'   => array(
				'title'       => esc_html__( 'Mark Order as Completed', 'straumur-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => esc_html__(
					'If enabled, orders will be marked as Completed after a successful payment instead of Processing.',
					'straumur-payments-for-woocommerce'
				),
				'default'     => 'no',
			),
			'items'                       => array(
				'title'       => esc_html__( 'Send cart items', 'straumur-payments-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => esc_html__(
					'Send cart items to the checkout page. Disable if using incompatible plugins.',
					'straumur-payments-for-woocommerce'
				),
				'default'     => 'yes',
				'desc_tip'    => false,
			),
			'checkout_expiry'             => array(
				'title'       => esc_html__( 'Checkout Expiry (hours)', 'straumur-payments-for-woocommerce' ),
				'type'        => 'select',
				'default'     => '1',
				'options'     => array(
					'0.0833' => esc_html__( '5 minutes', 'straumur-payments-for-woocommerce' ),
					'0.1667' => esc_html__( '10 minutes', 'straumur-payments-for-woocommerce' ),
					'0.25'   => esc_html__( '15 minutes', 'straumur-payments-for-woocommerce' ),
					'0.5'    => esc_html__( '30 minutes', 'straumur-payments-for-woocommerce' ),
					'1'      => esc_html__( '1 hour', 'straumur-payments-for-woocommerce' ),
					'2'      => esc_html__( '2 hours', 'straumur-payments-for-woocommerce' ),
					'4'      => esc_html__( '4 hours', 'straumur-payments-for-woocommerce' ),
					'6'      => esc_html__( '6 hours', 'straumur-payments-for-woocommerce' ),
					'12'     => esc_html__( '12 hours', 'straumur-payments-for-woocommerce' ),
					'24'     => esc_html__( '24 hours', 'straumur-payments-for-woocommerce' ),
				),
				'description' => esc_html__( 'How long before checkout sessions expire (approx).', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'abandon_url'                 => array(
				'title'       => esc_html__( 'Abandon URL', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'Optional URL to redirect shoppers if they cancel or abandon the payment page.', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'custom_success_url'          => array(
				'title'       => esc_html__( 'Custom Success URL', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'Optional URL to redirect shoppers after a successful payment. If left empty, the default WooCommerce thank-you page is used.', 'straumur-payments-for-woocommerce' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'terminal_identifier'         => array(
				'title'       => esc_html__( 'Payment Page Terminal Identifier', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'The Terminal Identifier provided by Straumur.', 'straumur-payments-for-woocommerce' ),
				'default'     => 'd67efaf00c8c',
				'desc_tip'    => true,
			),
			'gateway_terminal_identifier' => array(
				'title'       => esc_html__( 'Payment Gateway Terminal Identifier', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'The Terminal Identifier provided by Straumur.', 'straumur-payments-for-woocommerce' ),
				'default'     => '0531e49886d1',
				'desc_tip'    => true,
			),
			'api_key'                     => array(
				'title'       => esc_html__( 'API Key', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'The API Key provided by Straumur.', 'straumur-payments-for-woocommerce' ),
				'default'     => '8ee1da747aafad5a281807dd837489172370ad5b710b8d9625',
				'desc_tip'    => true,
			),
			'hmac_key'                    => array(
				'title'       => esc_html__( 'HMAC Key', 'straumur-payments-for-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'Your HMAC secret key used to validate incoming webhooks.', 'straumur-payments-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'webhook_url'                 => array(
				'title'             => esc_html__( 'Webhook URL', 'straumur-payments-for-woocommerce' ),
				'type'              => 'text',
				'description'       => esc_html__(
					'Use this URL in your Straumur dashboard to configure webhooks. Click the field to select all.',
					'straumur-payments-for-woocommerce'
				),
				'default'           => $webhook_url,
				'desc_tip'          => false,
				'custom_attributes' => array(
					'readonly' => 'readonly',
					'onclick'  => 'this.select()',
				),
			),
		);
	}

	/**
	 * Validate the production URL field.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $test_mode True if test mode is enabled.
	 * @param string $value     The production URL value.
	 *
	 * @return string The validated or sanitized production URL.
	 */
	public static function validate_production_url_field( bool $test_mode, string $value ): string {
		if ( ! $test_mode && empty( $value ) ) {
			WC_Admin_Settings::add_error(
				esc_html__(
					'Production URL is required when Test Mode is disabled.',
					'straumur-payments-for-woocommerce'
				)
			);
			return '';
		}
		// Sanitize the URL before saving.
		return esc_url_raw( $value );
	}

	/**
	 * Retrieve the saved settings for the Straumur gateway.
	 * Caches after first load.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private static function get_settings(): array {
		if ( empty( self::$cached_settings ) ) {
			self::$cached_settings = get_option( self::$option_key, array() );
		}
		return self::$cached_settings;
	}

	/**
	 * Check if the gateway is enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$settings = self::get_settings();
		return ( isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] );
	}

	/**
	 * Get the gateway title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_title(): string {
		$settings = self::get_settings();
		return $settings['title'] ?? esc_html__( 'Straumur Payments', 'straumur-payments-for-woocommerce' );
	}

	/**
	 * Get the gateway description.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_description(): string {
		$settings = self::get_settings();
		return $settings['description'] ?? esc_html__(
			'Pay securely using Straumur hosted checkout.',
			'straumur-payments-for-woocommerce'
		);
	}

	/**
	 * Get the Straumur API key.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_api_key(): string {
		$settings = self::get_settings();
		return $settings['api_key'] ?? '';
	}

	/**
	 * Get the Straumur Gateway Terminal Identifier.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_gateway_terminal_identifier(): string {
		$settings = self::get_settings();
		return $settings['gateway_terminal_identifier'] ?? '';
	}

	/**
	 * Get the Straumur Payment Page Terminal Identifier.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_terminal_identifier(): string {
		$settings = self::get_settings();
		return $settings['terminal_identifier'] ?? '';
	}

	/**
	 * Get the Straumur theme key.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_theme_key(): string {
		$settings = self::get_settings();
		return $settings['theme_key'] ?? '';
	}

	/**
	 * Check if payments are authorize-only.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_authorize_only(): bool {
		$settings = self::get_settings();
		return ( isset( $settings['authorize_only'] ) && 'yes' === $settings['authorize_only'] );
	}

	/**
	 * Check if orders should be marked as completed on payment.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_complete_order_on_payment(): bool {
		$settings = self::get_settings();
		return ( isset( $settings['complete_order_on_payment'] ) && 'yes' === $settings['complete_order_on_payment'] );
	}

	/**
	 * Check if test mode is enabled.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_test_mode(): bool {
		$settings = self::get_settings();
		return ( isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'] );
	}

	/**
	 * Get the Abandon URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_abandon_url(): string {
		$settings = self::get_settings();
		return ! empty( $settings['abandon_url'] ) ? $settings['abandon_url'] : '';
	}

	/**
	 * Get the Custom Success URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_custom_success_url(): string {
		$settings = self::get_settings();
		return ! empty( $settings['custom_success_url'] ) ? $settings['custom_success_url'] : '';
	}

	/**
	 * Get the production URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_production_url(): string {
		$settings = self::get_settings();
		// Default is the live Straumur endpoint, sanitized.
		return esc_url_raw( $settings['production_url'] ?? 'https://greidslugatt.straumur.is/api/v1/' );
	}

	/**
	 * Get the HMAC key for validating Straumur webhooks.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_hmac_key(): string {
		$settings = self::get_settings();
		return $settings['hmac_key'] ?? '';
	}

	/**
	 * Whether cart items should be sent to Straumur.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function send_items(): bool {
		$settings = self::get_settings();
		return ( isset( $settings['items'] ) && 'yes' === $settings['items'] );
	}

	/**
	 * Get the Straumur webhook URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_webhook_url(): string {
		$settings = self::get_settings();
		return ! empty( $settings['webhook_url'] )
			? $settings['webhook_url']
			: home_url( '/wp-json/straumur/v1/payment-callback' );
	}

	/**
	 * Get the checkout expiry (in hours) from the settings.
	 *
	 * @since 1.0.0
	 * @return float
	 */
	public static function get_checkout_expiry(): float {
		$settings = self::get_settings();
		return isset( $settings['checkout_expiry'] )
			? (float) $settings['checkout_expiry']
			: 1.0;
	}
}
