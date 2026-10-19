<?php

ss_test(
	'plugin: the package registers its activation hook and plugins_loaded bootstrap',
	function () {
		ss_ok( has_action( 'plugins_loaded', array( 'StormSale_Plugin', 'instance' ) ) !== false );
		ss_same( 1, count( StormSale_Test_Env::$activation_hooks ) );
		ss_same( array( 'StormSale_Plugin', 'activate' ), StormSale_Test_Env::$activation_hooks[0]['callback'] );
	}
);

ss_test(
	'plugin: singleton returns the same booted instance',
	function () {
		$first  = StormSale_Plugin::instance();
		$second = StormSale_Plugin::instance();

		ss_same( $first, $second );
		ss_ok( $first->referral() instanceof StormSale_Referral );
	}
);

ss_test(
	'plugin: the conversion pipeline is wired to the documented hooks',
	function () {
		ss_same( 20, has_action( 'woocommerce_payment_complete', array( 'StormSale_Conversion', 'on_payment_complete' ) ) );
		ss_same( 10, has_action( StormSale_Conversion::ACTION, array( 'StormSale_Conversion', 'process' ) ) );
		ss_same( 10, has_action( 'woocommerce_admin_order_data_after_order_details', array( 'StormSale_Order_Admin', 'render' ) ) );
	}
);

ss_test(
	'plugin: the logger helper is source tagged',
	function () {
		stormsale_log( 'info', 'hello log', array( 'order_id' => 1 ) );

		ss_same( 1, count( StormSale_Test_Env::$logs ) );
		ss_same( 'info', StormSale_Test_Env::$logs[0]['level'] );
		ss_same( 'stormsale-affiliate', StormSale_Test_Env::$logs[0]['context']['source'] );
		ss_same( 1, StormSale_Test_Env::$logs[0]['context']['order_id'] );
	}
);

ss_test(
	'admin: the order panel shows an unconverted order as not sent',
	function () {
		$order = ss_order( 30 );

		ob_start();
		( new StormSale_Order_Admin() )->render( $order );
		$html = ob_get_clean();

		ss_contains( $html, 'StormSale conversion' );
		ss_contains( $html, 'not_sent' );
		ss_contains( $html, 'none', 'orders without a referral show none' );
		ss_not_contains( $html, 'Ledger transaction' );
	}
);

ss_test(
	'admin: the order panel surfaces attribution, status and the ledger transaction',
	function () {
		$order = ss_order( 31 );
		$order->meta[ StormSale_Referral::META ]                   = 'aff9';
		$order->meta[ StormSale_Referral::META_SOURCE ]            = 'session';
		$order->meta[ StormSale_Conversion::META_STATUS ]          = 'verified';
		$order->meta[ StormSale_Conversion::META_TX ]              = 'deadbeefcafe';
		$order->meta[ StormSale_Conversion::META_ATTEMPTS ]        = 1;

		ob_start();
		( new StormSale_Order_Admin() )->render( $order );
		$html = ob_get_clean();

		ss_contains( $html, 'aff9' );
		ss_contains( $html, 'verified' );
		ss_contains( $html, 'deadbeefcafe' );
		ss_not_contains( $html, 'Last error' );
	}
);

ss_test(
	'admin: failed conversions show the recorded error',
	function () {
		$order = ss_order( 32 );
		$order->meta[ StormSale_Conversion::META_STATUS ] = 'failed';
		$order->meta[ StormSale_Conversion::META_ERROR ]  = 'http_500';

		ob_start();
		( new StormSale_Order_Admin() )->render( $order );
		$html = ob_get_clean();

		ss_contains( $html, 'http_500' );
	}
);

ss_test(
	'uninstall: removing the plugin deletes its data',
	function () {
		ss_configure();
		StormSale_Stats::record_click( 'aff9' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'stormsale-affiliate/stormsale-affiliate.php' );
		}

		include dirname( __DIR__ ) . '/uninstall.php';

		ss_same( false, get_option( StormSale_Settings::OPTION ) );
		ss_same( false, get_option( StormSale_Stats::OPTION ) );
	}
);
