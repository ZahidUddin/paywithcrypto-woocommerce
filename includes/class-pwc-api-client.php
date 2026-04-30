<?php
/**
 * PayWithCrypto API client.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side API wrapper for PWC.
 */
class PWC_API_Client {
	/**
	 * Gateway settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 */
	public function __construct( array $settings = array() ) {
		$this->settings = $settings;
	}

	/**
	 * Whether required credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_app_key() && '' !== $this->get_secret();
	}

	/**
	 * Create a PWC wallet-transfer payment order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_order( WC_Order $order ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'pwc_missing_credentials', __( 'PayWithCrypto API credentials are not configured.', 'paywithcrypto-woocommerce' ) );
		}

		$external_id = $order->get_meta( '_pwc_external_id', true );
		if ( '' === $external_id ) {
			$external_id = wp_generate_uuid4();
			$order->update_meta_data( '_pwc_external_id', $external_id );
			$order->save();
		}

		$amount       = (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
		$callback_url = $this->get_callback_url();
		$redirect_url = PWC_Payment_Page::get_return_url( $order );

		$body = array(
			'external_id'  => $external_id,
			'amount'       => $amount,
			'fiat'         => $this->get_fiat_currency( $order ),
			'chain'        => $this->get_chain(),
			'network'      => $this->get_network(),
			'crypto'       => $this->get_crypto(),
			'callback_url' => $callback_url,
			'redirect_url' => $redirect_url,
			'items'        => $this->build_order_items( $order ),
		);

		$body['signature'] = PWC_Signature::generate_body_signature( $body, $this->get_secret(), $this->get_secret_mode() );

		return $this->request( 'POST', '/api/v1/orders', $body, true );
	}

	/**
	 * Query payment status by PWC payment/order id.
	 *
	 * @param string $pwc_payment_id PWC payment id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_payment_status( $pwc_payment_id ) {
		$pwc_payment_id = rawurlencode( sanitize_text_field( (string) $pwc_payment_id ) );

		return $this->request( 'GET', '/api/v1/payment/' . $pwc_payment_id, array(), false );
	}

	/**
	 * Query order details by PWC order id.
	 *
	 * @param string $pwc_order_id PWC order id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_order( $pwc_order_id ) {
		$pwc_order_id = rawurlencode( sanitize_text_field( (string) $pwc_order_id ) );

		return $this->request( 'GET', '/api/v1/orders/' . $pwc_order_id, array(), true );
	}

	/**
	 * Perform an API request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path API path.
	 * @param array<string,mixed> $body Request body.
	 * @param bool                $auth_required Whether auth headers are required.
	 * @return array<string,mixed>|WP_Error
	 */
	public function request( $method, $path, array $body = array(), $auth_required = true ) {
		$method = strtoupper( sanitize_text_field( (string) $method ) );
		$url    = $this->build_url( $path );

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( $auth_required ) {
			$timestamp = (string) time();
			$nonce     = PWC_Signature::generate_nonce();
			$version   = $this->get_app_version();

			$headers['AppKey']      = $this->get_app_key();
			$headers['Timestamp']   = $timestamp;
			$headers['Nonce']       = $nonce;
			$headers['App-Version'] = $version;
			$headers['Sign']        = PWC_Signature::generate_header_sign( $this->get_app_key(), $this->get_secret(), $timestamp, $nonce, $version, $this->get_secret_mode() );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => $headers,
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log( 'PWC API request', array( 'method' => $method, 'url' => $url ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'PWC API transport error', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw_body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			$this->log( 'PWC API invalid JSON response', array( 'http_code' => $http_code ) );
			return new WP_Error( 'pwc_invalid_json', __( 'PayWithCrypto returned an invalid response.', 'paywithcrypto-woocommerce' ), array( 'status' => $http_code ) );
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			$message = isset( $decoded['message'] ) ? sanitize_text_field( (string) $decoded['message'] ) : __( 'PayWithCrypto API request failed.', 'paywithcrypto-woocommerce' );
			$this->log( 'PWC API HTTP error', array( 'http_code' => $http_code, 'message' => $message ) );

			return new WP_Error( 'pwc_http_error', $message, array( 'status' => $http_code, 'response' => $decoded ) );
		}

		if ( isset( $decoded['code'] ) && 0 !== (int) $decoded['code'] ) {
			$message = isset( $decoded['message'] ) ? sanitize_text_field( (string) $decoded['message'] ) : __( 'PayWithCrypto rejected the request.', 'paywithcrypto-woocommerce' );
			$this->log( 'PWC API application error', array( 'code' => $decoded['code'], 'message' => $message ) );

			return new WP_Error( 'pwc_api_error', $message, array( 'status' => $http_code, 'response' => $decoded ) );
		}

		$this->log( 'PWC API response received', array( 'http_code' => $http_code, 'code' => isset( $decoded['code'] ) ? $decoded['code'] : '' ) );

		return array(
			'success' => true,
			'code'    => isset( $decoded['code'] ) ? (int) $decoded['code'] : 0,
			'message' => isset( $decoded['message'] ) ? sanitize_text_field( (string) $decoded['message'] ) : '',
			'data'    => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded,
			'raw'     => $decoded,
		);
	}

	/**
	 * Run a safe backend connection/authentication probe.
	 *
	 * This intentionally sends a signed create-order body with an invalid token.
	 * An "invalid crypto" validation response means the route, auth headers, and
	 * body signature were accepted far enough for business validation. A 401/403
	 * means credentials/header signing failed. A body "Invalid signature" means
	 * create-order signing failed. No real payment order should be created by
	 * this probe.
	 *
	 * @return array<string,mixed>
	 */
	public function test_connection() {
		$url = $this->build_url( '/api/v1/orders' );

		if ( ! $this->is_configured() ) {
			return array(
				'ok'          => false,
				'verdict'     => 'missing_credentials',
				'message'     => __( 'App Key and Secret must be configured before testing.', 'paywithcrypto-woocommerce' ),
				'endpoint'    => $url,
				'http_code'   => 0,
				'app_key'     => $this->mask_app_key(),
				'secret_mode' => $this->get_secret_mode(),
			);
		}

		$timestamp = (string) time();
		$nonce     = PWC_Signature::generate_nonce();
		$version   = $this->get_app_version();
		$headers   = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'AppKey'      => $this->get_app_key(),
			'Timestamp'   => $timestamp,
			'Nonce'       => $nonce,
			'App-Version' => $version,
			'Sign'        => PWC_Signature::generate_header_sign( $this->get_app_key(), $this->get_secret(), $timestamp, $nonce, $version, $this->get_secret_mode() ),
		);
		$body      = array(
			'external_id'  => 'pwc-connection-test-' . gmdate( 'YmdHis' ) . '-' . PWC_Signature::generate_nonce( 8 ),
			'amount'       => 1,
			'fiat'         => $this->get_fiat_currency(),
			'chain'        => $this->get_chain(),
			'network'      => $this->get_network(),
			'crypto'       => 'INVALID-TEST',
			'callback_url' => $this->get_callback_url(),
			'redirect_url' => home_url( '/pwc-connection-test-return' ),
			'items'        => array(
				array(
					'sku'        => 'pwc-connection-test',
					'name'       => 'PayWithCrypto Connection Test',
					'quantity'   => 1,
					'unit_price' => 1,
				),
			),
		);
		$body['signature'] = PWC_Signature::generate_body_signature( $body, $this->get_secret(), $this->get_secret_mode() );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'verdict'     => 'transport_error',
				'message'     => $response->get_error_message(),
				'endpoint'    => $url,
				'http_code'   => 0,
				'app_key'     => $this->mask_app_key(),
				'secret_mode' => $this->get_secret_mode(),
			);
		}

		$http_code   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = trim( (string) wp_remote_retrieve_body( $response ) );
		$decoded     = json_decode( $raw_body, true );
		$api_code    = is_array( $decoded ) && isset( $decoded['code'] ) ? (string) $decoded['code'] : '';
		$api_message = is_array( $decoded ) && isset( $decoded['message'] ) ? sanitize_text_field( (string) $decoded['message'] ) : '';

		if ( '' === $api_message && '' !== $raw_body ) {
			$api_message = sanitize_text_field( substr( $raw_body, 0, 240 ) );
		}

		$result = array(
			'ok'          => false,
			'verdict'     => 'unexpected_response',
			'message'     => $api_message ? $api_message : __( 'PayWithCrypto returned an empty response body.', 'paywithcrypto-woocommerce' ),
			'endpoint'    => $url,
			'http_code'   => $http_code,
			'api_code'    => $api_code,
			'app_key'     => $this->mask_app_key(),
			'secret_mode' => $this->get_secret_mode(),
			'checked_at'  => gmdate( 'c' ),
		);

		if ( 401 === $http_code || 403 === $http_code ) {
			$result['verdict'] = 'auth_failed';
			$result['message'] = $api_message ? $api_message : __( 'PWC rejected the AppKey, Secret, or signature.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		if ( 404 === $http_code ) {
			$result['verdict'] = 'route_not_found';
			$result['message'] = __( 'PWC endpoint route was not found. Check the selected environment or custom base URL.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		if ( 2002 === (int) $api_code || false !== stripos( $api_message, 'invalid signature' ) ) {
			$result['verdict'] = 'body_signature_failed';
			$result['message'] = __( 'PWC reached the create-order route but rejected the body signature. Check the signing mode and secret value.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		if ( 2009 === (int) $api_code || false !== stripos( $api_message, 'invalid crypto' ) ) {
			$result['ok']      = true;
			$result['verdict'] = 'create_order_signature_accepted';
			$result['message'] = __( 'Connection reached PWC and both header credentials and create-order body signature were accepted. The invalid test token was rejected as expected, so no payment order was created.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		if ( in_array( $http_code, array( 400, 422 ), true ) ) {
			$result['ok']      = true;
			$result['verdict'] = 'credentials_accepted';
			$result['message'] = __( 'Connection reached PWC and the signed create-order probe was rejected during validation as expected, so no payment order was created.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		if ( $http_code >= 200 && $http_code < 300 ) {
			$result['ok']      = true;
			$result['verdict'] = 'connected';
			$result['message'] = __( 'Connection reached PWC successfully.', 'paywithcrypto-woocommerce' );
			return $result;
		}

		return $result;
	}

	/**
	 * Get configured callback URL.
	 *
	 * @return string
	 */
	public function get_callback_url() {
		return WC()->api_request_url( 'paywithcrypto_notify' );
	}

	/**
	 * Get configured base URL.
	 *
	 * @return string
	 */
	public function get_base_url() {
		$environment = isset( $this->settings['environment'] ) ? sanitize_key( (string) $this->settings['environment'] ) : 'production';

		if ( 'sandbox' === $environment ) {
			return 'https://api-test.paywithcrypto.vip/order';
		}

		// Legacy saved environment values now fall back to the single supported
		// production endpoint.
		return 'https://checkout.paywithcrypto.vip/order';
	}

	/**
	 * Get AppKey key_id only.
	 *
	 * @return string
	 */
	public function get_app_key() {
		$app_key = isset( $this->settings['app_key'] ) ? sanitize_text_field( (string) $this->settings['app_key'] ) : '';

		if ( false !== strpos( $app_key, '.' ) ) {
			$app_key = strtok( $app_key, '.' );
		}

		return trim( (string) $app_key );
	}

	/**
	 * Get API secret.
	 *
	 * @return string
	 */
	public function get_secret() {
		return isset( $this->settings['secret'] ) ? trim( (string) $this->settings['secret'] ) : '';
	}

	/**
	 * Get secret signing mode.
	 *
	 * @return string
	 */
	public function get_secret_mode() {
		return 'hash';
	}

	/**
	 * Get app version header.
	 *
	 * @return string
	 */
	public function get_app_version() {
		$version = defined( 'PWC_VERSION' ) ? PWC_VERSION : '1.0.0';

		return '' !== $version ? $version : '1.0.0';
	}

	/**
	 * Get fiat currency from the order or WooCommerce store settings.
	 *
	 * @param WC_Order|null $order Optional order.
	 * @return string
	 */
	public function get_fiat_currency( $order = null ) {
		$fiat = '';

		if ( $order instanceof WC_Order ) {
			$fiat = $order->get_currency();
		}

		if ( '' === $fiat && function_exists( 'get_woocommerce_currency' ) ) {
			$fiat = get_woocommerce_currency();
		}

		if ( '' === $fiat && isset( $this->settings['fiat_currency'] ) ) {
			$fiat = sanitize_text_field( (string) $this->settings['fiat_currency'] );
		}

		return strtoupper( $fiat ? $fiat : 'USD' );
	}

	/**
	 * Get chain setting.
	 *
	 * @return string
	 */
	public function get_chain() {
		$chain = isset( $this->settings['chain'] ) ? sanitize_text_field( (string) $this->settings['chain'] ) : 'BSC';
		$chain = strtoupper( $chain ? $chain : 'BSC' );

		return in_array( $chain, array( 'BSC', 'ETH' ), true ) ? $chain : 'BSC';
	}

	/**
	 * Get network setting.
	 *
	 * @return string
	 */
	public function get_network() {
		$network = isset( $this->settings['network'] ) ? sanitize_text_field( (string) $this->settings['network'] ) : 'mainnet';
		$network = strtolower( $network ? $network : 'mainnet' );

		return in_array( $network, array( 'mainnet', 'test', 'sepolia' ), true ) ? $network : 'mainnet';
	}

	/**
	 * Get crypto token setting.
	 *
	 * @return string
	 */
	public function get_crypto() {
		$crypto = isset( $this->settings['crypto'] ) ? sanitize_text_field( (string) $this->settings['crypto'] ) : 'USDT-ERC20';
		$crypto = strtoupper( $crypto ? $crypto : 'USDT-ERC20' );

		return in_array( $crypto, array( 'USDT-ERC20', 'PWCUSD-ERC20', 'TEST-ERC20' ), true ) ? $crypto : 'USDT-ERC20';
	}

	/**
	 * Build full API URL.
	 *
	 * @param string $path API path.
	 * @return string
	 */
	private function build_url( $path ) {
		return untrailingslashit( $this->get_base_url() ) . '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Build PWC line items from a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_order_items( WC_Order $order ) {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product    = $item->get_product();
			$quantity   = max( 1, (int) $item->get_quantity() );
			$line_total = (float) $item->get_total() + (float) $item->get_total_tax();
			$unit_price = $quantity > 0 ? $line_total / $quantity : $line_total;
			$sku        = $product ? $product->get_sku() : '';

			$items[] = array(
				'sku'        => $sku ? sanitize_text_field( $sku ) : 'wc-item-' . absint( $item_id ),
				'name'       => sanitize_text_field( $item->get_name() ),
				'quantity'   => $quantity,
				'unit_price' => (float) wc_format_decimal( $unit_price, wc_get_price_decimals() ),
			);
		}

		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			$fee_total = (float) $item->get_total() + (float) $item->get_total_tax();
			if ( 0.0 === $fee_total ) {
				continue;
			}

			$items[] = array(
				'sku'        => 'wc-fee-' . absint( $item_id ),
				'name'       => sanitize_text_field( $item->get_name() ),
				'quantity'   => 1,
				'unit_price' => (float) wc_format_decimal( $fee_total, wc_get_price_decimals() ),
			);
		}

		$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		if ( $shipping_total > 0 ) {
			$items[] = array(
				'sku'        => 'wc-shipping',
				'name'       => __( 'Shipping', 'paywithcrypto-woocommerce' ),
				'quantity'   => 1,
				'unit_price' => (float) wc_format_decimal( $shipping_total, wc_get_price_decimals() ),
			);
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'sku'        => 'wc-order-' . $order->get_id(),
				'name'       => sprintf( /* translators: %s: order number */ __( 'Order %s', 'paywithcrypto-woocommerce' ), $order->get_order_number() ),
				'quantity'   => 1,
				'unit_price' => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			);
		}

		return $items;
	}

	/**
	 * Write a debug log entry with sensitive data removed.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	private function log( $message, array $context = array() ) {
		$debug = isset( $this->settings['debug'] ) && 'yes' === $this->settings['debug'];
		if ( ! $debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		$logger->debug( sanitize_text_field( $message ) . ' ' . wc_print_r( $this->redact_context( $context ), true ), array( 'source' => 'paywithcrypto' ) );
	}

	/**
	 * Redact known sensitive fields.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	private function redact_context( array $context ) {
		$sensitive_keys = array( 'secret', 'signature', 'sign', 'authorization' );

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}

	/**
	 * Mask AppKey for admin diagnostics.
	 *
	 * @return string
	 */
	private function mask_app_key() {
		$app_key = $this->get_app_key();
		if ( '' === $app_key ) {
			return '';
		}

		if ( strlen( $app_key ) <= 8 ) {
			return substr( $app_key, 0, 2 ) . '...' . substr( $app_key, -2 );
		}

		return substr( $app_key, 0, 6 ) . '...' . substr( $app_key, -4 );
	}
}
