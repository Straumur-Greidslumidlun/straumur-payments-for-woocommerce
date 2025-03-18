<?php
/**
 * Plugin Name:     Straumur Payments For WooCommerce
 * Plugin URI:      https://straumur.is/veflausnir
 * Description:     Facilitates seamless payments using Straumur's Hosted Checkout in WooCommerce.
 * Author:          Straumur
 * Author URI:      https://straumur.is
 * Text Domain:     straumur-payments-for-woocommerce
 * Domain Path:     /languages
 * Version:         1.1.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 9.4
 * WC Payment Gateway: yes
 * WC Blocks Support: yes
 *
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
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

/*
 * Define plugin constants.
 */
define('STRAUMUR_PAYMENTS_VERSION', '1.1.0');
define('STRAUMUR_PAYMENTS_MAIN_FILE', __FILE__);
define('STRAUMUR_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STRAUMUR_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Show an admin notice if WooCommerce is not active.
 *
 * @since 1.0.0
 * @return void
 */
function straumur_payments_woocommerce_missing_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="error"><p><strong>' . esc_html__('Straumur Payments for WooCommerce requires WooCommerce to be installed and active.', 'straumur-payments-for-woocommerce') . '</strong></p></div>';
}

/**
 * Initialize the Straumur Payments plugin.
 *
 * Checks if WooCommerce is active and loads the payment gateway and other classes.
 *
 * @since 1.0.0
 * @return void
 */
function straumur_payments_init(): void
{
    // Include required files.
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-settings.php';
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-api.php';
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-order-handler.php';
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-payment-gateway.php';
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-block-support.php';
    require_once STRAUMUR_PAYMENTS_PLUGIN_DIR . 'includes/class-wc-straumur-webhook-handler.php';

    // Check if WooCommerce is active.
    if (!function_exists('WC')) {
        add_action('admin_notices', __NAMESPACE__ . '\\straumur_payments_woocommerce_missing_notice');
        return;
    }

    // Load text domain for translations.
    load_plugin_textdomain('straumur-payments-for-woocommerce', false, dirname(plugin_basename(STRAUMUR_PAYMENTS_MAIN_FILE)) . '/languages');

    if (class_exists(__NAMESPACE__ . '\\WC_Straumur_Block_Support')) {
        WC_Straumur_Block_Support::init();
    }

    // Add the gateway to WooCommerce.
    add_filter('woocommerce_payment_gateways', __NAMESPACE__ . '\\add_straumur_payment_gateway');

    // Declare HPOS (High Performance Order Storage) compatibility.
    add_action('before_woocommerce_init', static function () {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                STRAUMUR_PAYMENTS_MAIN_FILE,
                true
            );
        }
    });

    // Add plugin action links.
    add_filter('plugin_action_links_' . plugin_basename(STRAUMUR_PAYMENTS_MAIN_FILE), __NAMESPACE__ . '\\straumur_payments_action_links');

    // Add plugin row meta.
    add_filter('plugin_row_meta', __NAMESPACE__ . '\\straumur_payments_plugin_row_meta', 10, 2);

    // Instantiate the order handler.
    $straumur_order_handler = new \Straumur\Payments\WC_Straumur_Order_Handler();

    // Hook into order status transitions.
    add_action('woocommerce_order_status_on-hold_to_cancelled', [ $straumur_order_handler, 'handle_cancellation' ], 10, 1);
    add_action('woocommerce_order_status_on-hold_to_processing', [ $straumur_order_handler, 'handle_capture' ], 10, 1);
    add_action('woocommerce_order_status_on-hold_to_completed',[ $straumur_order_handler, 'handle_capture' ], 10, 1);
    add_action('woocommerce_order_status_processing_to_refunded', [ $straumur_order_handler, 'handle_refund' ], 10, 1);
    add_action('woocommerce_order_status_completed_to_refunded', [ $straumur_order_handler, 'handle_refund' ], 10, 1);

}

/**
 * Add Straumur Payment Gateway to WooCommerce gateways.
 *
 * @since 1.0.0
 * 
 * @param array $gateways Existing WooCommerce payment gateways.
 * @return array Modified list of payment gateways.
 */
function add_straumur_payment_gateway(array $gateways): array
{
    $gateways[] = '\\Straumur\\Payments\\WC_Straumur_Payment_Gateway';
    return $gateways;
}


/**
 * Add action links in the Plugins list page.
 *
 * @since 1.0.0
 *
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function straumur_payments_action_links(array $links): array
{
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=straumur');
    $settings_link = sprintf('<a href="%s">%s</a>', esc_url($settings_url), esc_html__('Settings', 'straumur-payments-for-woocommerce'));
    array_unshift($links, $settings_link);

    return $links;
}

/**
 * Add plugin row meta links in the Plugins list page.
 *
 * @since 1.0.0
 *
 * @param array  $links Existing meta links.
 * @param string $file  Plugin file.
 * @return array Modified plugin meta links.
 */
function straumur_payments_plugin_row_meta(array $links, string $file): array
{
    if (plugin_basename(STRAUMUR_PAYMENTS_MAIN_FILE) === $file) {
        $docs_link = '<a href="https://docs.straumur.is" target="_blank" rel="noopener noreferrer">' . esc_html__('Documentation', 'straumur-payments-for-woocommerce') . '</a>';
        $support_link = '<a href="https://straumur.is/hafa-samband" target="_blank" rel="noopener noreferrer">' . esc_html__('Support', 'straumur-payments-for-woocommerce') . '</a>';
        $links[] = $docs_link;
        $links[] = $support_link;
    }

    return $links;
}

// Initialize the plugin after all plugins are loaded, ensuring WooCommerce is ready.
add_action('plugins_loaded', __NAMESPACE__ . '\\straumur_payments_init');
