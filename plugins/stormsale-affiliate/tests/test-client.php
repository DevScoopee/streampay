<?php

ss_test(
	'client: submits an encrypted, signed, idempotent request to the gateway',
	function () {
		ss_configure( 'cmp_winter25', 'sk_live_key_1' );

		$payload = array(
			'version'       => 1,
			'conversion_id' => 'conv_1',
			'order'         => array( 'id' => 7, 'total' => '19.99' ),
		);

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'verified', 'tx' => 'deadbeef', 'ledger' => 42 ) );

		$client   = new StormSale_Client( 'cmp_winter25', 'sk_live_key_1' );
		$response = $client->submit( $payload, 'conv_1' );

		$request = ss_last_request();

		ss_same( STORMSALE_GATEWAY_URL, $request['url'] );
		ss_same( true, $request['args']['sslverify'] );
		ss_same( 15, $request['args']['timeout'] );

		$headers = $request['args']['headers'];

		ss_same( 'application/json', $headers['Content-Type'] );
		ss_same( 'cmp_winter25', $headers['X-StormSale-Campaign'] );
		ss_same( 'conv_1', $headers['X-StormSale-Conversion'] );
		ss_contains( $headers['User-Agent'], 'StormSale-Affiliate/' . STORMSALE_VERSION );

		$body = json_decode( $request['args']['body'], true );

		ss_same( 'cmp_winter25', $body['campaign_id'] );
		ss_same( 'conv_1', $body['conversion_id'] );
		ss_ok( is_string( $body['payload'] ), 'payload is a string blob' );
		ss_not_contains( $request['args']['body'], '19.99', 'the request body never carries plaintext order data' );

		$plaintext = StormSale_Crypto::decrypt( $body['payload'], 'sk_live_key_1', 'campaign:cmp_winter25' );

		ss_same( wp_json_encode( $payload ), $plaintext, 'the gateway can decrypt the order payload' );

		ss_same(
			StormSale_Crypto::sign( $body['payload'], 'sk_live_key_1' ),
			$headers['X-StormSale-Signature'],
			'signature covers the envelope with the shared API key'
		);

		ss_same( true, $response['ok'] );
		ss_same( 'verified', $response['status'] );
		ss_same( 'deadbeef', $response['tx'] );
		ss_same( 42, $response['ledger'] );
		ss_same( 200, $response['http_code'] );
		ss_same( null, $response['error'] );
	}
);

ss_test(
	'client: the gateway URL is filterable for self hosted gateways',
	function () {
		add_filter(
			'stormsale_gateway_url',
			function ( $url ) {
				return 'https://gateway.internal.test/v1/conversions';
			}
		);

		StormSale_Test_Env::queue_response( 200, array( 'status' => 'pending' ) );

		$client = new StormSale_Client( 'cmp_1', 'sk_1' );
		$client->submit( array( 'x' => 1 ), 'conv_2' );

		ss_same( 'https://gateway.internal.test/v1/conversions', ss_last_request()['url'] );
	}
);

ss_test(
	'client: accepted responses normalize to a pending status',
	function () {
		StormSale_Test_Env::queue_response( 202, array( 'status' => 'accepted' ) );

		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_3' );

		ss_same( true, $response['ok'] );
		ss_same( 'pending', $response['status'] );
	}
);

ss_test(
	'client: a 2xx without a status field is treated as pending',
	function () {
		StormSale_Test_Env::queue_response( 200, array() );

		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_4' );

		ss_same( true, $response['ok'] );
		ss_same( 'pending', $response['status'] );
	}
);

ss_test(
	'client: unknown success statuses are reported as failures',
	function () {
		StormSale_Test_Env::queue_response( 200, array( 'status' => 'weird' ) );

		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_5' );

		ss_same( false, $response['ok'] );
		ss_same( 'unexpected_status:weird', $response['error'] );
	}
);

ss_test(
	'client: gateway rejections surface the gateway message',
	function () {
		StormSale_Test_Env::queue_response( 401, array( 'message' => 'invalid api key' ) );

		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_6' );

		ss_same( false, $response['ok'] );
		ss_same( 'failed', $response['status'] );
		ss_same( 'invalid api key', $response['error'] );
		ss_same( 401, $response['http_code'] );
	}
);

ss_test(
	'client: transport level errors become retryable failures',
	function () {
		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_7' );

		ss_same( false, $response['ok'] );
		ss_same( 'failed', $response['status'] );
		ss_contains( $response['error'], 'no response queued' );
		ss_same( 0, $response['http_code'] );
		ss_same( true, StormSale_Conversion::retryable( $response ) );
	}
);

ss_test(
	'client: WP_Error responses are reported without fatals',
	function () {
		StormSale_Test_Env::$remote_queue[] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );

		$client   = new StormSale_Client( 'cmp_1', 'sk_1' );
		$response = $client->submit( array( 'x' => 1 ), 'conv_8' );

		ss_same( false, $response['ok'] );
		ss_contains( $response['error'], 'cURL error 28' );
		ss_same( 0, $response['http_code'] );
	}
);
