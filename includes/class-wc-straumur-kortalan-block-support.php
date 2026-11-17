<?php
/**
 * Straumur Kortalán block checkout support.
 *
 * Registers the Straumur Kortalán payment option with the Cart and Checkout blocks. The script
 * is the one the Straumur Payments option uses; it registers each option whose data is present.
 *
 * @package Straumur\Payments
 * @since   2.3.0
 */

declare(strict_types=1);

namespace Straumur\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WC_Straumur_Kortalan_Block_Support
 *
 * @since 2.3.0
 */
class WC_Straumur_Kortalan_Block_Support extends WC_Straumur_Block_Support {

	/**
	 * Payment method name, the same as the gateway ID.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return WC_Straumur_Settings::KORTALAN_GATEWAY_ID;
	}

	/**
	 * Title shown at checkout.
	 *
	 * @return string
	 */
	public function get_payment_method_title(): string {
		return WC_Straumur_Settings::get_kortalan_title();
	}

	/**
	 * Description shown at checkout.
	 *
	 * @return string
	 */
	public function get_payment_method_description(): string {
		return WC_Straumur_Settings::get_kortalan_description();
	}

	/**
	 * Data handed to the checkout script.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->get_payment_method_title(),
			'description' => $this->get_payment_method_description(),
			'supports'    => array( 'products' ),
			'icons'       => array(
				array(
					'name'  => 'kortalan',
					'label' => 'Straumur Kortalán',
					'url'   => esc_url( STRAUMUR_PAYMENTS_PLUGIN_URL . 'assets/images/kortalan.svg' ),
				),
			),
		);
	}

	/**
	 * Whether the merchant has switched the option on. Whether it is offered for a given basket
	 * is decided by the gateway's is_available().
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return WC_Straumur_Settings::is_kortalan_enabled();
	}
}
