<?php
/**
 * PayWithCrypto payment and return pages.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend payment page and REST polling endpoint.
 */
class PWC_Payment_Page {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Add pretty rewrite endpoints under the checkout page.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_tag( '%pwc_pay%', '([0-9]+)' );
		add_rewrite_tag( '%pwc_return%', '([0-9]+)' );

		$checkout_path = self::get_checkout_path();
		if ( '' === $checkout_path ) {
			$checkout_path = 'checkout';
		}

		$checkout_path = preg_quote( trim( $checkout_path, '/' ), '#' );

		add_rewrite_rule( '^' . $checkout_path . '/pwc-pay/([0-9]+)/?$', 'index.php?pwc_pay=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $checkout_path . '/pwc-return/([0-9]+)/?$', 'index.php?pwc_return=$matches[1]', 'top' );
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array<int,string> $vars Vars.
	 * @return array<int,string>
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'pwc_pay';
		$vars[] = 'pwc_return';

		return $vars;
	}

	/**
	 * Register REST polling route.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'paywithcrypto/v1',
			'/status/(?P<order_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Render payment or return page when the endpoint matches.
	 */
	public static function maybe_render_page() {
		$payment_order_id = absint( get_query_var( 'pwc_pay' ) );
		$return_order_id  = absint( get_query_var( 'pwc_return' ) );

		if ( ! $payment_order_id && isset( $_GET['pwc_pay'] ) ) {
			$payment_order_id = absint( wp_unslash( $_GET['pwc_pay'] ) );
		}

		if ( ! $return_order_id && isset( $_GET['pwc_return'] ) ) {
			$return_order_id = absint( wp_unslash( $_GET['pwc_return'] ) );
		}

		if ( $payment_order_id ) {
			self::render_payment_page( $payment_order_id );
		}

		if ( $return_order_id ) {
			self::render_return_page( $return_order_id );
		}
	}

	/**
	 * REST status endpoint callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get_status( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'pwc_order_not_found', __( 'Order not found.', 'paywithcrypto-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( ! self::can_view_order( $order, $request->get_param( 'key' ) ) ) {
			return new WP_Error( 'pwc_invalid_order_key', __( 'Invalid order key.', 'paywithcrypto-woocommerce' ), array( 'status' => 403 ) );
		}

		$result = PWC_Order_Sync::sync_pwc_payment_status( $order );
		if ( is_wp_error( $result ) ) {
			$data                = PWC_Order_Sync::get_public_status_data( $order );
			$data['sync_error']  = true;
			$data['sync_notice'] = __( 'Unable to refresh status right now. Showing the latest known payment details.', 'paywithcrypto-woocommerce' );
		} else {
			$data = $result;
		}

		return rest_ensure_response( self::filter_rest_status_data( $data ) );
	}

	/**
	 * Build the customer payment page URL.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_payment_page_url( WC_Order $order ) {
		return self::build_checkout_endpoint_url( 'pwc-pay', 'pwc_pay', $order );
	}

	/**
	 * Build the customer return page URL.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_return_url( WC_Order $order ) {
		return self::build_checkout_endpoint_url( 'pwc-return', 'pwc_return', $order );
	}

	/**
	 * Render wallet-transfer payment page.
	 *
	 * @param int $order_id Order id.
	 */
	private static function render_payment_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_view_order( $order, isset( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '' ) ) {
			wp_die( esc_html__( 'Invalid PayWithCrypto payment link.', 'paywithcrypto-woocommerce' ), esc_html__( 'Payment Link Error', 'paywithcrypto-woocommerce' ), array( 'response' => 403 ) );
		}

		if ( PWC_GATEWAY_ID !== $order->get_payment_method() ) {
			wp_die( esc_html__( 'This order is not payable with PayWithCrypto.', 'paywithcrypto-woocommerce' ), esc_html__( 'Payment Link Error', 'paywithcrypto-woocommerce' ), array( 'response' => 400 ) );
		}

		PWC_Order_Sync::sync_pwc_payment_status( $order );

		$status_data = PWC_Order_Sync::get_public_status_data( $order );
		$return_url  = self::get_return_url( $order );
		$status_url  = add_query_arg( 'key', rawurlencode( $order->get_order_key() ), rest_url( 'paywithcrypto/v1/status/' . $order->get_id() ) );

		self::enqueue_assets( $order, $status_data, $status_url, $return_url );

		get_header();
		include PWC_PLUGIN_DIR . 'templates/payment-page.php';
		get_footer();
		exit;
	}

	/**
	 * Render return status page.
	 *
	 * @param int $order_id Order id.
	 */
	private static function render_return_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_view_order( $order, isset( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '' ) ) {
			wp_die( esc_html__( 'Invalid PayWithCrypto return link.', 'paywithcrypto-woocommerce' ), esc_html__( 'Return Link Error', 'paywithcrypto-woocommerce' ), array( 'response' => 403 ) );
		}

		$result = PWC_Order_Sync::sync_pwc_payment_status( $order );
		if ( is_wp_error( $result ) ) {
			$status_data = PWC_Order_Sync::get_public_status_data( $order );
			$sync_error  = $result->get_error_message();
		} else {
			$status_data = $result;
			$sync_error  = '';
		}

		wp_enqueue_style( 'pwc-payment', PWC_PLUGIN_URL . 'assets/css/paywithcrypto.css', array(), PWC_VERSION );

		get_header();
		include PWC_PLUGIN_DIR . 'templates/return-status.php';
		get_footer();
		exit;
	}

	/**
	 * Enqueue frontend assets for payment page.
	 *
	 * @param WC_Order            $order Order.
	 * @param array<string,mixed> $status_data Status data.
	 * @param string              $status_url REST status URL.
	 * @param string              $return_url Return URL.
	 */
	private static function enqueue_assets( WC_Order $order, array $status_data, $status_url, $return_url ) {
		wp_enqueue_style( 'pwc-payment', PWC_PLUGIN_URL . 'assets/css/paywithcrypto.css', array(), PWC_VERSION );
		wp_enqueue_script( 'pwc-payment', PWC_PLUGIN_URL . 'assets/js/paywithcrypto-payment.js', array(), PWC_VERSION, true );

		wp_localize_script(
			'pwc-payment',
			'PWCPaymentPage',
			array(
				'statusUrl'    => esc_url_raw( $status_url ),
				'returnUrl'    => esc_url_raw( $return_url ),
				'pollInterval' => 3500,
				'initialStatus' => self::filter_rest_status_data( $status_data ),
				'i18n'         => array(
					'copy'        => __( 'Copy', 'paywithcrypto-woocommerce' ),
					'copied'      => __( 'Copied', 'paywithcrypto-woocommerce' ),
					'copyFailed'  => __( 'Copy failed', 'paywithcrypto-woocommerce' ),
					'expired'     => __( 'Expired', 'paywithcrypto-woocommerce' ),
					'redirecting' => __( 'Payment received. Redirecting...', 'paywithcrypto-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Determine if the current request can view the order.
	 *
	 * @param WC_Order $order Order.
	 * @param mixed    $provided_key Provided order key.
	 * @return bool
	 */
	private static function can_view_order( WC_Order $order, $provided_key ) {
		$provided_key = sanitize_text_field( (string) $provided_key );
		if ( '' !== $provided_key && hash_equals( $order->get_order_key(), $provided_key ) ) {
			return true;
		}

		if ( is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'edit_shop_order', $order->get_id() );
	}

	/**
	 * Return safe REST status payload fields only.
	 *
	 * @param array<string,mixed> $data Status data.
	 * @return array<string,mixed>
	 */
	private static function filter_rest_status_data( array $data ) {
		$allowed = array(
			'status',
			'status_label',
			'amount_crypto',
			'amount_fiat',
			'fiat_currency',
			'crypto',
			'payment_address',
			'qr_code_data',
			'chain',
			'network',
			'confirmed_amount',
			'remaining_amount',
			'expires_at',
			'tx_hash',
			'transaction_url',
			'payment_status_message',
			'redirect_url',
			'is_terminal',
			'last_sync',
			'sync_error',
			'sync_notice',
		);

		$filtered = array();
		foreach ( $allowed as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$filtered[ $key ] = $data[ $key ];
			}
		}

		return $filtered;
	}

	/**
	 * Build pretty checkout endpoint URL, with query-string fallback.
	 *
	 * @param string   $endpoint Endpoint slug.
	 * @param string   $query_var Query var fallback.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function build_checkout_endpoint_url( $endpoint, $query_var, WC_Order $order ) {
		$checkout_url = wc_get_checkout_url();
		$key          = $order->get_order_key();

		if ( false !== strpos( $checkout_url, '?' ) ) {
			return add_query_arg(
				array(
					$query_var => $order->get_id(),
					'key'     => rawurlencode( $key ),
				),
				$checkout_url
			);
		}

		$url = trailingslashit( $checkout_url ) . sanitize_title( $endpoint ) . '/' . $order->get_id() . '/';

		return add_query_arg( 'key', rawurlencode( $key ), $url );
	}

	/**
	 * Return the checkout page path for rewrite rules.
	 *
	 * @return string
	 */
	private static function get_checkout_path() {
		$checkout_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		if ( $checkout_id > 0 ) {
			$uri = get_page_uri( $checkout_id );
			if ( $uri ) {
				return trim( $uri, '/' );
			}
		}

		return 'checkout';
	}
}
