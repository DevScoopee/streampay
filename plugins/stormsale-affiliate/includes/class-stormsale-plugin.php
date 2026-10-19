<?php

final class StormSale_Plugin {

	private static $instance = null;

	private $referral = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	public static function activate() {
		add_option( StormSale_Settings::OPTION, StormSale_Settings::defaults() );
	}

	private function boot() {
		load_plugin_textdomain(
			'stormsale-affiliate',
			false,
			dirname( plugin_basename( STORMSALE_PLUGIN_FILE ) ) . '/languages'
		);

		$settings = new StormSale_Settings();
		$settings->register();

		$this->referral = new StormSale_Referral();
		$this->referral->register();

		$order_admin = new StormSale_Order_Admin();
		$order_admin->register();

		add_action( 'woocommerce_payment_complete', array( 'StormSale_Conversion', 'on_payment_complete' ), 20, 1 );
		add_action( StormSale_Conversion::ACTION, array( 'StormSale_Conversion', 'process' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function referral() {
		return $this->referral;
	}

	public function enqueue_assets() {
		$handle = 'stormsale-referral';

		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/js/stormsale-referral.js', STORMSALE_PLUGIN_FILE ),
			array(),
			STORMSALE_VERSION,
			true
		);

		$config = array(
			'cookieName' => StormSale_Referral::COOKIE,
			'ttlDays'    => StormSale_Referral::TTL_DAYS,
			'params'     => StormSale_Referral::QUERY_PARAMS,
			'secure'     => is_ssl(),
		);

		wp_add_inline_script(
			$handle,
			'window.stormsaleReferral = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
