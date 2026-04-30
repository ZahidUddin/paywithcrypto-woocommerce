<?php
/**
 * Uninstall logic for PayWithCrypto for WooCommerce.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gateway_id      = 'paywithcrypto';
$settings_option = 'woocommerce_' . $gateway_id . '_settings';
$settings        = get_option( $settings_option, array() );
$settings        = is_array( $settings ) ? $settings : array();

// Keep existing data by default unless admin opted into cleanup.
$cleanup_enabled = isset( $settings['cleanup_on_uninstall'] ) && 'yes' === $settings['cleanup_on_uninstall'];
if ( ! $cleanup_enabled ) {
	return;
}

delete_option( $settings_option );
delete_option( 'pwc_last_connection_test' );

$timestamp = wp_next_scheduled( 'pwc_reconcile_pending_orders' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'pwc_reconcile_pending_orders' );
}
