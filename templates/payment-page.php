<?php
/**
 * PayWithCrypto payment page template.
 *
 * @package PayWithCrypto_WooCommerce
 *
 * @var WC_Order            $order
 * @var array<string,mixed> $status_data
 * @var string              $return_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status      = isset( $status_data['status'] ) ? sanitize_html_class( strtolower( (string) $status_data['status'] ) ) : 'pending';
$show_expiry = 'yes' === pwc_get_gateway_setting( 'show_expiration', 'yes' );
$fiat        = isset( $status_data['fiat_currency'] ) ? sanitize_text_field( (string) $status_data['fiat_currency'] ) : $order->get_currency();
?>
<main class="pwc-payment-page" aria-labelledby="pwc-payment-title">
	<section class="pwc-shell">
		<div class="pwc-hero">
			<p class="pwc-kicker"><?php esc_html_e( 'Crypto wallet transfer', 'paywithcrypto-woocommerce' ); ?></p>
			<h1 id="pwc-payment-title"><?php esc_html_e( 'Complete your PayWithCrypto payment', 'paywithcrypto-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Scan the QR code with your wallet app, or copy the wallet address and manually transfer the exact amount.', 'paywithcrypto-woocommerce' ); ?></p>
		</div>

		<div class="pwc-grid">
			<section class="pwc-card pwc-status-card" aria-live="polite">
				<div class="pwc-status-row">
					<span class="pwc-status-badge pwc-status-<?php echo esc_attr( $status ); ?>" data-pwc-status-badge>
						<span class="pwc-status-dot" aria-hidden="true"></span>
						<span data-pwc-field="status_label"><?php echo esc_html( $status_data['status_label'] ); ?></span>
					</span>
					<span class="pwc-order-number"><?php echo esc_html( sprintf( __( 'Order #%s', 'paywithcrypto-woocommerce' ), $order->get_order_number() ) ); ?></span>
				</div>

				<div class="pwc-amount-block">
					<span class="pwc-label"><?php esc_html_e( 'Amount due', 'paywithcrypto-woocommerce' ); ?></span>
					<strong><span data-pwc-field="amount_crypto"><?php echo esc_html( $status_data['amount_crypto'] ); ?></span> <span data-pwc-field="crypto"><?php echo esc_html( $status_data['crypto'] ); ?></span></strong>
					<span class="pwc-muted"><span data-pwc-field="amount_fiat"><?php echo esc_html( $status_data['amount_fiat'] ); ?></span> <span data-pwc-field="fiat_currency"><?php echo esc_html( $fiat ? $fiat : 'USD' ); ?></span></span>
				</div>

				<div class="pwc-detail-list">
					<div>
						<span class="pwc-label"><?php esc_html_e( 'Chain / Network', 'paywithcrypto-woocommerce' ); ?></span>
						<strong><span data-pwc-field="chain"><?php echo esc_html( $status_data['chain'] ); ?></span> / <span data-pwc-field="network"><?php echo esc_html( $status_data['network'] ); ?></span></strong>
					</div>
					<div>
						<span class="pwc-label"><?php esc_html_e( 'Confirmed', 'paywithcrypto-woocommerce' ); ?></span>
						<strong><span data-pwc-field="confirmed_amount"><?php echo esc_html( $status_data['confirmed_amount'] ); ?></span></strong>
					</div>
					<div>
						<span class="pwc-label"><?php esc_html_e( 'Remaining', 'paywithcrypto-woocommerce' ); ?></span>
						<strong><span data-pwc-field="remaining_amount"><?php echo esc_html( $status_data['remaining_amount'] ); ?></span></strong>
					</div>
					<?php if ( $show_expiry ) : ?>
						<div>
							<span class="pwc-label"><?php esc_html_e( 'Expires', 'paywithcrypto-woocommerce' ); ?></span>
							<strong data-pwc-field="expires_at" data-pwc-expires-at="<?php echo esc_attr( $status_data['expires_at'] ); ?>"><?php echo esc_html( $status_data['expires_at'] ); ?></strong>
						</div>
					<?php endif; ?>
				</div>

				<div class="pwc-message" data-pwc-message <?php echo empty( $status_data['payment_status_message'] ) ? 'hidden' : ''; ?>>
					<?php echo wp_kses_post( $status_data['payment_status_message'] ); ?>
				</div>

				<div class="pwc-tx" data-pwc-transaction <?php echo empty( $status_data['tx_hash'] ) ? 'hidden' : ''; ?>>
					<span class="pwc-label"><?php esc_html_e( 'Transaction', 'paywithcrypto-woocommerce' ); ?></span>
					<a href="<?php echo esc_url( $status_data['transaction_url'] ); ?>" target="_blank" rel="noopener noreferrer" data-pwc-tx-link>
						<span data-pwc-field="tx_hash"><?php echo esc_html( $status_data['tx_hash'] ); ?></span>
					</a>
				</div>
			</section>

			<section class="pwc-card pwc-pay-card">
				<div class="pwc-qr-wrap">
					<div class="pwc-qr" data-pwc-qr role="img" aria-label="<?php esc_attr_e( 'Payment QR code', 'paywithcrypto-woocommerce' ); ?>"></div>
				</div>

				<div class="pwc-copy-group">
					<label for="pwc-payment-address"><?php esc_html_e( 'Wallet address', 'paywithcrypto-woocommerce' ); ?></label>
					<div class="pwc-copy-row">
						<input id="pwc-payment-address" type="text" readonly value="<?php echo esc_attr( $status_data['payment_address'] ); ?>" data-pwc-field="payment_address" aria-label="<?php esc_attr_e( 'Payment wallet address', 'paywithcrypto-woocommerce' ); ?>">
						<button type="button" class="pwc-button" data-pwc-copy="payment_address"><?php esc_html_e( 'Copy', 'paywithcrypto-woocommerce' ); ?></button>
					</div>
				</div>

				<div class="pwc-copy-group">
					<label for="pwc-payment-amount"><?php esc_html_e( 'Payment amount', 'paywithcrypto-woocommerce' ); ?></label>
					<div class="pwc-copy-row">
						<input id="pwc-payment-amount" type="text" readonly value="<?php echo esc_attr( $status_data['amount_crypto'] ); ?>" data-pwc-field="amount_crypto_input" aria-label="<?php esc_attr_e( 'Payment crypto amount', 'paywithcrypto-woocommerce' ); ?>">
						<button type="button" class="pwc-button" data-pwc-copy="amount_crypto"><?php esc_html_e( 'Copy', 'paywithcrypto-woocommerce' ); ?></button>
					</div>
				</div>

				<ul class="pwc-instructions">
					<li><?php esc_html_e( 'Send the exact amount shown above to the wallet address.', 'paywithcrypto-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Use the selected chain and network only. Wrong-token transfers may not count toward this order.', 'paywithcrypto-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Supported wallets include MetaMask, Trust Wallet, Coinbase Wallet, TokenPocket, imToken, and compatible ERC-20/BEP-20 wallets.', 'paywithcrypto-woocommerce' ); ?></li>
				</ul>

				<a class="pwc-return-link" href="<?php echo esc_url( $return_url ); ?>"><?php esc_html_e( 'View order payment status', 'paywithcrypto-woocommerce' ); ?></a>
			</section>
		</div>
	</section>
</main>
