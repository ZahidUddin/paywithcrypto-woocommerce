<?php
/**
 * Plugin Name: PayWithCrypto for WooCommerce
 * Plugin URI: https://zahiduddin.com/
 * Description: WooCommerce payment gateway integration for PayWithCrypto / PWC crypto wallet transfer payments.
 * Version: 1.0.0
 * Author: Zahid Uddin
 * Author URI: https://zahiduddin.com/
 * Text Domain: paywithcrypto-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PWC_VERSION', '1.0.0' );
define( 'PWC_PLUGIN_FILE', __FILE__ );
define( 'PWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PWC_GATEWAY_ID', 'paywithcrypto' );
define( 'PWC_EDITION', 'basic' );

/**
 * Return gateway settings without instantiating the gateway.
 *
 * @return array<string,mixed>
 */
function pwc_get_gateway_settings() {
	$settings = get_option( 'woocommerce_' . PWC_GATEWAY_ID . '_settings', array() );

	return is_array( $settings ) ? $settings : array();
}

/**
 * Read one gateway setting.
 *
 * @param string $key Setting key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function pwc_get_gateway_setting( $key, $default = '' ) {
	$settings = pwc_get_gateway_settings();

	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

/**
 * Get a configured API client.
 *
 * @return PWC_API_Client
 */
function pwc_get_api_client() {
	return new PWC_API_Client( pwc_get_gateway_settings() );
}

/**
 * Determine whether WooCommerce is available.
 *
 * @return bool
 */
function pwc_is_woocommerce_active() {
	return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
}

/**
 * Load text domain.
 */
function pwc_load_textdomain() {
	load_plugin_textdomain( 'paywithcrypto-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pwc_load_textdomain' );

/**
 * Declare WooCommerce feature compatibility.
 */
function pwc_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'pwc_declare_wc_compatibility' );

/**
 * Admin notice when WooCommerce is unavailable.
 */
function pwc_woocommerce_missing_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'PayWithCrypto for WooCommerce requires WooCommerce to be installed and active.', 'paywithcrypto-woocommerce' )
		);
	}
}

/**
 * Load plugin classes and hooks after WooCommerce is available.
 */
function pwc_init_plugin() {
	if ( ! pwc_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'pwc_woocommerce_missing_notice' );
		return;
	}

	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-signature.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-api-client.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-order-sync.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-payment-page.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-webhook-handler.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-admin.php';
	require_once PWC_PLUGIN_DIR . 'includes/class-wc-gateway-paywithcrypto.php';

	PWC_Payment_Page::init();
	PWC_Webhook_Handler::init();
	PWC_Admin::init();
	PWC_Order_Sync::init();

	add_filter( 'woocommerce_payment_gateways', 'pwc_register_gateway' );

	/**
	 * Fires after the basic plugin is fully loaded.
	 *
	 * Extension plugins can hook here without modifying the free plugin.
	 */
	do_action( 'pwc_plugin_loaded' );
}
add_action( 'plugins_loaded', 'pwc_init_plugin', 20 );

/**
 * Register WooCommerce gateway class.
 *
 * @param array<int,string> $gateways Gateway classes.
 * @return array<int,string>
 */
function pwc_register_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_PayWithCrypto';

	/**
	 * Allow extension plugins to add extra gateway classes.
	 *
	 * @param array<int,string> $gateways Gateway class names.
	 */
	return apply_filters( 'pwc_gateway_classes', $gateways );
}

/**
 * Register WooCommerce Blocks payment method support.
 */
function pwc_register_blocks_support() {
	if ( ! pwc_is_woocommerce_active() ) {
		return;
	}

	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	if ( ! class_exists( 'PWC_API_Client' ) ) {
		require_once PWC_PLUGIN_DIR . 'includes/class-pwc-signature.php';
		require_once PWC_PLUGIN_DIR . 'includes/class-pwc-api-client.php';
	}

	require_once PWC_PLUGIN_DIR . 'includes/class-pwc-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $payment_method_registry ) {
			$payment_method_registry->register( new PWC_Blocks_Support() );
		}
	);
}
add_action( 'woocommerce_blocks_loaded', 'pwc_register_blocks_support' );

/**
 * Add cron interval for status reconciliation.
 *
 * @param array<string,array<string,int|string>> $schedules Existing schedules.
 * @return array<string,array<string,int|string>>
 */
function pwc_add_cron_interval( $schedules ) {
	$schedules['pwc_every_15_minutes'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes', 'paywithcrypto-woocommerce' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'pwc_add_cron_interval' );

/**
 * Plugin activation.
 */
function pwc_activate_plugin() {
	if ( ! wp_next_scheduled( 'pwc_reconcile_pending_orders' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'pwc_every_15_minutes', 'pwc_reconcile_pending_orders' );
	}

	if ( ! class_exists( 'PWC_Payment_Page' ) && file_exists( PWC_PLUGIN_DIR . 'includes/class-pwc-payment-page.php' ) ) {
		require_once PWC_PLUGIN_DIR . 'includes/class-pwc-payment-page.php';
	}

	if ( class_exists( 'PWC_Payment_Page' ) ) {
		PWC_Payment_Page::add_rewrite_rules();
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pwc_activate_plugin' );

/**
 * Plugin deactivation.
 */
function pwc_deactivate_plugin() {
	$timestamp = wp_next_scheduled( 'pwc_reconcile_pending_orders' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'pwc_reconcile_pending_orders' );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pwc_deactivate_plugin' );
