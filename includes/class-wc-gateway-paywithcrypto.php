<?php
/**
 * WooCommerce PayWithCrypto gateway.
 *
 * @package PayWithCrypto_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce gateway for PWC crypto payments.
 */
class WC_Gateway_PayWithCrypto extends WC_Payment_Gateway {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = PWC_GATEWAY_ID;
		$this->method_title       = __( 'PayWithCrypto', 'paywithcrypto-woocommerce' );
		$this->method_description = __( 'Accept crypto payments through PayWithCrypto. The basic version includes Crypto Wallet Transfer.', 'paywithcrypto-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay with Crypto', 'paywithcrypto-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay securely using crypto wallet transfer.', 'paywithcrypto-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize admin fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'       => __( 'Enable/Disable', 'paywithcrypto-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable PayWithCrypto', 'paywithcrypto-woocommerce' ),
				'default'     => 'no',
			),
			'title'            => array(
				'title'       => __( 'Checkout Title', 'paywithcrypto-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'paywithcrypto-woocommerce' ),
				'default'     => __( 'Pay with Crypto', 'paywithcrypto-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Checkout Description', 'paywithcrypto-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'paywithcrypto-woocommerce' ),
				'default'     => __( 'Pay securely using crypto wallet transfer.', 'paywithcrypto-woocommerce' ),
				'desc_tip'    => true,
			),
			'app_key'          => array(
				'title'       => __( 'App Key / API Key', 'paywithcrypto-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Enter only the AppKey key_id, for example ak_live_xxx. Do not paste the combined key.secret credential here.', 'paywithcrypto-woocommerce' ),
				'default'     => '',
			),
			'secret'           => array(
				'title'       => __( 'Secret', 'paywithcrypto-woocommerce' ),
				'type'        => 'secret',
				'description' => __( 'Stored server-side only. Leave blank to keep the existing secret.', 'paywithcrypto-woocommerce' ),
				'default'     => '',
			),
			'environment'      => array(
				'title'       => __( 'Environment', 'paywithcrypto-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Use Production with live credentials and Sandbox/Test with sandbox credentials. The fiat currency is read automatically from the WooCommerce order currency.', 'paywithcrypto-woocommerce' ),
				'default'     => 'production',
				'options'     => array(
					'production' => __( 'Production - https://checkout.paywithcrypto.vip/order', 'paywithcrypto-woocommerce' ),
					'sandbox'    => __( 'Sandbox/Test - https://api-test.paywithcrypto.vip/order', 'paywithcrypto-woocommerce' ),
				),
			),
			'chain'            => array(
				'title'       => __( 'Chain', 'paywithcrypto-woocommerce' ),
				'type'        => 'select',
				'default'     => 'BSC',
				'description' => __( 'Blockchain used for the wallet transfer. Use BSC for BNB Smart Chain or ETH for Ethereum. The selected chain must match the token and network configured below.', 'paywithcrypto-woocommerce' ),
				'options'     => array(
					'BSC' => __( 'BSC - BNB Smart Chain', 'paywithcrypto-woocommerce' ),
					'ETH' => __( 'ETH - Ethereum', 'paywithcrypto-woocommerce' ),
				),
			),
			'network'          => array(
				'title'       => __( 'Network', 'paywithcrypto-woocommerce' ),
				'type'        => 'select',
				'default'     => 'mainnet',
				'description' => __( 'Use mainnet for live payments. For PWC sandbox examples, use test with BSC/PWCUSD-ERC20 or sepolia with ETH/TEST-ERC20.', 'paywithcrypto-woocommerce' ),
				'options'     => array(
					'mainnet' => __( 'mainnet - Live network', 'paywithcrypto-woocommerce' ),
					'test'    => __( 'test - BSC sandbox/test network', 'paywithcrypto-woocommerce' ),
					'sepolia' => __( 'sepolia - Ethereum test network', 'paywithcrypto-woocommerce' ),
				),
			),
			'crypto'           => array(
				'title'       => __( 'Crypto Token', 'paywithcrypto-woocommerce' ),
				'type'        => 'select',
				'default'     => 'USDT-ERC20',
				'description' => __( 'Token requested from the customer. Use USDT-ERC20 for live USDT payments, PWCUSD-ERC20 with BSC/test sandbox, or TEST-ERC20 with ETH/sepolia sandbox.', 'paywithcrypto-woocommerce' ),
				'options'     => array(
					'USDT-ERC20'   => __( 'USDT-ERC20 - Live USDT', 'paywithcrypto-woocommerce' ),
					'PWCUSD-ERC20' => __( 'PWCUSD-ERC20 - BSC sandbox token', 'paywithcrypto-woocommerce' ),
					'TEST-ERC20'   => __( 'TEST-ERC20 - Ethereum Sepolia test token', 'paywithcrypto-woocommerce' ),
				),
			),
			'show_expiration' => array(
				'title'       => __( 'Expiration Display', 'paywithcrypto-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show payment expiration timer when PWC returns an expiration value.', 'paywithcrypto-woocommerce' ),
				'default'     => 'yes',
			),
			'debug'            => array(
				'title'       => __( 'Debug Logging', 'paywithcrypto-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logs', 'paywithcrypto-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Logs non-sensitive API events to WooCommerce logs. Secrets and signatures are never logged.', 'paywithcrypto-woocommerce' ),
			),
			'cleanup_on_uninstall' => array(
				'title'       => __( 'Data Cleanup', 'paywithcrypto-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Delete PayWithCrypto settings when plugin is uninstalled', 'paywithcrypto-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Recommended for temporary/testing installs. Keep disabled on production if you want to preserve credentials and settings after uninstall.', 'paywithcrypto-woocommerce' ),
			),
		);

		/**
		 * Allow add-ons to extend the single PayWithCrypto gateway settings.
		 *
		 * @param array<string,array<string,mixed>> $form_fields Gateway fields.
		 * @param WC_Gateway_PayWithCrypto         $gateway     Gateway instance.
		 */
		$this->form_fields = apply_filters( 'pwc_gateway_form_fields', $this->form_fields, $this );
	}

	/**
	 * Gateway availability at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		$client = new PWC_API_Client( $this->settings );
		return $client->is_configured();
	}

	/**
	 * Checkout payment fields.
	 */
	public function payment_fields() {
		$description = apply_filters( 'pwc_gateway_checkout_description', $this->description, $this );
		if ( $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}

		/**
		 * Allow add-ons to render PayWithCrypto flow selection before the basic
		 * Crypto Wallet Transfer instructions.
		 *
		 * @param WC_Gateway_PayWithCrypto $gateway Gateway instance.
		 */
		do_action( 'pwc_gateway_payment_fields', $this );

		$show_wallet_transfer_note = (bool) apply_filters( 'pwc_gateway_show_wallet_transfer_note', true, $this );
		if ( $show_wallet_transfer_note ) {
			echo '<p class="pwc-checkout-note">' . esc_html__( 'For Crypto Wallet Transfer, after placing the order you will see a wallet address, exact crypto amount, and QR code. Complete the transfer from MetaMask, Trust Wallet, Coinbase Wallet, TokenPocket, imToken, or another compatible wallet.', 'paywithcrypto-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Process payment and redirect to PWC payment page.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Unable to find the order for PayWithCrypto payment.', 'paywithcrypto-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		/**
		 * Allow add-ons to handle alternate PayWithCrypto payment flows while
		 * keeping one WooCommerce payment method row.
		 *
		 * Return an array compatible with WC_Payment_Gateway::process_payment()
		 * to short-circuit the basic Crypto Wallet Transfer flow.
		 *
		 * @param null|array<string,string>|WP_Error $result  Existing result.
		 * @param WC_Order                           $order   WooCommerce order.
		 * @param WC_Gateway_PayWithCrypto           $gateway Gateway instance.
		 */
		$addon_result = apply_filters( 'pwc_gateway_process_payment', null, $order, $this );
		if ( is_array( $addon_result ) ) {
			return $addon_result;
		}

		if ( is_wp_error( $addon_result ) ) {
			wc_add_notice( $addon_result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = new PWC_API_Client( $this->settings );
		if ( ! $client->is_configured() ) {
			wc_add_notice( __( 'PayWithCrypto is not fully configured. Please choose another payment method or contact the store.', 'paywithcrypto-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$existing_payment_id = sanitize_text_field( (string) $order->get_meta( '_pwc_payment_id', true ) );
		$existing_status     = PWC_Order_Sync::normalize_status( $order->get_meta( '_pwc_status', true ) );
		if ( '' !== $existing_payment_id && ! PWC_Order_Sync::is_terminal_status( $existing_status ) ) {
			return array(
				'result'   => 'success',
				'redirect' => PWC_Payment_Page::get_payment_page_url( $order ),
			);
		}

		if ( ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending', __( 'Awaiting PayWithCrypto wallet transfer payment.', 'paywithcrypto-woocommerce' ) );
		} else {
			$order->add_order_note( __( 'Awaiting PayWithCrypto wallet transfer payment.', 'paywithcrypto-woocommerce' ) );
		}

		$result = $client->create_order( $order );
		if ( is_wp_error( $result ) ) {
			$order->update_status( 'failed', __( 'PayWithCrypto order creation failed.', 'paywithcrypto-woocommerce' ) );
			$order->add_order_note( sprintf( /* translators: %s: API error */ __( 'PayWithCrypto API error: %s', 'paywithcrypto-woocommerce' ), $result->get_error_message() ) );
			$error_data = $result->get_error_data();
			$http_code  = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 0;
			$api_message = $result->get_error_message();

			if ( 401 === $http_code ) {
				wc_add_notice( __( 'PayWithCrypto authorization failed. Please contact the store or choose another payment method.', 'paywithcrypto-woocommerce' ), 'error' );
			} elseif ( false !== stripos( $api_message, 'invalid signature' ) ) {
				wc_add_notice( __( 'PayWithCrypto rejected the payment request signature. Please contact the store or choose another payment method.', 'paywithcrypto-woocommerce' ), 'error' );
			} elseif ( false !== stripos( $api_message, 'invalid crypto' ) ) {
				wc_add_notice( __( 'PayWithCrypto rejected the configured crypto token, chain, or network. Please contact the store or choose another payment method.', 'paywithcrypto-woocommerce' ), 'error' );
			} else {
				wc_add_notice( __( 'Unable to create a PayWithCrypto payment request right now. Please try again or choose another payment method.', 'paywithcrypto-woocommerce' ), 'error' );
			}

			return array( 'result' => 'failure' );
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		PWC_Order_Sync::save_create_order_data( $order, $data );

		$pwc_payment_id = sanitize_text_field( (string) $order->get_meta( '_pwc_payment_id', true ) );
		$order->add_order_note(
			$pwc_payment_id
				? sprintf( /* translators: %s: PWC payment id */ __( 'PayWithCrypto payment request created. PWC payment ID: %s', 'paywithcrypto-woocommerce' ), $pwc_payment_id )
				: __( 'PayWithCrypto payment request created.', 'paywithcrypto-woocommerce' )
		);

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => PWC_Payment_Page::get_payment_page_url( $order ),
		);
	}

	/**
	 * Admin options with endpoint info.
	 */
	public function admin_options() {
		$client       = new PWC_API_Client( $this->settings );
		$callback_url = $client->get_callback_url();
		$rest_notify  = rest_url( 'paywithcrypto/v1/notify' );

		echo '<h2>' . esc_html( $this->method_title ) . '</h2>';
		echo wp_kses_post( wpautop( $this->method_description ) );
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Notify URL:', 'paywithcrypto-woocommerce' ) . '</strong> <code>' . esc_html( $callback_url ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'REST Notify URL:', 'paywithcrypto-woocommerce' ) . '</strong> <code>' . esc_html( $rest_notify ) . '</code></p>';
		echo '<p>' . esc_html__( 'Use the WooCommerce Notify URL as callback_url in PWC. Return URLs are generated per order and validate the WooCommerce order key.', 'paywithcrypto-woocommerce' ) . '</p></div>';
		$this->render_connection_test_box( $client );
		/**
		 * Allow add-ons to render additional PayWithCrypto settings in the
		 * unified gateway management screen.
		 *
		 * @param WC_Gateway_PayWithCrypto $gateway Gateway instance.
		 */
		do_action( 'pwc_gateway_admin_options_before_settings', $this );
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 * Render backend connection test UI.
	 *
	 * @param PWC_API_Client $client API client.
	 */
	private function render_connection_test_box( PWC_API_Client $client ) {
		$last_result = get_option( 'pwc_last_connection_test', array() );
		$last_result = is_array( $last_result ) ? $last_result : array();
		$endpoint    = $client->get_base_url() . '/api/v1/orders';
		$is_ok       = ! empty( $last_result['ok'] );
		$verdict     = isset( $last_result['verdict'] ) ? sanitize_key( (string) $last_result['verdict'] ) : 'not_tested';
		$message     = isset( $last_result['message'] ) ? sanitize_text_field( (string) $last_result['message'] ) : __( 'No connection test has been run yet.', 'paywithcrypto-woocommerce' );
		$checked_at  = isset( $last_result['checked_at'] ) ? sanitize_text_field( (string) $last_result['checked_at'] ) : '';
		$status_text = $is_ok ? __( 'Connected', 'paywithcrypto-woocommerce' ) : __( 'Not verified', 'paywithcrypto-woocommerce' );

		if ( in_array( $verdict, array( 'auth_failed', 'body_signature_failed', 'route_not_found', 'transport_error', 'missing_credentials' ), true ) ) {
			$status_text = __( 'Failed', 'paywithcrypto-woocommerce' );
		}

		?>
		<div class="notice notice-info inline pwc-connection-test-box" style="padding:12px 14px;">
			<p>
				<strong><?php esc_html_e( 'Connection Test', 'paywithcrypto-woocommerce' ); ?>:</strong>
				<span id="pwc-connection-status" style="font-weight:700;color:<?php echo esc_attr( $is_ok ? '#008a20' : '#996800' ); ?>;"><?php echo esc_html( $status_text ); ?></span>
			</p>
			<p>
				<?php esc_html_e( 'Endpoint:', 'paywithcrypto-woocommerce' ); ?>
				<code id="pwc-connection-endpoint"><?php echo esc_html( $endpoint ); ?></code>
			</p>
			<p id="pwc-connection-message"><?php echo esc_html( $message ); ?></p>
			<p id="pwc-connection-details" <?php echo empty( $last_result ) ? 'style="display:none;"' : ''; ?>>
				<?php esc_html_e( 'Last result:', 'paywithcrypto-woocommerce' ); ?>
				<span data-pwc-test-field="verdict"><?php echo esc_html( $verdict ); ?></span>,
				HTTP <span data-pwc-test-field="http_code"><?php echo esc_html( isset( $last_result['http_code'] ) ? (string) absint( $last_result['http_code'] ) : '0' ); ?></span>,
				<?php esc_html_e( 'AppKey', 'paywithcrypto-woocommerce' ); ?>
				<span data-pwc-test-field="app_key"><?php echo esc_html( isset( $last_result['app_key'] ) ? (string) $last_result['app_key'] : '' ); ?></span>,
				<?php esc_html_e( 'checked', 'paywithcrypto-woocommerce' ); ?>
				<span data-pwc-test-field="checked_at"><?php echo esc_html( $checked_at ); ?></span>
			</p>
			<p>
				<button type="button" class="button" id="pwc-test-connection">
					<?php esc_html_e( 'Test PayWithCrypto Connection', 'paywithcrypto-woocommerce' ); ?>
				</button>
				<span class="spinner" id="pwc-test-connection-spinner" style="float:none;margin-top:0;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'This runs from your server. It signs a deliberately invalid create-order probe so PWC can authenticate the request without creating a real payment order.', 'paywithcrypto-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Custom secret field that never renders the stored secret value.
	 *
	 * @param string              $key Field key.
	 * @param array<string,mixed> $data Field data.
	 * @return string
	 */
	public function generate_secret_html( $key, $data ) {
		$field_key   = $this->get_field_key( $key );
		$defaults    = array(
			'title'       => '',
			'disabled'    => false,
			'class'       => '',
			'css'         => '',
			'placeholder' => '',
			'type'        => 'password',
			'desc_tip'    => false,
			'description' => '',
		);
		$data        = wp_parse_args( $data, $defaults );
		$has_secret  = '' !== $this->get_option( $key, '' );
		$placeholder = $has_secret ? __( 'Secret is stored. Enter a new secret to replace it.', 'paywithcrypto-woocommerce' ) : __( 'Enter API secret', 'paywithcrypto-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" style="<?php echo esc_attr( $data['css'] ); ?>" <?php disabled( $data['disabled'], true ); ?> />
					<?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate AppKey field.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_app_key_field( $key, $value ) {
		$app_key = sanitize_text_field( (string) $value );
		if ( false !== strpos( $app_key, '.' ) ) {
			$app_key = strtok( $app_key, '.' );
		}

		return trim( (string) $app_key );
	}

	/**
	 * Validate secret field while preserving old value when blank.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_secret_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $this->get_option( $key, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Validate environment.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_environment_field( $key, $value ) {
		$value = sanitize_key( (string) $value );

		return in_array( $value, array( 'production', 'sandbox' ), true ) ? $value : 'production';
	}

	/**
	 * Validate chain.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_chain_field( $key, $value ) {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		return in_array( $value, array( 'BSC', 'ETH' ), true ) ? $value : 'BSC';
	}

	/**
	 * Validate network.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_network_field( $key, $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );

		return in_array( $value, array( 'mainnet', 'test', 'sepolia' ), true ) ? $value : 'mainnet';
	}

	/**
	 * Validate crypto token.
	 *
	 * @param string $key Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_crypto_field( $key, $value ) {
		$value = strtoupper( sanitize_text_field( (string) $value ) );

		return in_array( $value, array( 'USDT-ERC20', 'PWCUSD-ERC20', 'TEST-ERC20' ), true ) ? $value : 'USDT-ERC20';
	}

}
