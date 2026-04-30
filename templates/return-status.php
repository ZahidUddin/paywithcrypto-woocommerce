<?php
/**
 * PayWithCrypto return status template.
 *
 * @package PayWithCrypto_WooCommerce
 *
 * @var WC_Order            $order
 * @var array<string,mixed> $status_data
 * @var string              $sync_error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status = isset( $status_data['status'] ) ? PWC_Order_Sync::normalize_status( $status_data['status'] ) : 'PENDING';
$title  = __( 'Payment status', 'paywithcrypto-woocommerce' );
$body   = __( 'Your crypto payment is awaiting payment or confirmation.', 'paywithcrypto-woocommerce' );

if ( 'PAID' === $status ) {
	$title = __( 'Thank you, payment received.', 'paywithcrypto-woocommerce' );
	$body  = __( 'PayWithCrypto has confirmed your payment. Your order has been updated.', 'paywithcrypto-woocommerce' );
} elseif ( 'FAILED' === $status ) {
	$title = __( 'Payment failed.', 'paywithcrypto-woocommerce' );
	$body  = __( 'PayWithCrypto reported this payment as failed.', 'paywithcrypto-woocommerce' );
} elseif ( 'EXPIRED' === $status ) {
	$title = __( 'Payment expired.', 'paywithcrypto-woocommerce' );
	$body  = __( 'The payment window has expired. Contact the store if you already sent funds.', 'paywithcrypto-woocommerce' );
} elseif ( 'PARTIALLY_PAID' === $status ) {
	$title = __( 'Partial payment received.', 'paywithcrypto-woocommerce' );
	$body  = __( 'PayWithCrypto detected a partial payment. Please review the remaining amount.', 'paywithcrypto-woocommerce' );
}
?>
<main class="pwc-payment-page pwc-return-page" aria-labelledby="pwc-return-title">
	<section class="pwc-shell pwc-return-shell">
		<section class="pwc-card pwc-return-card">
			<span class="pwc-status-badge pwc-status-<?php echo esc_attr( strtolower( $status ) ); ?>">
				<span class="pwc-status-dot" aria-hidden="true"></span>
				<?php echo esc_html( $status_data['status_label'] ); ?>
			</span>

			<h1 id="pwc-return-title"><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $body ); ?></p>

			<?php if ( ! empty( $status_data['payment_status_message'] ) ) : ?>
				<div class="pwc-message"><?php echo wp_kses_post( $status_data['payment_status_message'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $sync_error ) ) : ?>
				<div class="pwc-message pwc-message-warning"><?php echo esc_html( $sync_error ); ?></div>
			<?php endif; ?>

			<div class="pwc-detail-list pwc-return-details">
				<div>
					<span class="pwc-label"><?php esc_html_e( 'Order', 'paywithcrypto-woocommerce' ); ?></span>
					<strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
				</div>
				<div>
					<span class="pwc-label"><?php esc_html_e( 'Amount', 'paywithcrypto-woocommerce' ); ?></span>
					<strong><?php echo esc_html( trim( $status_data['amount_crypto'] . ' ' . $status_data['crypto'] ) ); ?></strong>
				</div>
				<div>
					<span class="pwc-label"><?php esc_html_e( 'Confirmed', 'paywithcrypto-woocommerce' ); ?></span>
					<strong><?php echo esc_html( $status_data['confirmed_amount'] ); ?></strong>
				</div>
				<div>
					<span class="pwc-label"><?php esc_html_e( 'Remaining', 'paywithcrypto-woocommerce' ); ?></span>
					<strong><?php echo esc_html( $status_data['remaining_amount'] ); ?></strong>
				</div>
			</div>

			<?php if ( ! empty( $status_data['tx_hash'] ) ) : ?>
				<p class="pwc-tx">
					<span class="pwc-label"><?php esc_html_e( 'Transaction', 'paywithcrypto-woocommerce' ); ?></span>
					<?php if ( ! empty( $status_data['transaction_url'] ) ) : ?>
						<a href="<?php echo esc_url( $status_data['transaction_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $status_data['tx_hash'] ); ?></a>
					<?php else : ?>
						<strong><?php echo esc_html( $status_data['tx_hash'] ); ?></strong>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<div class="pwc-actions">
				<a class="pwc-button pwc-button-primary" href="<?php echo esc_url( $order->get_view_order_url() ); ?>"><?php esc_html_e( 'View order details', 'paywithcrypto-woocommerce' ); ?></a>
				<?php if ( ! PWC_Order_Sync::is_terminal_status( $status ) ) : ?>
					<a class="pwc-button" href="<?php echo esc_url( PWC_Payment_Page::get_payment_page_url( $order ) ); ?>"><?php esc_html_e( 'Back to payment page', 'paywithcrypto-woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
	</section>
</main>
