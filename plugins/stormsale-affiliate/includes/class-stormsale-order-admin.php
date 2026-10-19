<?php

final class StormSale_Order_Admin {

	public function register() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render' ), 10, 1 );
	}

	public function render( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}

		$referral = StormSale_Referral::referral_for_order( $order );
		$status   = (string) $order->get_meta( StormSale_Conversion::META_STATUS );
		$error    = (string) $order->get_meta( StormSale_Conversion::META_ERROR );
		$tx       = (string) $order->get_meta( StormSale_Conversion::META_TX );
		$attempts = (int) $order->get_meta( StormSale_Conversion::META_ATTEMPTS );

		if ( '' === $status ) {
			$status = 'not_sent';
		}
		?>
		<div class="stormsale-order-panel">
			<h4><?php echo esc_html( __( 'StormSale conversion', 'stormsale-affiliate' ) ); ?></h4>
			<p>
				<strong><?php echo esc_html( __( 'Campaign:', 'stormsale-affiliate' ) ); ?></strong>
				<?php echo esc_html( StormSale_Settings::get( 'campaign_id' ) ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( __( 'Referral code:', 'stormsale-affiliate' ) ); ?></strong>
				<?php echo esc_html( null === $referral['code'] ? __( 'none', 'stormsale-affiliate' ) : $referral['code'] ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( __( 'Conversion status:', 'stormsale-affiliate' ) ); ?></strong>
				<?php echo esc_html( $status ); ?>
			</p>
			<p>
				<strong><?php echo esc_html( __( 'Attempts:', 'stormsale-affiliate' ) ); ?></strong>
				<?php echo esc_html( (string) $attempts ); ?>
			</p>
			<?php if ( '' !== $tx ) : ?>
				<p>
					<strong><?php echo esc_html( __( 'Ledger transaction:', 'stormsale-affiliate' ) ); ?></strong>
					<code><?php echo esc_html( $tx ); ?></code>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $error && 'verified' !== $status ) : ?>
				<p>
					<strong><?php echo esc_html( __( 'Last error:', 'stormsale-affiliate' ) ); ?></strong>
					<?php echo esc_html( $error ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
