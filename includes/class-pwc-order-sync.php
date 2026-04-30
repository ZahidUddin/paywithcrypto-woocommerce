<?php
/**
 * PayWithCrypto order status synchronization.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs PWC statuses into WooCommerce orders.
 */
class PWC_Order_Sync {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'pwc_reconcile_pending_orders', array( __CLASS__, 'reconcile_pending_orders' ) );
	}

	/**
	 * Reusable backend status sync.
	 *
	 * @param int|WC_Order $wc_order_id WooCommerce order id or object.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sync_pwc_payment_status( $wc_order_id ) {
		$order = $wc_order_id instanceof WC_Order ? $wc_order_id : wc_get_order( absint( $wc_order_id ) );
		if ( ! $order ) {
			return new WP_Error( 'pwc_order_not_found', __( 'WooCommerce order was not found.', 'paywithcrypto-woocommerce' ) );
		}

		$pwc_payment_id = self::get_pwc_payment_id( $order );
		if ( '' === $pwc_payment_id ) {
			return new WP_Error( 'pwc_missing_payment_id', __( 'PayWithCrypto payment id is missing for this order.', 'paywithcrypto-woocommerce' ) );
		}

		$lock_key = 'pwc_sync_lock_' . $order->get_id();
		if ( get_transient( $lock_key ) ) {
			return self::get_public_status_data( $order );
		}

		set_transient( $lock_key, 1, 20 );

		$client = pwc_get_api_client();
		$result = $client->get_payment_status( $pwc_payment_id );

		delete_transient( $lock_key );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data ) ) {
			return new WP_Error( 'pwc_empty_status', __( 'PayWithCrypto returned an empty status response.', 'paywithcrypto-woocommerce' ) );
		}

		self::apply_payment_status( $order, $data, 'api' );

		return self::get_public_status_data( $order );
	}

	/**
	 * Apply status data from API or a verified webhook.
	 *
	 * @param WC_Order            $order Order.
	 * @param array<string,mixed> $data Status data.
	 * @param string              $source Source label.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply_payment_status( WC_Order $order, array $data, $source = 'api' ) {
		$status = isset( $data['status'] ) ? self::normalize_status( $data['status'] ) : '';
		if ( '' === $status ) {
			return new WP_Error( 'pwc_missing_status', __( 'PayWithCrypto status is missing.', 'paywithcrypto-woocommerce' ) );
		}

		$validation = self::validate_status_data_matches_order( $order, $data, $status );
		if ( is_wp_error( $validation ) ) {
			$order->add_order_note( $validation->get_error_message() );
			$order->save();
			return $validation;
		}

		$previous_status = self::normalize_status( $order->get_meta( '_pwc_status', true ) );
		$previous_tx     = sanitize_text_field( (string) $order->get_meta( '_pwc_tx_hash', true ) );
		$tx_hash         = self::extract_transaction_id( $data );
		$payment_id      = self::first_non_empty( $data, array( 'payment_id', 'order_id', 'id' ) );
		$external_id     = self::first_non_empty( $data, array( 'external_id', 'merchant_order_id' ) );

		if ( '' !== $payment_id ) {
			$order->update_meta_data( '_pwc_payment_id', sanitize_text_field( $payment_id ) );
			$order->update_meta_data( '_pwc_order_id', sanitize_text_field( $payment_id ) );
		}

		if ( '' !== $external_id ) {
			$order->update_meta_data( '_pwc_external_id', sanitize_text_field( $external_id ) );
		}

		$order->update_meta_data( '_pwc_status', $status );
		$order->update_meta_data( '_pwc_amount_fiat', sanitize_text_field( self::first_non_empty( $data, array( 'amount_fiat', 'amount' ) ) ) );
		$order->update_meta_data( '_pwc_fiat_currency', sanitize_text_field( self::first_non_empty( $data, array( 'fiat', 'fiat_currency' ) ) ?: $order->get_currency() ) );
		$order->update_meta_data( '_pwc_amount_crypto', sanitize_text_field( self::first_non_empty( $data, array( 'amount_crypto', 'crypto_amount' ) ) ) );
		$order->update_meta_data( '_pwc_crypto', sanitize_text_field( self::first_non_empty( $data, array( 'crypto' ) ) ) );
		$order->update_meta_data( '_pwc_payment_address', sanitize_text_field( self::first_non_empty( $data, array( 'payment_address', 'address' ) ) ) );
		$order->update_meta_data( '_pwc_qr_code_data', sanitize_text_field( self::first_non_empty( $data, array( 'qr_code_data', 'payment_address' ) ) ) );
		$order->update_meta_data( '_pwc_chain', sanitize_text_field( self::first_non_empty( $data, array( 'chain' ) ) ) );
		$order->update_meta_data( '_pwc_network', sanitize_text_field( self::first_non_empty( $data, array( 'network' ) ) ) );
		$order->update_meta_data( '_pwc_confirmed_amount', sanitize_text_field( self::first_non_empty( $data, array( 'confirmed_amount', 'paid_amount' ) ) ) );
		$order->update_meta_data( '_pwc_remaining_amount', sanitize_text_field( self::first_non_empty( $data, array( 'remaining_amount' ) ) ) );
		$order->update_meta_data( '_pwc_expires_at', sanitize_text_field( self::first_non_empty( $data, array( 'expires_at', 'expired_at' ) ) ) );
		$order->update_meta_data( '_pwc_created_at', sanitize_text_field( self::first_non_empty( $data, array( 'created_at' ) ) ) );
		$order->update_meta_data( '_pwc_tx_hash', sanitize_text_field( $tx_hash ) );
		$order->update_meta_data( '_pwc_payment_status_message', wp_kses_post( self::first_non_empty( $data, array( 'payment_status_message', 'message' ) ) ) );
		$order->update_meta_data( '_pwc_transactions', self::sanitize_transactions( isset( $data['transactions'] ) && is_array( $data['transactions'] ) ? $data['transactions'] : array() ) );
		$order->update_meta_data( '_pwc_last_sync', gmdate( 'c' ) );
		$order->update_meta_data( '_pwc_last_sync_source', sanitize_key( $source ) );

		self::update_wc_order_status( $order, $status, $tx_hash, $previous_status, $previous_tx, $data );

		$order->save();

		return self::get_public_status_data( $order );
	}

	/**
	 * Return cached public status data for frontend/admin display.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function get_public_status_data( WC_Order $order ) {
		$status       = self::normalize_status( $order->get_meta( '_pwc_status', true ) );
		$status       = $status ? $status : 'PENDING';
		$address      = sanitize_text_field( (string) $order->get_meta( '_pwc_payment_address', true ) );
		$qr_code_data = sanitize_text_field( (string) $order->get_meta( '_pwc_qr_code_data', true ) );
		$transactions = $order->get_meta( '_pwc_transactions', true );
		$transactions = is_array( $transactions ) ? $transactions : array();
		$tx_hash      = sanitize_text_field( (string) $order->get_meta( '_pwc_tx_hash', true ) );

		if ( '' === $tx_hash ) {
			$tx_hash = self::extract_transaction_id( array( 'transactions' => $transactions ) );
		}

		return array(
			'order_id'                => $order->get_id(),
			'status'                  => $status,
			'status_label'            => self::get_status_label( $status ),
			'amount_crypto'           => sanitize_text_field( (string) $order->get_meta( '_pwc_amount_crypto', true ) ),
			'amount_fiat'             => sanitize_text_field( (string) $order->get_meta( '_pwc_amount_fiat', true ) ),
			'fiat_currency'           => sanitize_text_field( (string) ( $order->get_meta( '_pwc_fiat_currency', true ) ?: $order->get_currency() ) ),
			'crypto'                  => sanitize_text_field( (string) $order->get_meta( '_pwc_crypto', true ) ),
			'payment_address'         => $address,
			'qr_code_data'            => $qr_code_data ? $qr_code_data : $address,
			'chain'                   => sanitize_text_field( (string) $order->get_meta( '_pwc_chain', true ) ),
			'network'                 => sanitize_text_field( (string) $order->get_meta( '_pwc_network', true ) ),
			'confirmed_amount'        => sanitize_text_field( (string) $order->get_meta( '_pwc_confirmed_amount', true ) ),
			'remaining_amount'        => sanitize_text_field( (string) $order->get_meta( '_pwc_remaining_amount', true ) ),
			'expires_at'              => sanitize_text_field( (string) $order->get_meta( '_pwc_expires_at', true ) ),
			'tx_hash'                 => $tx_hash,
			'transaction_url'         => self::get_transaction_url( $transactions, $tx_hash ),
			'payment_status_message'  => wp_kses_post( (string) $order->get_meta( '_pwc_payment_status_message', true ) ),
			'redirect_url'            => PWC_Payment_Page::get_return_url( $order ),
			'is_terminal'             => self::is_terminal_status( $status ),
			'last_sync'               => sanitize_text_field( (string) $order->get_meta( '_pwc_last_sync', true ) ),
		);
	}

	/**
	 * Save create-order response data into order meta.
	 *
	 * @param WC_Order            $order Order.
	 * @param array<string,mixed> $data Create-order response data.
	 */
	public static function save_create_order_data( WC_Order $order, array $data ) {
		$pwc_order_id = self::first_non_empty( $data, array( 'payment_id', 'order_id', 'id' ) );
		if ( '' !== $pwc_order_id ) {
			$order->update_meta_data( '_pwc_payment_id', sanitize_text_field( $pwc_order_id ) );
			$order->update_meta_data( '_pwc_order_id', sanitize_text_field( $pwc_order_id ) );
		}

		$order->update_meta_data( '_pwc_status', self::normalize_status( self::first_non_empty( $data, array( 'status' ) ) ) ?: 'PENDING' );
		$order->update_meta_data( '_pwc_amount_fiat', sanitize_text_field( self::first_non_empty( $data, array( 'amount_fiat', 'amount' ) ) ) );
		$order->update_meta_data( '_pwc_fiat_currency', sanitize_text_field( self::first_non_empty( $data, array( 'fiat', 'fiat_currency' ) ) ?: $order->get_currency() ) );
		$order->update_meta_data( '_pwc_amount_crypto', sanitize_text_field( self::first_non_empty( $data, array( 'amount_crypto', 'crypto_amount' ) ) ) );
		$order->update_meta_data( '_pwc_crypto', sanitize_text_field( self::first_non_empty( $data, array( 'crypto' ) ) ) );
		$order->update_meta_data( '_pwc_payment_url', esc_url_raw( self::first_non_empty( $data, array( 'payment_url' ) ) ) );
		$order->update_meta_data( '_pwc_chain', sanitize_text_field( pwc_get_gateway_setting( 'chain', '' ) ) );
		$order->update_meta_data( '_pwc_network', sanitize_text_field( pwc_get_gateway_setting( 'network', '' ) ) );
		$order->update_meta_data( '_pwc_expires_at', sanitize_text_field( self::first_non_empty( $data, array( 'expires_at', 'expired_at' ) ) ) );
		$order->update_meta_data( '_pwc_last_sync', gmdate( 'c' ) );
		$order->save();
	}

	/**
	 * Reconcile old pending PayWithCrypto orders.
	 */
	public static function reconcile_pending_orders() {
		if ( 'yes' !== pwc_get_gateway_setting( 'enabled', 'no' ) ) {
			return;
		}

		$order_ids = wc_get_orders(
			array(
				'limit'          => 20,
				'return'         => 'ids',
				'payment_method' => PWC_GATEWAY_ID,
				'status'         => array( 'pending', 'on-hold' ),
				'date_created'   => '>' . ( time() - DAY_IN_SECONDS ),
			)
		);

		foreach ( $order_ids as $order_id ) {
			self::sync_pwc_payment_status( absint( $order_id ) );
		}
	}

	/**
	 * Find WooCommerce order id from PWC payload.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return int
	 */
	public static function find_order_id_from_payload( array $payload ) {
		$external_id = self::first_non_empty( $payload, array( 'external_id', 'merchant_order_id' ) );
		$payment_id  = self::first_non_empty( $payload, array( 'payment_id', 'order_id', 'id' ) );

		if ( '' !== $external_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => '_pwc_external_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => sanitize_text_field( $external_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( ! empty( $orders ) ) {
				return absint( $orders[0] );
			}
		}

		if ( '' !== $payment_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => '_pwc_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => sanitize_text_field( $payment_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( ! empty( $orders ) ) {
				return absint( $orders[0] );
			}
		}

		return 0;
	}

	/**
	 * Normalize PWC status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = strtoupper( sanitize_key( (string) $status ) );
		$status = str_replace( '-', '_', $status );

		return $status;
	}

	/**
	 * Whether status is terminal for polling.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_terminal_status( $status ) {
		return in_array( self::normalize_status( $status ), array( 'PAID', 'FAILED', 'EXPIRED', 'CLOSED', 'CANCELLED' ), true );
	}

	/**
	 * Return human label for PWC status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function get_status_label( $status ) {
		switch ( self::normalize_status( $status ) ) {
			case 'PAID':
				return __( 'Paid', 'paywithcrypto-woocommerce' );
			case 'FAILED':
				return __( 'Failed', 'paywithcrypto-woocommerce' );
			case 'EXPIRED':
				return __( 'Expired', 'paywithcrypto-woocommerce' );
			case 'PARTIALLY_PAID':
				return __( 'Partially Paid', 'paywithcrypto-woocommerce' );
			case 'RISK_LOCKED':
				return __( 'Risk Locked', 'paywithcrypto-woocommerce' );
			case 'CANCELLED':
				return __( 'Cancelled', 'paywithcrypto-woocommerce' );
			case 'CLOSED':
				return __( 'Closed', 'paywithcrypto-woocommerce' );
			default:
				return __( 'Awaiting Payment', 'paywithcrypto-woocommerce' );
		}
	}

	/**
	 * Update WooCommerce order state from PWC status.
	 *
	 * @param WC_Order            $order Order.
	 * @param string              $status PWC status.
	 * @param string              $tx_hash Transaction hash.
	 * @param string              $previous_status Previous PWC status.
	 * @param string              $previous_tx Previous transaction hash.
	 * @param array<string,mixed> $data Status data.
	 */
	private static function update_wc_order_status( WC_Order $order, $status, $tx_hash, $previous_status, $previous_tx, array $data ) {
		$status_changed = $status !== $previous_status;
		$new_tx         = '' !== $tx_hash && $tx_hash !== $previous_tx;
		$message        = wp_kses_post( self::first_non_empty( $data, array( 'payment_status_message' ) ) );

		switch ( $status ) {
			case 'PAID':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $tx_hash ? $tx_hash : self::get_pwc_payment_id( $order ) );
					$order->add_order_note( self::build_order_note( __( 'PayWithCrypto payment confirmed.', 'paywithcrypto-woocommerce' ), $tx_hash, $message ) );
				} elseif ( $new_tx ) {
					$order->add_order_note( self::build_order_note( __( 'Additional PayWithCrypto transaction detected.', 'paywithcrypto-woocommerce' ), $tx_hash, $message ) );
				}
				break;

			case 'FAILED':
				if ( 'failed' !== $order->get_status() ) {
					$order->update_status( 'failed', __( 'PayWithCrypto payment failed.', 'paywithcrypto-woocommerce' ) );
				}
				break;

			case 'EXPIRED':
				if ( 'failed' !== $order->get_status() && ! $order->is_paid() ) {
					$order->update_status( 'failed', __( 'PayWithCrypto payment expired.', 'paywithcrypto-woocommerce' ) );
				}
				break;

			case 'PARTIALLY_PAID':
				if ( ! $order->is_paid() && 'on-hold' !== $order->get_status() ) {
					$order->update_status( 'on-hold', __( 'PayWithCrypto partial payment received. Awaiting remaining amount.', 'paywithcrypto-woocommerce' ) );
				}
				if ( $status_changed || $new_tx ) {
					$order->add_order_note( self::build_order_note( __( 'PayWithCrypto partial payment update.', 'paywithcrypto-woocommerce' ), $tx_hash, $message ) );
				}
				break;

			case 'RISK_LOCKED':
				if ( ! $order->is_paid() && 'on-hold' !== $order->get_status() ) {
					$order->update_status( 'on-hold', __( 'PayWithCrypto payment is risk locked.', 'paywithcrypto-woocommerce' ) );
				}
				break;

			case 'CANCELLED':
			case 'CLOSED':
				if ( ! $order->is_paid() && 'failed' !== $order->get_status() ) {
					$order->update_status( 'failed', __( 'PayWithCrypto payment was cancelled or closed.', 'paywithcrypto-woocommerce' ) );
				}
				break;

			case 'PENDING':
			default:
				if ( self::has_detected_transaction( $data ) && ! $order->is_paid() && 'on-hold' !== $order->get_status() ) {
					$order->update_status( 'on-hold', __( 'PayWithCrypto transaction detected and awaiting confirmation.', 'paywithcrypto-woocommerce' ) );
				}
				if ( $message && ( $status_changed || $new_tx ) ) {
					$order->add_order_note( self::build_order_note( __( 'PayWithCrypto payment update.', 'paywithcrypto-woocommerce' ), $tx_hash, $message ) );
				}
				break;
		}

		if ( $status_changed || $new_tx ) {
			$order->update_meta_data( '_pwc_last_noted_status', $status );
			$order->update_meta_data( '_pwc_last_noted_tx_hash', $tx_hash );
		}
	}

	/**
	 * Validate trusted PWC status data against the WooCommerce order before applying it.
	 *
	 * @param WC_Order            $order Order.
	 * @param array<string,mixed> $data Status data.
	 * @param string              $status Normalized PWC status.
	 * @return true|WP_Error
	 */
	private static function validate_status_data_matches_order( WC_Order $order, array $data, $status ) {
		$payment_id        = self::first_non_empty( $data, array( 'payment_id', 'order_id', 'id' ) );
		$stored_payment_id = self::get_pwc_payment_id( $order );

		if ( '' !== $payment_id && '' !== $stored_payment_id && ! hash_equals( $stored_payment_id, sanitize_text_field( $payment_id ) ) ) {
			return new WP_Error( 'pwc_status_payment_id_mismatch', __( 'PayWithCrypto status verification failed: payment id does not match this WooCommerce order.', 'paywithcrypto-woocommerce' ) );
		}

		$external_id        = self::first_non_empty( $data, array( 'external_id', 'merchant_order_id' ) );
		$stored_external_id = sanitize_text_field( (string) $order->get_meta( '_pwc_external_id', true ) );
		if ( '' !== $external_id && '' !== $stored_external_id && ! hash_equals( $stored_external_id, sanitize_text_field( $external_id ) ) ) {
			return new WP_Error( 'pwc_status_external_id_mismatch', __( 'PayWithCrypto status verification failed: external order id does not match this WooCommerce order.', 'paywithcrypto-woocommerce' ) );
		}

		$fiat_currency = strtoupper( sanitize_text_field( self::first_non_empty( $data, array( 'fiat', 'fiat_currency' ) ) ) );
		if ( '' !== $fiat_currency && strtoupper( $order->get_currency() ) !== $fiat_currency ) {
			return new WP_Error( 'pwc_status_currency_mismatch', __( 'PayWithCrypto status verification failed: fiat currency does not match this WooCommerce order.', 'paywithcrypto-woocommerce' ) );
		}

		$amount_fiat = self::first_non_empty( $data, array( 'amount_fiat', 'amount' ) );
		if ( '' !== $amount_fiat ) {
			$order_total = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
			$pwc_total   = wc_format_decimal( $amount_fiat, wc_get_price_decimals() );

			if ( $order_total !== $pwc_total ) {
				return new WP_Error( 'pwc_status_amount_mismatch', __( 'PayWithCrypto status verification failed: payment amount does not match this WooCommerce order.', 'paywithcrypto-woocommerce' ) );
			}
		}

		$expected_crypto = sanitize_text_field( (string) $order->get_meta( '_pwc_crypto', true ) );
		if ( '' === $expected_crypto ) {
			$expected_crypto = sanitize_text_field( (string) pwc_get_gateway_setting( 'crypto', '' ) );
		}

		$crypto = sanitize_text_field( self::first_non_empty( $data, array( 'crypto' ) ) );
		if ( '' !== $crypto && '' !== $expected_crypto && strtoupper( $expected_crypto ) !== strtoupper( $crypto ) ) {
			return new WP_Error( 'pwc_status_crypto_mismatch', __( 'PayWithCrypto status verification failed: crypto token does not match this WooCommerce order.', 'paywithcrypto-woocommerce' ) );
		}

		if ( 'PAID' === $status ) {
			$remaining = self::first_non_empty( $data, array( 'remaining_amount' ) );
			if ( '' !== $remaining && (float) $remaining > 0 ) {
				return new WP_Error( 'pwc_status_remaining_amount', __( 'PayWithCrypto status verification failed: payment still has a remaining amount.', 'paywithcrypto-woocommerce' ) );
			}

			$amount_crypto    = self::first_non_empty( $data, array( 'amount_crypto', 'crypto_amount' ) );
			$confirmed_amount = self::first_non_empty( $data, array( 'confirmed_amount', 'paid_amount' ) );
			if ( '' !== $amount_crypto && '' !== $confirmed_amount && (float) $confirmed_amount < (float) $amount_crypto ) {
				return new WP_Error( 'pwc_status_confirmed_amount_mismatch', __( 'PayWithCrypto status verification failed: confirmed crypto amount is lower than the required amount.', 'paywithcrypto-woocommerce' ) );
			}
		}

		return true;
	}

	/**
	 * Build a safe order note.
	 *
	 * @param string $base Base message.
	 * @param string $tx_hash Transaction hash.
	 * @param string $message API message.
	 * @return string
	 */
	private static function build_order_note( $base, $tx_hash = '', $message = '' ) {
		$note = $base;
		if ( '' !== $tx_hash ) {
			$note .= ' ' . sprintf( /* translators: %s: transaction hash */ __( 'Transaction: %s', 'paywithcrypto-woocommerce' ), sanitize_text_field( $tx_hash ) );
		}
		if ( '' !== $message ) {
			$note .= ' ' . wp_strip_all_tags( $message );
		}

		return $note;
	}

	/**
	 * Get PWC payment id from order meta.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function get_pwc_payment_id( WC_Order $order ) {
		$payment_id = sanitize_text_field( (string) $order->get_meta( '_pwc_payment_id', true ) );
		if ( '' === $payment_id ) {
			$payment_id = sanitize_text_field( (string) $order->get_meta( '_pwc_order_id', true ) );
		}

		return $payment_id;
	}

	/**
	 * Extract first non-empty scalar from data.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<int,string>   $keys Keys.
	 * @return string
	 */
	private static function first_non_empty( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}

		return '';
	}

	/**
	 * Extract transaction id from payment data.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	private static function extract_transaction_id( array $data ) {
		if ( isset( $data['tx_hash'] ) && is_scalar( $data['tx_hash'] ) && '' !== (string) $data['tx_hash'] ) {
			return sanitize_text_field( (string) $data['tx_hash'] );
		}

		if ( isset( $data['payment_tx_hash'] ) && is_scalar( $data['payment_tx_hash'] ) && '' !== (string) $data['payment_tx_hash'] ) {
			return sanitize_text_field( (string) $data['payment_tx_hash'] );
		}

		if ( isset( $data['transactions'] ) && is_array( $data['transactions'] ) ) {
			foreach ( $data['transactions'] as $transaction ) {
				if ( ! is_array( $transaction ) ) {
					continue;
				}

				if ( isset( $transaction['tx_id'] ) && is_scalar( $transaction['tx_id'] ) && '' !== (string) $transaction['tx_id'] ) {
					return sanitize_text_field( (string) $transaction['tx_id'] );
				}
			}
		}

		return '';
	}

	/**
	 * Sanitize transactions for storage and public return.
	 *
	 * @param array<int,mixed> $transactions Transactions.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_transactions( array $transactions ) {
		$clean = array();

		foreach ( $transactions as $transaction ) {
			if ( ! is_array( $transaction ) ) {
				continue;
			}

			$clean[] = array(
				'tx_id'                  => isset( $transaction['tx_id'] ) ? sanitize_text_field( (string) $transaction['tx_id'] ) : '',
				'chain'                  => isset( $transaction['chain'] ) ? sanitize_text_field( (string) $transaction['chain'] ) : '',
				'from_address'           => isset( $transaction['from_address'] ) ? sanitize_text_field( (string) $transaction['from_address'] ) : '',
				'to_address'             => isset( $transaction['to_address'] ) ? sanitize_text_field( (string) $transaction['to_address'] ) : '',
				'amount'                 => isset( $transaction['amount'] ) ? sanitize_text_field( (string) $transaction['amount'] ) : '',
				'confirmations'          => isset( $transaction['confirmations'] ) ? absint( $transaction['confirmations'] ) : 0,
				'required_confirmations' => isset( $transaction['required_confirmations'] ) ? absint( $transaction['required_confirmations'] ) : 0,
				'block_height'           => isset( $transaction['block_height'] ) ? absint( $transaction['block_height'] ) : 0,
				'detected_at'            => isset( $transaction['detected_at'] ) ? sanitize_text_field( (string) $transaction['detected_at'] ) : '',
				'status'                 => isset( $transaction['status'] ) ? self::normalize_status( $transaction['status'] ) : '',
				'explorer_base_url'      => isset( $transaction['explorer_base_url'] ) ? esc_url_raw( (string) $transaction['explorer_base_url'] ) : '',
			);
		}

		return $clean;
	}

	/**
	 * Return transaction explorer URL when available.
	 *
	 * @param array<int,array<string,mixed>> $transactions Transactions.
	 * @param string                         $tx_hash Transaction hash.
	 * @return string
	 */
	private static function get_transaction_url( array $transactions, $tx_hash ) {
		foreach ( $transactions as $transaction ) {
			if ( empty( $transaction['tx_id'] ) || empty( $transaction['explorer_base_url'] ) ) {
				continue;
			}

			if ( '' === $tx_hash || $transaction['tx_id'] === $tx_hash ) {
				return esc_url_raw( trailingslashit( $transaction['explorer_base_url'] ) . rawurlencode( $transaction['tx_id'] ) );
			}
		}

		return '';
	}

	/**
	 * Detect any submitted transaction.
	 *
	 * @param array<string,mixed> $data Status data.
	 * @return bool
	 */
	private static function has_detected_transaction( array $data ) {
		$confirmed = self::first_non_empty( $data, array( 'confirmed_amount', 'paid_amount' ) );
		if ( '' !== $confirmed && (float) $confirmed > 0 ) {
			return true;
		}

		return isset( $data['transactions'] ) && is_array( $data['transactions'] ) && ! empty( $data['transactions'] );
	}
}

if ( ! function_exists( 'pwc_sync_pwc_payment_status' ) ) {
	/**
	 * Global prefixed sync wrapper.
	 *
	 * @param int $wc_order_id WooCommerce order id.
	 * @return array<string,mixed>|WP_Error
	 */
	function pwc_sync_pwc_payment_status( $wc_order_id ) {
		return PWC_Order_Sync::sync_pwc_payment_status( $wc_order_id );
	}
}
