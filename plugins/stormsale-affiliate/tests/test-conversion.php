<?php

ss_test(
	'conversion: completed orders are hooked to the payment_complete action',
	function () {
		ss_same( 20, has_action( 'woocommerce_payment_complete', array( 'StormSale_Conversion', 'on_payment_complete' ) ) );
		ss_same( 10, has_action( StormSale_Conversion::ACTION, array( 'StormSale_Conversion', 'process' ) ) );
	}
);

ss_test(
	'conversion: unconfigured stores never transmit anything',
	function () {
		$order = ss_order( 1 );
		$order->update_meta_data( '_stormsale_conversion_status', '' );

		StormSale_Conversion::on_payment_complete( 1 );

		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );
		ss_same( '', $order->get_meta( StormSale_Conversion::META_STATUS ) );
		ss_same( 1, count( StormSale_Test_Env::$logs ) );
		ss_same( 'warning', StormSale_Test_Env::$logs[0]['level'] );
	}
);

ss_test(
	'conversion: a completed order produces a verified conversion with a ledger tx',
	function () {
		ss_configure();
		$order = ss_order( 7 );

		StormSale_Test_Env::queue_response(
			200,
			array(
				'status'  => 'verified',
				'tx'      => 'deadbeefcafe',
				'ledger'  => 424242,
			)
		);

		StormSale_Conversion::on_payment_complete( 7 );

		ss_same( 'verified', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 'deadbeefcafe', $order->meta[ StormSale_Conversion::META_TX ] );
		ss_same( 1, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
		ss_same( 32, strlen( $order->meta[ StormSale_Conversion::META_ID ] ) );
		ss_same( '', $order->meta[ StormSale_Conversion::META_ERROR ] );
		ss_ok( isset( $order->meta[ StormSale_Conversion::META_AT ] ), 'verified conversions record a timestamp' );
		ss_same( 1, count( StormSale_Test_Env::$remote_calls ) );
	}
);

ss_test(
	'conversion: the payment_complete hook accepts an order object as well as an ID',
	function () {
		ss_configure();
		$order = ss_order( 8 );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx8' ) );

		StormSale_Conversion::on_payment_complete( $order );

		ss_same( 'verified', $order->meta[ StormSale_Conversion::META_STATUS ] );
	}
);

ss_test(
	'conversion: the same completed order is never transmitted twice',
	function () {
		ss_configure();
		$order = ss_order( 9 );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx9' ) );

		StormSale_Conversion::on_payment_complete( 9 );
		StormSale_Conversion::on_payment_complete( 9 );

		ss_same( 1, count( StormSale_Test_Env::$remote_calls ) );
		ss_same( 1, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
	}
);

ss_test(
	'conversion: in-flight conversions are not re-dispatched',
	function () {
		ss_configure();
		$order = ss_order( 10 );

		$order->update_meta_data( StormSale_Conversion::META_STATUS, 'queued' );

		StormSale_Conversion::on_payment_complete( 10 );

		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );
		ss_same( 0, $order->saves );
	}
);

ss_test(
	'conversion: referral attribution from the checkout session lands in the order meta and the payload',
	function () {
		ss_configure();
		$order = ss_order( 11 );

		WC()->session->set( StormSale_Referral::SESSION_KEY, 'aff9' );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx11' ) );

		StormSale_Conversion::on_payment_complete( 11 );

		ss_same( 'aff9', $order->meta[ StormSale_Referral::META ] );
		ss_same( 'session', $order->meta[ StormSale_Referral::META_SOURCE ] );

		$body = json_decode( ss_last_request()['args']['body'], true );
		$payload = json_decode(
			StormSale_Crypto::decrypt( $body['payload'], StormSale_Settings::get( 'api_key' ), 'campaign:cmp_winter25' ),
			true
		);

		ss_same( 'aff9', $payload['referral']['code'] );
		ss_same( 'session', $payload['referral']['source'] );
	}
);

ss_test(
	'conversion: the encrypted payload describes the purchase without leaking personal data',
	function () {
		ss_configure();
		$order = ss_order( 12 );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx12' ) );

		StormSale_Conversion::on_payment_complete( 12 );

		$request = ss_last_request();
		$body    = json_decode( $request['args']['body'], true );
		$plain   = StormSale_Crypto::decrypt(
			$body['payload'],
			StormSale_Settings::get( 'api_key' ),
			'campaign:' . StormSale_Settings::get( 'campaign_id' )
		);

		ss_not_contains( $request['args']['body'], 'buyer@example.com', 'email is not transmitted in clear text' );

		$payload = json_decode( $plain, true );

		ss_same( 1, $payload['version'] );
		ss_same( 'cmp_winter25', $payload['campaign_id'] );
		ss_same( $order->meta[ StormSale_Conversion::META_ID ], $payload['conversion_id'] );

		ss_same( 12, $payload['order']['id'] );
		ss_same( '12', $payload['order']['number'] );
		ss_same( 'processing', $payload['order']['status'] );
		ss_same( 'USD', $payload['order']['currency'] );
		ss_same( '19.99', $payload['order']['total'] );
		ss_same( 1999, $payload['order']['total_minor'] );
		ss_same( 2, $payload['order']['decimals'] );
		ss_same( 3, $payload['order']['item_count'] );
		ss_same( 'cod', $payload['order']['payment_method'] );
		ss_same( '2026-10-01T10:00:00+00:00', $payload['order']['created_at'] );

		ss_same( hash( 'sha256', 'buyer@example.com' ), $payload['customer']['email_sha256'] );
		ss_same( home_url( '/' ), $payload['store']['url'] );
		ss_same( STORMSALE_VERSION, $payload['store']['plugin_version'] );

		ss_ok( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $payload['occurred_at'] ) === 1, 'occurred_at is ISO 8601' );
		ss_ok( preg_match( '/^[0-9a-f]{16}$/', $payload['nonce'] ) === 1, 'nonce is 8 random bytes' );
	}
);

ss_test(
	'conversion: minor units respect the store currency settings',
	function () {
		ss_configure();
		StormSale_Test_Env::$price_decimals = 0;
		$order                               = ss_order( 13, array( 'total' => '250' ) );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx13' ) );

		StormSale_Conversion::on_payment_complete( 13 );

		$body    = json_decode( ss_last_request()['args']['body'], true );
		$payload = json_decode(
			StormSale_Crypto::decrypt( $body['payload'], StormSale_Settings::get( 'api_key' ), 'campaign:cmp_winter25' ),
			true
		);

		ss_same( 250, $payload['order']['total_minor'] );
		ss_same( 0, $payload['order']['decimals'] );
	}
);

ss_test(
	'conversion: the conversion id is deterministic so retries stay idempotent',
	function () {
		ss_configure();
		$order = ss_order( 14 );

		$first  = StormSale_Conversion::conversion_id( $order );
		$second = StormSale_Conversion::conversion_id( $order );

		ss_same( 32, strlen( $first ) );
		ss_same( $first, $second );

		$other = ss_order( 15 );
		ss_not_ok( $first === StormSale_Conversion::conversion_id( $other ), 'ids differ per order' );

		$other_campaign = StormSale_Conversion::conversion_id( $order );
		ss_configure( 'cmp_other', 'sk_live_stormsale_abc123' );
		ss_not_ok( $other_campaign === StormSale_Conversion::conversion_id( $order ), 'ids differ per campaign' );
	}
);

ss_test(
	'conversion: gateway failures are recorded on the order and logged',
	function () {
		ss_configure();
		$order = ss_order( 16 );

		StormSale_Test_Env::queue_response( 500, array( 'message' => 'gateway exploded' ) );

		StormSale_Conversion::on_payment_complete( 16 );

		ss_same( 'failed', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 'gateway exploded', $order->meta[ StormSale_Conversion::META_ERROR ] );
		ss_same( 1, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
		ss_same( 'error', StormSale_Test_Env::$logs[ count( StormSale_Test_Env::$logs ) - 1 ]['level'] );
	}
);

ss_test(
	'conversion: transient gateway failures schedule an asynchronous retry',
	function () {
		ss_configure();
		StormSale_Test_Env::$async_queue = true;
		$order                            = ss_order( 17 );

		StormSale_Test_Env::queue_response( 503, array( 'message' => 'try later' ) );

		StormSale_Conversion::process( 17 );

		ss_same( 1, count( StormSale_Test_Env::$scheduled_actions ) );

		$retry = StormSale_Test_Env::$scheduled_actions[0];

		ss_same( StormSale_Conversion::ACTION, $retry['hook'] );
		ss_same( array( 17 ), $retry['args'] );
		ss_same( 'stormsale', $retry['group'] );
		ss_ok( $retry['timestamp'] > time(), 'retry is scheduled in the future' );
	}
);

ss_test(
	'conversion: permanent gateway rejections are not retried',
	function () {
		ss_configure();
		StormSale_Test_Env::$async_queue = true;

		$order = ss_order( 18 );

		StormSale_Test_Env::queue_response( 401, array( 'message' => 'bad key' ) );

		StormSale_Conversion::process( 18 );

		ss_same( 'failed', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 0, count( StormSale_Test_Env::$scheduled_actions ) );
	}
);

ss_test(
	'conversion: transport failures are retried but retries never duplicate',
	function () {
		ss_configure();
		StormSale_Test_Env::$async_queue = true;

		$order = ss_order( 19 );

		StormSale_Conversion::process( 19 );

		ss_same( 'failed', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_contains( $order->meta[ StormSale_Conversion::META_ERROR ], 'no response queued' );

		ss_same( 1, count( StormSale_Test_Env::$scheduled_actions ) );

		ss_not_ok( StormSale_Conversion::schedule_retry( 19, 1 ), 'an already scheduled retry is not duplicated' );
		ss_same( 1, count( StormSale_Test_Env::$scheduled_actions ) );
	}
);

ss_test(
	'conversion: pending ledger confirmations are polled until verified',
	function () {
		ss_configure();
		StormSale_Test_Env::$async_queue = true;
		$order                            = ss_order( 20 );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'pending' ) );

		StormSale_Conversion::process( 20 );

		ss_same( 'pending', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 1, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
		ss_same( 1, count( StormSale_Test_Env::$scheduled_actions ), 'a verification poll is scheduled' );

		StormSale_Test_Env::$remote_queue = array();
		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx20' ) );

		StormSale_Conversion::process( 20 );

		ss_same( 'verified', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 'tx20', $order->meta[ StormSale_Conversion::META_TX ] );
		ss_same( 2, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
	}
);

ss_test(
	'conversion: a verified conversion is never submitted again',
	function () {
		ss_configure();
		$order = ss_order( 21 );

		$order->update_meta_data( StormSale_Conversion::META_STATUS, 'verified' );
		$order->update_meta_data( StormSale_Conversion::META_ATTEMPTS, 1 );

		$result = StormSale_Conversion::process( 21 );

		ss_same( true, $result['ok'] );
		ss_same( 'verified', $result['status'] );
		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );
		ss_same( 1, $order->meta[ StormSale_Conversion::META_ATTEMPTS ] );
	}
);

ss_test(
	'conversion: the attempt budget is enforced',
	function () {
		ss_configure();
		$order = ss_order( 22 );

		$order->update_meta_data( StormSale_Conversion::META_ATTEMPTS, StormSale_Conversion::MAX_ATTEMPTS );

		$result = StormSale_Conversion::process( 22 );

		ss_same( false, $result['ok'] );
		ss_same( 'max_attempts_exceeded', $result['error'] );
		ss_same( 'failed', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 'max_attempts_exceeded', $order->meta[ StormSale_Conversion::META_ERROR ] );
		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );
	}
);

ss_test(
	'conversion: Action Scheduler is used when available so checkout stays fast',
	function () {
		ss_configure();
		StormSale_Test_Env::$async_queue = true;
		$order                            = ss_order( 23 );

		StormSale_Conversion::on_payment_complete( 23 );

		ss_same( 0, count( StormSale_Test_Env::$remote_calls ), 'nothing is transmitted inline' );
		ss_same( 'queued', $order->meta[ StormSale_Conversion::META_STATUS ] );

		ss_same( 1, count( StormSale_Test_Env::$async_actions ) );

		$dispatch = StormSale_Test_Env::$async_actions[0];

		ss_same( StormSale_Conversion::ACTION, $dispatch['hook'] );
		ss_same( array( 23 ), $dispatch['args'] );
		ss_same( 'stormsale', $dispatch['group'] );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx23' ) );

		do_action( StormSale_Conversion::ACTION, 23 );

		ss_same( 'verified', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 'tx23', $order->meta[ StormSale_Conversion::META_TX ] );
		ss_same( 1, count( StormSale_Test_Env::$remote_calls ) );
	}
);

ss_test(
	'conversion: without Action Scheduler the conversion is sent inline',
	function () {
		ss_configure();
		$order = ss_order( 24 );

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'tx24' ) );

		StormSale_Conversion::on_payment_complete( 24 );

		ss_same( 0, count( StormSale_Test_Env::$async_actions ) );
		ss_same( 'verified', $order->meta[ StormSale_Conversion::META_STATUS ] );
		ss_same( 1, count( StormSale_Test_Env::$remote_calls ) );
	}
);

ss_test(
	'conversion: missing orders and missing gateways are handled defensively',
	function () {
		ss_configure();

		StormSale_Conversion::on_payment_complete( 9999 );
		$result = StormSale_Conversion::process( 9999 );

		ss_same( 'order_not_found', $result['error'] );
		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );

		StormSale_Conversion::on_payment_complete( 'not-an-order' );
		StormSale_Conversion::on_payment_complete( null );

		ss_configure( '', '' );
		$order = ss_order( 25 );
		$result = StormSale_Conversion::process( 25 );

		ss_same( 'not_configured', $result['error'] );
		ss_same( 0, count( StormSale_Test_Env::$remote_calls ) );
		ss_same( '', $order->get_meta( StormSale_Conversion::META_STATUS ) );
	}
);
