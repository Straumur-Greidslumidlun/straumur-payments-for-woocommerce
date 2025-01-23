<?php
/**
 * Straumur Settingsss Class
 *
 * Provides and validates the settings fields used by the Straumur payment gateway.
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

use WC_Admin_Settings;
use function esc_html__;
use function esc_url_raw;
use function home_url;

class WC_Straumur_Settings
{
    /**
     * Option key for Straumur settings.
     *
     * @since 1.0.0
     * @var string
     */
    private static $option_key = 'woocommerce_straumur_settings';

    /**
     * Retrieve the gateway form fields for the settings page.
     *
     * @since 1.0.0
     *
     * @return array Settings fields.
     */
    public static function get_form_fields(): array
    {
        $webhook_url = home_url('/wp-json/straumur/v1/payment-callback');

        return [
            'enabled' => [
                'title'       => esc_html__('Enable/Disable', 'straumur-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => esc_html__('Enable Straumur Payments', 'straumur-payments-for-woocommerce'),
                'default'     => 'yes',
            ],
            'title' => [
                'title'       => esc_html__('Title', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('This controls the title which the user sees during checkout.', 'straumur-payments-for-woocommerce'),
                'default'     => esc_html__('Straumur Payments', 'straumur-payments-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => esc_html__('Description', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('This controls the description which the user sees during checkout.', 'straumur-payments-for-woocommerce'),
                'default'     => esc_html__('Pay via Straumur Hosted Checkout.', 'straumur-payments-for-woocommerce'),
                                'desc_tip'    => true,

            ],
            'theme_key' => [
                'title'       => esc_html__('Theme key', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('Theme key, logo colors etc.', 'straumur-payments-for-woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
            ],

            'authorize_only' => [
                'title'       => esc_html__('Authorize Only (Manual Capture)', 'straumur-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => esc_html__('Enable authorize only mode. Payments will require manual capture.', 'straumur-payments-for-woocommerce'),
                'default'     => 'no',
                'description' => esc_html__('If enabled, payments will be authorized but not captured automatically.', 'straumur-payments-for-woocommerce'),
                'desc_tip'    => false,
            ],


            'items' => [
                'title'       => esc_html__('Send cart items', 'straumur-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => esc_html__('Send cart items to the checkout page. Disable if using incompatible plugins.', 'straumur-payments-for-woocommerce'),
                'default'     => 'yes',
                'desc_tip'    => false,
            ],


            'terminal_identifier' => [
                'title'       => esc_html__('Terminal Identifier', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('The Terminal Identifier provided by Straumur.', 'straumur-payments-for-woocommerce'),
                'default'     => '117980943d55',
                'desc_tip'    => true,
            ],
            'test_mode' => [
                'title'       => esc_html__('Test Mode', 'straumur-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => esc_html__('Enable Test Mode', 'straumur-payments-for-woocommerce'),
                'default'     => 'yes',
                'description' => esc_html__('If enabled, the gateway will use the sandbox URL.', 'straumur-payments-for-woocommerce'),
                'desc_tip'    => false,
            ],
            'production_url' => [
                'title'       => esc_html__('Production URL', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('The Production URL provided by Straumur.', 'straumur-payments-for-woocommerce'),
                'default'     => 'https://greidslugatt.straumur.is/api/v1/',
                'desc_tip'    => true,
                'placeholder' => 'https://greidslugatt.straumur.is/api/v1/',
            ],
            'api_key' => [
                'title'       => esc_html__('API Key', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('The API Key provided by Straumur.', 'straumur-payments-for-woocommerce'),
                'default'     => '4cb5b98a627312a883fa6d4cac30ed7c422d4b5a7a45e65d15',
                'desc_tip'    => true,
            ],


            'webhook_url' => [
                'title'             => esc_html__('Webhook URL', 'straumur-payments-for-woocommerce'),
                'type'              => 'text',
                'description'       => esc_html__('Use this URL in your Straumur dashboard to configure webhooks. Click the field to select all.', 'straumur-payments-for-woocommerce'),
                'default'           => $webhook_url,
                'desc_tip'          => false,
                'custom_attributes' => [
                    'readonly' => 'readonly',
                    'onclick'  => 'this.select()',
                ],
            ],         
            'hmac_key' => [
                'title'       => esc_html__('HMAC Key', 'straumur-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => esc_html__('Your HMAC secret key used to validate incoming webhooks.', 'straumur-payments-for-woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Validate the production URL field.
     *
     * Ensures that the production URL is not empty when test mode is disabled.
     * This method can be called from the payment gateway class when options are saved.
     *
     * @since 1.0.0
     *
     * @param bool   $test_mode True if test mode is enabled.
     * @param string $value     The production URL value.
     * @return string The validated or sanitized production URL.
     */
    public static function validate_production_url_field(bool $test_mode, string $value): string
    {
        if (!$test_mode && empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Production URL is required when Test Mode is disabled.', 'straumur-payments-for-woocommerce'));
            return '';
        }

        return esc_url_raw($value);
    }

    /**
     * Retrieve the saved settings for the Straumur gateway.
     *
     * @since 1.0.0
     * @return array Associative array of settings.
     */
    private static function get_settings(): array
    {
        return get_option(self::$option_key, []);
    }

    /**
     * Check if the gateway is enabled.
     *
     * @since 1.0.0
     * @return bool True if enabled, false otherwise.
     */
    public static function is_enabled(): bool
    {
        $settings = self::get_settings();
        return isset($settings['enabled']) && $settings['enabled'] === 'yes';
    }

    /**
     * Get the gateway title.
     *
     * @since 1.0.0
     * @return string Gateway title.
     */
    public static function get_title(): string
    {
        $settings = self::get_settings();
        return $settings['title'] ?? esc_html__('Straumur Payments', 'straumur-payments-for-woocommerce');
    }

    /**
     * Get the gateway description.
     *
     * @since 1.0.0
     * @return string Gateway description.
     */
    public static function get_description(): string
    {
        $settings = self::get_settings();
        return $settings['description'] ?? esc_html__('Pay securely using Straumur hosted checkout.', 'straumur-payments-for-woocommerce');
    }

    /**
     * Get the API key.
     *
     * @since 1.0.0
     * @return string API key.
     */
    public static function get_api_key(): string
    {
        $settings = self::get_settings();
        return $settings['api_key'] ?? '';
    }

    /**
     * Get the terminal identifier.
     *
     * @since 1.0.0
     * @return string Terminal identifier.
     */
    public static function get_terminal_identifier(): string
    {
        $settings = self::get_settings();
        return $settings['terminal_identifier'] ?? '';
    }

    /**
     * Get the theme key.
     *
     * @since 1.0.0
     * @return string Theme key.
     */
    public static function get_theme_key(): string
    {
        $settings = self::get_settings();
        return $settings['theme_key'] ?? '';
    }

    /**
     * Check if authorize only mode is enabled.
     *
     * @since 1.0.0
     * @return bool True if authorize_only is enabled, false otherwise.
     */
    public static function is_authorize_only(): bool
    {
        $settings = self::get_settings();
        return isset($settings['authorize_only']) && $settings['authorize_only'] === 'yes';
    }

    /**
     * Check if test mode is enabled.
     *
     * @since 1.0.0
     * @return bool True if test mode is enabled, false otherwise.
     */
    public static function is_test_mode(): bool
    {
        $settings = self::get_settings();
        return isset($settings['test_mode']) && $settings['test_mode'] === 'yes';
    }

    /**
     * Get the production URL.
     *
     * @since 1.0.0
     * @return string Production URL.
     */
    public static function get_production_url(): string
    {
        $settings = self::get_settings();
        return $settings['production_url'] ?? 'https://greidslugatt.straumur.is/api/v1/';
    }

    /**
     * Get the HMAC secret.
     *
     * @since 1.0.0
     * @return string HMAC secret.
     */
    public static function get_hmac_key(): string
    {
        $settings = self::get_settings();
        return $settings['hmac_key'] ?? '';
    }

    /**
     * Check if items should be sent to the checkout page.
     *
     * @since 1.0.0
     * @return bool True if items should be sent, false otherwise.
     */
    public static function send_items(): bool
    {
        $settings = self::get_settings();
        return isset($settings['items']) && $settings['items'] === 'yes';
    }

    /**
     * Get the webhook URL.
     *
     * @since 1.0.0
     * @return string Webhook URL.
     */
    public static function get_webhook_url(): string
    {
        $settings = self::get_settings();
        // The webhook_url default is set dynamically, so we must ensure a fallback.
        return $settings['webhook_url'] ?? home_url('/wp-json/straumur/v1/payment-callback');
    }
}
