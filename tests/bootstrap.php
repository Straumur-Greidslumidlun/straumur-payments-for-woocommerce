<?php
/**
 * Unit test bootstrap.
 *
 * The tests run the plugin's classes without WordPress or WooCommerce: WordPress functions are
 * stubbed per test with Brain Monkey, and the few WordPress and WooCommerce classes the code
 * uses are replaced by the minimal stand-ins in tests/stubs.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );

// Info and warning messages reach the logger only with WP_DEBUG on; the tests read them.
define( 'WP_DEBUG', true );

require_once __DIR__ . '/stubs/wordpress.php';
require_once __DIR__ . '/stubs/woocommerce.php';

require_once dirname( __DIR__ ) . '/includes/class-wc-straumur-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-straumur-log-redactor.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-straumur-webhook-handler.php';
