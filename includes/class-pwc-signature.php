<?php
/**
 * PayWithCrypto signing helpers.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized PWC signature logic.
 */
class PWC_Signature {
	/**
	 * Return a lowercase SHA-256 secret hash, unless the value is already a hash.
	 *
	 * @param string $secret Secret or SecretHash.
	 * @return string
	 */
	public static function get_secret_hash( $secret ) {
		$secret = trim( (string) $secret );

		if ( preg_match( '/^[a-f0-9]{64}$/i', $secret ) ) {
			return strtolower( $secret );
		}

		return hash( 'sha256', $secret );
	}

	/**
	 * Return the signing suffix according to configured mode.
	 *
	 * @param string $secret Secret value.
	 * @param string $secret_mode hash or raw.
	 * @return string
	 */
	public static function get_signing_secret( $secret, $secret_mode = 'hash' ) {
		return 'raw' === $secret_mode ? trim( (string) $secret ) : self::get_secret_hash( $secret );
	}

	/**
	 * Generate a random nonce.
	 *
	 * @param int $length Nonce length.
	 * @return string
	 */
	public static function generate_nonce( $length = 12 ) {
		$length = max( 8, min( 16, absint( $length ) ) );
		$bytes  = wp_generate_password( $length, false, false );

		return substr( preg_replace( '/[^A-Za-z0-9]/', '', $bytes ), 0, $length );
	}

	/**
	 * Generate uppercase MD5 header signature.
	 *
	 * @param string $app_key App key id only.
	 * @param string $secret Secret value.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce Nonce.
	 * @param string $app_version App version.
	 * @param string $secret_mode hash or raw.
	 * @return string
	 */
	public static function generate_header_sign( $app_key, $secret, $timestamp, $nonce, $app_version, $secret_mode = 'hash' ) {
		$data = array(
			'AppKey'     => (string) $app_key,
			'Timestamp'  => (string) $timestamp,
			'Nonce'      => (string) $nonce,
			'AppVersion' => (string) $app_version,
		);

		uksort(
			$data,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a, (string) $b );
			}
		);

		$sign_string = '';
		foreach ( $data as $key => $value ) {
			$sign_string .= $key . $value;
		}

		$sign_string .= self::get_signing_secret( $secret, $secret_mode );

		return strtoupper( md5( $sign_string ) );
	}

	/**
	 * Generate lowercase MD5 request body signature.
	 *
	 * PWC validates the create-order signature against all scalar order fields
	 * documented for wallet transfer: external_id, amount, fiat, chain, network,
	 * crypto, callback_url, and redirect_url when present.
	 *
	 * @param array<string,mixed> $data Request data.
	 * @param string              $secret Secret value.
	 * @param string              $secret_mode hash or raw.
	 * @return string
	 */
	public static function generate_body_signature( array $data, $secret, $secret_mode = 'hash' ) {
		$signature_data = array();
		$allowed_keys   = array( 'external_id', 'amount', 'fiat', 'chain', 'network', 'crypto', 'callback_url', 'redirect_url' );

		foreach ( $allowed_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = $data[ $key ];
			if ( 'redirect_url' === $key && ( null === $value || '' === (string) $value ) ) {
				continue;
			}

			$signature_data[ $key ] = self::stringify_value( $value );
		}

		ksort( $signature_data, SORT_STRING );

		$parts = array();
		foreach ( $signature_data as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		$sign_string = implode( '&', $parts ) . self::get_signing_secret( $secret, $secret_mode );

		return md5( $sign_string );
	}

	/**
	 * Verify a PWC webhook signature if the request supplies one.
	 *
	 * The public docs do not fully specify callback signature format. This method
	 * validates documented header signatures when Timestamp/Nonce/App-Version are
	 * present and validates body signatures against scalar payload fields when a
	 * payload-level signature is supplied. Requests without a signature return
	 * true and must be confirmed server-side via the payment status endpoint.
	 *
	 * @param string              $raw_body Raw JSON body.
	 * @param array<string,mixed> $payload Parsed JSON payload.
	 * @param array<string,mixed> $headers Request headers.
	 * @param string              $secret Secret value.
	 * @param string              $secret_mode hash or raw.
	 * @param string              $app_key Optional app key for header verification.
	 * @param string              $app_version Optional app version for header verification.
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, array $payload, array $headers, $secret, $secret_mode = 'hash', $app_key = '', $app_version = '1.0.0' ) {
		$normalized_headers = self::normalize_headers( $headers );
		$signature          = self::get_header_value( $normalized_headers, array( 'sign', 'x-pwc-signature', 'x-signature', 'signature' ) );

		if ( '' !== $signature ) {
			$timestamp = self::get_header_value( $normalized_headers, array( 'timestamp', 'x-pwc-timestamp' ) );
			$nonce     = self::get_header_value( $normalized_headers, array( 'nonce', 'x-pwc-nonce' ) );
			$version   = self::get_header_value( $normalized_headers, array( 'app-version', 'appversion', 'x-pwc-app-version' ) );
			$key       = self::get_header_value( $normalized_headers, array( 'appkey', 'app-key', 'x-pwc-appkey' ) );

			$key     = '' !== $key ? $key : $app_key;
			$version = '' !== $version ? $version : $app_version;

			if ( '' !== $key && '' !== $timestamp && '' !== $nonce && '' !== $version ) {
				$expected = self::generate_header_sign( $key, $secret, $timestamp, $nonce, $version, $secret_mode );

				return hash_equals( $expected, strtoupper( $signature ) );
			}
		}

		$payload_signature = '';
		foreach ( array( 'signature', 'sign', 'Sign' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
				$payload_signature = (string) $payload[ $key ];
				unset( $payload[ $key ] );
				break;
			}
		}

		if ( '' === $payload_signature ) {
			return true;
		}

		$signature_data = array();
		foreach ( $payload as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$signature_data[ (string) $key ] = self::stringify_value( $value );
			}
		}

		ksort( $signature_data, SORT_STRING );

		$parts = array();
		foreach ( $signature_data as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		$candidates = array(
			md5( implode( '&', $parts ) . '&' . self::get_signing_secret( $secret, $secret_mode ) ),
			md5( implode( '&', $parts ) . self::get_signing_secret( $secret, $secret_mode ) ),
			md5( $raw_body . self::get_signing_secret( $secret, $secret_mode ) ),
		);

		foreach ( $candidates as $candidate ) {
			if ( hash_equals( strtolower( $candidate ), strtolower( $payload_signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine if a webhook request included a signature field/header.
	 *
	 * @param array<string,mixed> $payload Parsed payload.
	 * @param array<string,mixed> $headers Headers.
	 * @return bool
	 */
	public static function webhook_has_signature( array $payload, array $headers ) {
		$headers = self::normalize_headers( $headers );

		if ( '' !== self::get_header_value( $headers, array( 'sign', 'x-pwc-signature', 'x-signature', 'signature' ) ) ) {
			return true;
		}

		foreach ( array( 'signature', 'sign', 'Sign' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return request headers in a portable way.
	 *
	 * @return array<string,string>
	 */
	public static function get_request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}

		$headers = array();
		foreach ( $_SERVER as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 0 === strpos( $key, 'HTTP_' ) ) {
				$name             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $key, 5 ) ) ) ) );
				$headers[ $name ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}

		return $headers;
	}

	/**
	 * Normalize header names to lowercase.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @return array<string,string>
	 */
	private static function normalize_headers( array $headers ) {
		$normalized = array();
		foreach ( $headers as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$normalized[ strtolower( (string) $key ) ] = sanitize_text_field( (string) $value );
		}

		return $normalized;
	}

	/**
	 * Read one header value by any accepted name.
	 *
	 * @param array<string,string> $headers Headers.
	 * @param array<int,string>    $keys Header names.
	 * @return string
	 */
	private static function get_header_value( array $headers, array $keys ) {
		foreach ( $keys as $key ) {
			$key = strtolower( $key );
			if ( isset( $headers[ $key ] ) && '' !== $headers[ $key ] ) {
				return $headers[ $key ];
			}
		}

		return '';
	}

	/**
	 * Convert scalar values to signature strings.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function stringify_value( $value ) {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return trim( (string) $value );
	}
}
