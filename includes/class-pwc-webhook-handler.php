<?php
/**
 * PayWithCrypto webhook handler.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles async PWC callbacks.
 */
class PWC_Webhook_Handler {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_api_paywithcrypto_notify', array( __CLASS__, 'handle_wc_api_notify' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST webhook alias.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'paywithcrypto/v1',
			'/notify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rest_notify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * WooCommerce API webhook endpoint.
	 */
	public static function handle_wc_api_notify() {
		$raw_body = (string) file_get_contents( 'php://input' );
		$headers  = PWC_Signature::get_request_headers();
		$result   = self::process_notification( $raw_body, $headers );

		if ( is_wp_error( $result ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				self::get_error_status( $result )
			);
		}

		wp_send_json( $result, 200 );
	}

	/**
	 * REST webhook endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_rest_notify( WP_REST_Request $request ) {
		$result = self::process_notification( (string) $request->get_body(), $request->get_headers() );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => self::get_error_status( $result ) ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Process a PWC notification.
	 *
	 * @param string              $raw_body Raw body.
	 * @param array<string,mixed> $headers Request headers.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function process_notification( $raw_body, array $headers ) {
		if ( '' === trim( $raw_body ) ) {
			return new WP_Error( 'pwc_empty_webhook', __( 'Empty webhook body.', 'paywithcrypto-woocommerce' ), array( 'status' => 400 ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'pwc_invalid_webhook_json', __( 'Invalid webhook JSON.', 'paywithcrypto-woocommerce' ), array( 'status' => 400 ) );
		}

		$data = self::unwrap_payload_data( $payload );
		if ( empty( $data ) ) {
			$data = $payload;
		}

		$status     = isset( $data['status'] ) ? PWC_Order_Sync::normalize_status( $data['status'] ) : '';
		$payment_id = self::first_non_empty( $data, array( 'payment_id', 'order_id', 'id' ) );
		$external   = self::first_non_empty( $data, array( 'external_id', 'merchant_order_id' ) );

		if ( '' === $status || ( '' === $payment_id && '' === $external ) ) {
			return new WP_Error( 'pwc_missing_webhook_fields', __( 'Webhook is missing required payment fields.', 'paywithcrypto-woocommerce' ), array( 'status' => 400 ) );
		}

		$client = pwc_get_api_client();
		if ( ! $client->is_configured() ) {
			return new WP_Error( 'pwc_missing_credentials', __( 'PayWithCrypto credentials are not configured.', 'paywithcrypto-woocommerce' ), array( 'status' => 401 ) );
		}

		$payload_has_signature = PWC_Signature::webhook_has_signature( $payload, $headers );
		$data_has_signature    = PWC_Signature::webhook_has_signature( $data, array() );
		$has_signature         = $payload_has_signature || $data_has_signature;
		$signature_ok          = PWC_Signature::verify_webhook_signature( $raw_body, $payload, $headers, $client->get_secret(), $client->get_secret_mode(), $client->get_app_key(), $client->get_app_version() );

		if ( $signature_ok && $data_has_signature ) {
			$signature_ok = PWC_Signature::verify_webhook_signature( $raw_body, $data, array(), $client->get_secret(), $client->get_secret_mode(), $client->get_app_key(), $client->get_app_version() );
		}

		if ( ! $signature_ok ) {
			return new WP_Error( 'pwc_invalid_signature', __( 'Invalid PayWithCrypto webhook signature.', 'paywithcrypto-woocommerce' ), array( 'status' => 401 ) );
		}

		$order_id = PWC_Order_Sync::find_order_id_from_payload( $data );
		if ( ! $order_id ) {
			return new WP_Error( 'pwc_webhook_order_not_found', __( 'Webhook order was not found.', 'paywithcrypto-woocommerce' ), array( 'status' => 404 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'pwc_order_not_found', __( 'WooCommerce order was not found.', 'paywithcrypto-woocommerce' ), array( 'status' => 404 ) );
		}

		$stored_payment_id = sanitize_text_field( (string) $order->get_meta( '_pwc_payment_id', true ) );
		if ( '' !== $payment_id && '' !== $stored_payment_id && ! hash_equals( $stored_payment_id, sanitize_text_field( $payment_id ) ) ) {
			return new WP_Error( 'pwc_payment_id_mismatch', __( 'Webhook payment id does not match the WooCommerce order.', 'paywithcrypto-woocommerce' ), array( 'status' => 400 ) );
		}

		$sync = PWC_Order_Sync::sync_pwc_payment_status( $order );
		if ( is_wp_error( $sync ) ) {
			if ( ! $has_signature ) {
				return new WP_Error( 'pwc_webhook_sync_failed', __( 'Unable to confirm unsigned webhook with PayWithCrypto.', 'paywithcrypto-woocommerce' ), array( 'status' => 502 ) );
			}

			$applied = PWC_Order_Sync::apply_payment_status( $order, $data, 'signed-webhook' );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			$sync = $applied;
		}

		return array(
			'success'  => true,
			'order_id' => $order->get_id(),
			'status'   => isset( $sync['status'] ) ? $sync['status'] : $status,
		);
	}

	/**
	 * Unwrap common response data envelope.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>
	 */
	private static function unwrap_payload_data( array $payload ) {
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			return $payload['data'];
		}

		return $payload;
	}

	/**
	 * Extract scalar value by first matching key.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $keys Keys.
	 * @return string
	 */
	private static function first_non_empty( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return sanitize_text_field( (string) $data[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Get HTTP status from WP_Error.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	private static function get_error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return absint( $data['status'] );
		}

		return 400;
	}
}
