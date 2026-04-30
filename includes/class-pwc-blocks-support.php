<?php
/**
 * WooCommerce Blocks support.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers PayWithCrypto in WooCommerce Blocks checkout.
 */
class PWC_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = PWC_GATEWAY_ID;

	/**
	 * Gateway settings.
	 *
	 * @var array<string,mixed>
	 */
	protected $settings = array();

	/**
	 * Initialize settings.
	 */
	public function initialize() {
		$this->settings = pwc_get_gateway_settings();
	}

	/**
	 * Is active in blocks.
	 *
	 * @return bool
	 */
	public function is_active() {
		$client = new PWC_API_Client( $this->settings );

		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && $client->is_configured();
	}

	/**
	 * Script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'pwc-blocks',
			PWC_PLUGIN_URL . 'assets/js/paywithcrypto-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PWC_VERSION,
			true
		);

		return array( 'pwc-blocks' );
	}

	/**
	 * Data passed to blocks JS.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$data = array(
			'title'       => isset( $this->settings['title'] ) ? sanitize_text_field( (string) $this->settings['title'] ) : __( 'Pay with Crypto', 'paywithcrypto-woocommerce' ),
			'description' => isset( $this->settings['description'] ) ? wp_kses_post( (string) $this->settings['description'] ) : __( 'Pay securely using crypto wallet transfer.', 'paywithcrypto-woocommerce' ),
			'supports'    => array( 'products' ),
		);

		/**
		 * Allow add-ons to pass additional safe data to the PayWithCrypto Blocks UI.
		 *
		 * @param array<string,mixed> $data Blocks payment method data.
		 * @param array<string,mixed> $settings Gateway settings.
		 */
		return apply_filters( 'pwc_blocks_payment_method_data', $data, $this->settings );
	}
}
