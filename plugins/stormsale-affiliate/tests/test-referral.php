<?php

ss_test(
	'referral: cookie lifecycle hooks span the cart and checkout',
	function () {
		ss_same( 5, has_action( 'init', array( 'StormSale_Referral', 'capture' ) ) );
		ss_same( 6, has_action( 'init', array( 'StormSale_Referral', 'refresh_cookie' ) ) );
		ss_same( 10, has_action( 'wp', array( 'StormSale_Referral', 'sync_session' ) ) );
		ss_same( 10, has_action( 'woocommerce_before_checkout', array( 'StormSale_Referral', 'sync_session' ) ) );
		ss_same( 20, has_action( 'woocommerce_checkout_create_order', array( 'StormSale_Referral', 'attach_to_order' ) ) );
		ss_same( 20, has_action( 'woocommerce_store_api_checkout_update_order_from_request', array( 'StormSale_Referral', 'attach_to_order' ) ) );
	}
);

ss_test(
	'referral: codes are strictly validated',
	function () {
		ss_same( 'aff-42_ok.1', StormSale_Referral::sanitize_code( 'aff-42_ok.1' ) );
		ss_same( 'a', StormSale_Referral::sanitize_code( 'a' ) );
		ss_same( str_repeat( 'b', 64 ), StormSale_Referral::sanitize_code( str_repeat( 'b', 64 ) ) );

		ss_same( null, StormSale_Referral::sanitize_code( '' ) );
		ss_same( null, StormSale_Referral::sanitize_code( '   ' ) );
		ss_same( 'leading', StormSale_Referral::sanitize_code( ' leading ' ), 'surrounding whitespace is trimmed' );
		ss_same( null, StormSale_Referral::sanitize_code( 'has space' ) );
		ss_same( null, StormSale_Referral::sanitize_code( 'script>alert(1)' ) );
		ss_same( null, StormSale_Referral::sanitize_code( 'affä' ) );
		ss_same( null, StormSale_Referral::sanitize_code( str_repeat( 'b', 65 ) ) );
		ss_same( null, StormSale_Referral::sanitize_code( array( 'aff1' ) ) );
		ss_same( null, StormSale_Referral::sanitize_code( null ) );
	}
);

ss_test(
	'referral: the ss_ref query parameter wins and invalid values are ignored',
	function () {
		ss_same( 'aff9', StormSale_Referral::code_from_request( array( 'ss_ref' => 'aff9', 'ref' => 'other' ) ) );
		ss_same( 'other', StormSale_Referral::code_from_request( array( 'ss_ref' => 'bad code', 'ref' => 'other' ) ) );
		ss_same( null, StormSale_Referral::code_from_request( array( 'ss_ref' => '<x>' ) ) );
		ss_same( null, StormSale_Referral::code_from_request( array() ) );
		ss_same( null, StormSale_Referral::code_from_request( 'not-an-array' ) );
		ss_same( null, StormSale_Referral::code_from_request( array( 'ss_ref' => array( 'nested' ) ) ) );
	}
);

ss_test(
	'referral: cookie parameters are secure, scoped and expire in 30 days',
	function () {
		$now  = 1700000000;
		$opts = StormSale_Referral::cookie_params( 'aff9', $now );

		ss_same( StormSale_Referral::COOKIE, $opts['name'] );
		ss_same( 'aff9', $opts['value'] );
		ss_same( $now + ( 30 * 86400 ), $opts['expires'] );
		ss_same( '/', $opts['path'] );
		ss_same( true, $opts['httponly'] );
		ss_same( 'Lax', $opts['samesite'] );
		ss_same( false, $opts['secure'], 'secure mirrors is_ssl()' );

		StormSale_Test_Env::$ssl = true;

		ss_same( true, StormSale_Referral::cookie_params( 'aff9', $now )['secure'] );
	}
);

ss_test(
	'referral: a landing click sets the cookie, the session and the click counter',
	function () {
		$_GET = array( 'ss_ref' => 'aff9' );

		ss_same( 'aff9', StormSale_Referral::capture() );
		ss_same( 'aff9', StormSale_Referral::session_code() );

		$stats = StormSale_Stats::load();

		ss_same( 1, $stats['total'] );
		ss_same( 1, $stats['codes']['aff9'] );
		ss_same( 'aff9', $stats['last_code'] );
	}
);

ss_test(
	'referral: returning to the landing page does not inflate the click count',
	function () {
		$_GET = array( 'ss_ref' => 'aff9' );

		StormSale_Referral::capture();

		$_COOKIE[ StormSale_Referral::COOKIE ] = 'aff9';

		StormSale_Referral::capture();

		ss_same( 1, StormSale_Stats::load()['total'] );
	}
);

ss_test(
	'referral: a competing affiliate click is counted again',
	function () {
		$_GET = array( 'ss_ref' => 'aff9' );
		StormSale_Referral::capture();

		$_COOKIE[ StormSale_Referral::COOKIE ] = 'aff9';
		$_GET                                 = array( 'ss_ref' => 'aff7' );
		StormSale_Referral::capture();

		$stats = StormSale_Stats::load();

		ss_same( 2, $stats['total'] );
		ss_same( 1, $stats['codes']['aff9'] );
		ss_same( 1, $stats['codes']['aff7'] );
		ss_same( 'aff7', $stats['last_code'] );
	}
);

ss_test(
	'referral: pages without referral parameters are ignored',
	function () {
		$_GET = array( 'utm_source' => 'newsletter' );

		ss_same( null, StormSale_Referral::capture() );
		ss_same( 0, StormSale_Stats::load()['total'] );
		ss_same( null, StormSale_Referral::session_code() );
	}
);

ss_test(
	'referral: the cookie survives a refresh with a sliding expiry',
	function () {
		$_COOKIE[ StormSale_Referral::COOKIE ] = 'aff9';

		ss_same( 'aff9', StormSale_Referral::current_code() );
		ss_same( 'aff9', StormSale_Referral::sync_session() );
		StormSale_Referral::refresh_cookie();
		ss_same( 'aff9', StormSale_Referral::current_code(), 'a valid cookie survives the sliding refresh' );

		$_COOKIE[ StormSale_Referral::COOKIE ] = 'bad code';

		ss_same( null, StormSale_Referral::current_code() );
		ss_same( false, StormSale_Referral::refresh_cookie() );
	}
);

ss_test(
	'referral: attribution is attached to the order once and from the session first',
	function () {
		WC()->session->set( StormSale_Referral::SESSION_KEY, 'aff9' );
		$_COOKIE[ StormSale_Referral::COOKIE ] = 'other';

		$order = ss_order( 11 );

		ss_ok( StormSale_Referral::attach_to_order( $order ) );
		ss_same( 'aff9', $order->meta[ StormSale_Referral::META ] );
		ss_same( 'session', $order->meta[ StormSale_Referral::META_SOURCE ] );
		ss_ok( isset( $order->meta[ StormSale_Referral::META_AT ] ) );
		ss_same( 1, $order->saves );

		ss_not_ok( StormSale_Referral::attach_to_order( $order ), 'existing attribution is never overwritten' );
		ss_same( 1, $order->saves );
	}
);

ss_test(
	'referral: attribution falls back to the cookie when the session is gone',
	function () {
		$_COOKIE[ StormSale_Referral::COOKIE ] = 'aff3';

		$order = ss_order( 12 );

		ss_ok( StormSale_Referral::attach_to_order( $order ) );
		ss_same( 'aff3', $order->meta[ StormSale_Referral::META ] );
		ss_same( 'cookie', $order->meta[ StormSale_Referral::META_SOURCE ] );
	}
);

ss_test(
	'referral: orders with no live signal stay unattributed',
	function () {
		$order = ss_order( 13 );

		ss_not_ok( StormSale_Referral::attach_to_order( $order ) );
		ss_same( '', $order->get_meta( StormSale_Referral::META ) );
		ss_same( 0, $order->saves );
	}
);

ss_test(
	'referral: referral_for_order prefers order meta, then session, then cookie',
	function () {
		$order = ss_order( 14 );
		$order->meta[ StormSale_Referral::META ]        = 'aff5';
		$order->meta[ StormSale_Referral::META_SOURCE ] = 'session';
		$order->meta[ StormSale_Referral::META_AT ]     = '2026-10-01T10:00:00+00:00';

		ss_same(
			array(
				'code'   => 'aff5',
				'source' => 'session',
				'at'     => '2026-10-01T10:00:00+00:00',
			),
			StormSale_Referral::referral_for_order( $order )
		);

		$fresh = ss_order( 15 );

		ss_same(
			array(
				'code'   => null,
				'source' => 'none',
				'at'     => null,
			),
			StormSale_Referral::referral_for_order( $fresh )
		);

		WC()->session->set( StormSale_Referral::SESSION_KEY, 'aff6' );

		ss_same(
			array(
				'code'   => 'aff6',
				'source' => 'session',
				'at'     => null,
			),
			StormSale_Referral::referral_for_order( $fresh )
		);
	}
);

ss_test(
	'referral: the click counter stays bounded',
	function () {
		for ( $i = 0; $i < 505; $i++ ) {
			StormSale_Stats::record_click( 'aff' . $i );
		}

		$stats = StormSale_Stats::load();

		ss_same( 505, $stats['total'] );
		ss_same( StormSale_Stats::MAX_CODES, count( $stats['codes'] ) );
		ss_ok( isset( $stats['codes']['aff504'] ), 'newest codes are retained' );
	}
);

ss_test(
	'referral: plugin boots the referral JS with the shared cookie configuration',
	function () {
		do_action( 'wp_enqueue_scripts' );

		ss_same( 1, count( StormSale_Test_Env::$scripts ) );

		$script = StormSale_Test_Env::$scripts[0];

		ss_same( 'stormsale-referral', $script['handle'] );
		ss_contains( $script['src'], 'assets/js/stormsale-referral.js' );
		ss_same( true, $script['in_footer'] );
		ss_same( STORMSALE_VERSION, $script['ver'] );

		ss_same( 1, count( StormSale_Test_Env::$inline_scripts ) );

		$inline = StormSale_Test_Env::$inline_scripts[0];

		ss_same( 'stormsale-referral', $inline['handle'] );
		ss_same( 'before', $inline['position'] );
		ss_contains( $inline['data'], 'stormsale_ref' );
		ss_contains( $inline['data'], 'ss_ref' );
	}
);
