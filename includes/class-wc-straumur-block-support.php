<?php
/**
 * Straumur Webhook Class
 *
 * Registers Straumur payment method with the new Cart and Checkout blocks.
 */

declare(strict_types=1);

namespace Straumur\Payments;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class WC_Straumur_Block_Support extends AbstractPaymentMethodType {

    /**
     * Initialize the class by hooking into WooCommerce Blocks.
     */
    public static function init(): void {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            [ __CLASS__, 'register_payment_method_type' ]
        );
    }

    /**
     * Register the payment method type.
     *
     * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Payment method registry.
     * @return void
     */
    public static function register_payment_method_type( $registry ): void {
        $instance = new self();
        $registry->register( $instance );
    }

    /**
     * Implement required method from IntegrationInterface.
     */
    public function initialize() {
        // No-op if not needed.
    }

    public function get_name(): string {
        return 'straumur';
    }

    public function get_payment_method_title(): string {
        return WC_Straumur_Settings::get_title();
    }

    public function get_payment_method_description(): string {
        return WC_Straumur_Settings::get_description();
    }

    public function get_payment_method_script_handles(): array {
        $this->register_scripts();
        return [ 'straumur-block-payment-method' ];
    }

    public function get_payment_method_data(): array {
        return [
            'title'       => $this->get_payment_method_title(),
            'description' => $this->get_payment_method_description(),
            'supports'    => [ 'products', 'subscriptions' ],
        ];
    }

    public function register_scripts(): void {
        $asset_path = STRAUMUR_PAYMENTS_PLUGIN_DIR . 'assets/js/frontend/straumur-block-payment-method.asset.php';
        $asset      = file_exists( $asset_path )
            ? include $asset_path
            : [ 'dependencies' => [], 'version' => STRAUMUR_PAYMENTS_VERSION ];

        wp_register_script(
            'straumur-block-payment-method',
            STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/js/frontend/straumur-block-payment-method.js',
            array_merge( $asset['dependencies'], [ 'wc-blocks-registry', 'wc-settings' ] ),
            $asset['version'],
            true
        );
    }

    public function is_active(): bool {
        $settings = get_option( 'woocommerce_straumur_settings', [] );
        return isset( $settings['enabled'] ) && 'yes' === $settings['enabled'];
    }
}