<?php
/**
 * PayWithCrypto admin UI.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin helpers and order metabox.
 */
class PWC_Admin {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_metabox' ) );
		add_action( 'admin_post_pwc_sync_order', array( __CLASS__, 'handle_manual_sync' ) );
		add_action( 'wp_ajax_pwc_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Add order meta box for classic and HPOS screens.
	 */
	public static function add_order_metabox() {
		add_meta_box(
			'pwc_order_details',
			__( 'PayWithCrypto', 'paywithcrypto-woocommerce' ),
			array( __CLASS__, 'render_order_metabox' ),
			'shop_order',
			'side',
			'default'
		);

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			add_meta_box(
				'pwc_order_details',
				__( 'PayWithCrypto', 'paywithcrypto-woocommerce' ),
				array( __CLASS__, 'render_order_metabox' ),
				wc_get_page_screen_id( 'shop-order' ),
				'side',
				'default'
			);
		}
	}

	/**
	 * Render order meta box.
	 *
	 * @param mixed $post_or_order Post or order object.
	 */
	public static function render_order_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( isset( $post_or_order->ID ) ? $post_or_order->ID : 0 );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'paywithcrypto-woocommerce' ) . '</p>';
			return;
		}

		if ( PWC_GATEWAY_ID !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'This order was not paid with PayWithCrypto.', 'paywithcrypto-woocommerce' ) . '</p>';
			return;
		}

		$status_data = PWC_Order_Sync::get_public_status_data( $order );
		$fields      = array(
			__( 'PWC Payment ID', 'paywithcrypto-woocommerce' ) => $order->get_meta( '_pwc_payment_id', true ),
			__( 'Status', 'paywithcrypto-woocommerce' )         => $status_data['status_label'] . ' (' . $status_data['status'] . ')',
			__( 'Crypto Amount', 'paywithcrypto-woocommerce' )  => $status_data['amount_crypto'],
			__( 'Fiat Amount', 'paywithcrypto-woocommerce' )    => trim( $status_data['amount_fiat'] . ' ' . $status_data['fiat_currency'] ),
			__( 'Token', 'paywithcrypto-woocommerce' )          => $status_data['crypto'],
			__( 'Chain / Network', 'paywithcrypto-woocommerce' ) => trim( $status_data['chain'] . ' / ' . $status_data['network'], ' /' ),
			__( 'Payment Address', 'paywithcrypto-woocommerce' ) => $status_data['payment_address'],
			__( 'Confirmed', 'paywithcrypto-woocommerce' )      => $status_data['confirmed_amount'],
			__( 'Remaining', 'paywithcrypto-woocommerce' )      => $status_data['remaining_amount'],
			__( 'Transaction', 'paywithcrypto-woocommerce' )    => $status_data['tx_hash'],
			__( 'Last Sync', 'paywithcrypto-woocommerce' )      => $status_data['last_sync'],
		);

		echo '<div class="pwc-admin-order-box">';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $fields as $label => $value ) {
			if ( '' === (string) $value ) {
				$value = '&mdash;';
			} else {
				$value = esc_html( (string) $value );
			}

			echo '<tr><th style="width:42%;">' . esc_html( $label ) . '</th><td style="word-break:break-word;">' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		if ( ! empty( $status_data['payment_status_message'] ) ) {
			echo '<p><strong>' . esc_html__( 'PWC Message:', 'paywithcrypto-woocommerce' ) . '</strong><br>' . wp_kses_post( $status_data['payment_status_message'] ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="pwc_sync_order">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( 'pwc_sync_order_' . $order->get_id() );
		submit_button( __( 'Sync PayWithCrypto Status', 'paywithcrypto-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle manual sync admin action.
	 */
	public static function handle_manual_sync() {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'paywithcrypto-woocommerce' ), esc_html__( 'PayWithCrypto Sync Error', 'paywithcrypto-woocommerce' ), array( 'response' => 404 ) );
		}

		if ( ! self::can_edit_order( $order_id ) ) {
			wp_die( esc_html__( 'You do not have permission to sync this order.', 'paywithcrypto-woocommerce' ), esc_html__( 'PayWithCrypto Sync Error', 'paywithcrypto-woocommerce' ), array( 'response' => 403 ) );
		}

		check_admin_referer( 'pwc_sync_order_' . $order_id );

		$result = PWC_Order_Sync::sync_pwc_payment_status( $order );
		$args   = array( 'pwc_sync' => is_wp_error( $result ) ? 'error' : 'success' );

		if ( is_wp_error( $result ) ) {
			$args['pwc_sync_message'] = rawurlencode( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( $args, $order->get_edit_order_url() ) );
		exit;
	}

	/**
	 * Handle admin AJAX connection test.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to test this connection.', 'paywithcrypto-woocommerce' ),
				),
				403
			);
		}

		check_ajax_referer( 'pwc_test_connection', 'nonce' );

		$client = pwc_get_api_client();
		$result = $client->test_connection();
		$result = self::sanitize_connection_test_result( $result );

		update_option( 'pwc_last_connection_test', $result, false );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 200 );
	}

	/**
	 * Admin notices.
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['pwc_sync'] ) ) {
			$type    = sanitize_key( wp_unslash( $_GET['pwc_sync'] ) );
			$message = 'success' === $type ? __( 'PayWithCrypto status synced.', 'paywithcrypto-woocommerce' ) : __( 'PayWithCrypto status sync failed.', 'paywithcrypto-woocommerce' );
			if ( 'error' === $type && isset( $_GET['pwc_sync_message'] ) ) {
				$message .= ' ' . sanitize_text_field( wp_unslash( $_GET['pwc_sync_message'] ) );
			}

			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'success' === $type ? 'success' : 'error' ), esc_html( $message ) );
		}

		$settings = pwc_get_gateway_settings();
		if ( isset( $settings['enabled'] ) && 'yes' === $settings['enabled'] ) {
			$client = new PWC_API_Client( $settings );
			if ( ! $client->is_configured() ) {
				$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paywithcrypto' );
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'PayWithCrypto is enabled but App Key or Secret is missing.', 'paywithcrypto-woocommerce' ),
					esc_url( $settings_url ),
					esc_html__( 'Open gateway settings', 'paywithcrypto-woocommerce' )
				);
			}

			$app_key = isset( $settings['app_key'] ) ? trim( (string) $settings['app_key'] ) : '';
			if ( '' !== $app_key && 0 !== strpos( $app_key, 'ak_' ) ) {
				$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paywithcrypto' );
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'PayWithCrypto AppKey does not match the documented key_id format. PWC currently documents AppKey values as ak_...; using a merchant id or API id can return 401 Unauthorized.', 'paywithcrypto-woocommerce' ),
					esc_url( $settings_url ),
					esc_html__( 'Review PayWithCrypto settings', 'paywithcrypto-woocommerce' )
				);
			}
		}
	}

	/**
	 * Capability check for editing an order.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function can_edit_order( $order_id ) {
		return current_user_can( 'edit_shop_order', $order_id ) || current_user_can( 'edit_post', $order_id ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Sanitize connection test data before storage or JSON output.
	 *
	 * @param array<string,mixed> $result Test result.
	 * @return array<string,mixed>
	 */
	private static function sanitize_connection_test_result( array $result ) {
		return array(
			'ok'          => ! empty( $result['ok'] ),
			'verdict'     => isset( $result['verdict'] ) ? sanitize_key( (string) $result['verdict'] ) : 'unknown',
			'message'     => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			'endpoint'    => isset( $result['endpoint'] ) ? esc_url_raw( (string) $result['endpoint'] ) : '',
			'http_code'   => isset( $result['http_code'] ) ? absint( $result['http_code'] ) : 0,
			'api_code'    => isset( $result['api_code'] ) ? sanitize_text_field( (string) $result['api_code'] ) : '',
			'app_key'     => isset( $result['app_key'] ) ? sanitize_text_field( (string) $result['app_key'] ) : '',
			'secret_mode' => isset( $result['secret_mode'] ) ? sanitize_key( (string) $result['secret_mode'] ) : '',
			'checked_at'  => isset( $result['checked_at'] ) ? sanitize_text_field( (string) $result['checked_at'] ) : gmdate( 'c' ),
		);
	}
}
