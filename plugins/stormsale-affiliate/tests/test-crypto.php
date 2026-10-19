<?php

ss_test(
	'crypto: encrypt/decrypt roundtrip binds ciphertext to the campaign AAD',
	function () {
		$plaintext = wp_json_encode(
			array(
				'order' => array(
					'id'    => 42,
					'total' => '19.99',
				),
			)
		);

		$blob = StormSale_Crypto::encrypt( $plaintext, 'sk_live_key_1', 'campaign:cmp_winter25' );

		ss_ok( is_string( $blob ) && '' !== $blob, 'an envelope blob is produced' );
		ss_not_contains( $blob, '19.99', 'the transport blob must not contain plaintext order data' );

		$roundtrip = StormSale_Crypto::decrypt( $blob, 'sk_live_key_1', 'campaign:cmp_winter25' );

		ss_same( $plaintext, $roundtrip, 'decryption reproduces the exact plaintext' );
	}
);

ss_test(
	'crypto: envelope is a versioned base64url JSON document',
	function () {
		$blob      = StormSale_Crypto::encrypt( '{"hello":"world"}', 'sk_live_key_1', 'campaign:cmp_1' );
		$envelope  = json_decode( StormSale_Crypto::unb64( $blob ), true );

		ss_ok( is_array( $envelope ), 'envelope decodes to JSON' );
		ss_same( 1, $envelope['version'], 'envelope version' );
		ss_same( 'aes-256-gcm', $envelope['cipher'], 'envelope cipher' );
		ss_same( StormSale_Crypto::key_id( 'sk_live_key_1' ), $envelope['kid'], 'envelope kid' );
		ss_same( StormSale_Crypto::b64( 'campaign:cmp_1' ), $envelope['aad'], 'envelope records the AAD' );

		foreach ( array( 'iv', 'tag', 'ciphertext' ) as $field ) {
			ss_ok( isset( $envelope[ $field ] ) && is_string( $envelope[ $field ] ), 'envelope has ' . $field );
		}

		ss_same( 12, strlen( StormSale_Crypto::unb64( $envelope['iv'] ) ), 'IV is 96 bits' );
		ss_same( 16, strlen( StormSale_Crypto::unb64( $envelope['tag'] ) ), 'GCM tag is 128 bits' );
	}
);

ss_test(
	'crypto: a fresh random IV is used for every encryption',
	function () {
		$plaintext = 'same payload every time';

		$first  = StormSale_Crypto::encrypt( $plaintext, 'sk_live_key_1', 'campaign:cmp_1' );
		$second = StormSale_Crypto::encrypt( $plaintext, 'sk_live_key_1', 'campaign:cmp_1' );

		ss_not_ok( $first === $second, 'two encryptions of the same plaintext must differ' );
		ss_same( $plaintext, StormSale_Crypto::decrypt( $first, 'sk_live_key_1', 'campaign:cmp_1' ) );
		ss_same( $plaintext, StormSale_Crypto::decrypt( $second, 'sk_live_key_1', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: tampered ciphertext is rejected',
	function () {
		$blob     = StormSale_Crypto::encrypt( 'top secret order', 'sk_live_key_1', 'campaign:cmp_1' );
		$envelope = json_decode( StormSale_Crypto::unb64( $blob ), true );

		$raw = StormSale_Crypto::unb64( $envelope['ciphertext'] );
		$raw[0] = $raw[0] === 'A' ? 'B' : 'A';
		$envelope['ciphertext'] = StormSale_Crypto::b64( $raw );

		$tampered = StormSale_Crypto::b64( wp_json_encode( $envelope ) );

		ss_same( null, StormSale_Crypto::decrypt( $tampered, 'sk_live_key_1', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: a tampered GCM tag is rejected',
	function () {
		$blob     = StormSale_Crypto::encrypt( 'top secret order', 'sk_live_key_1', 'campaign:cmp_1' );
		$envelope = json_decode( StormSale_Crypto::unb64( $blob ), true );

		$tag          = StormSale_Crypto::unb64( $envelope['tag'] );
		$tag[0]       = $tag[0] === 'a' ? 'b' : 'a';
		$envelope['tag'] = StormSale_Crypto::b64( $tag );

		$tampered = StormSale_Crypto::b64( wp_json_encode( $envelope ) );

		ss_same( null, StormSale_Crypto::decrypt( $tampered, 'sk_live_key_1', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: ciphertext cannot be decrypted with a different API key',
	function () {
		$blob = StormSale_Crypto::encrypt( 'order payload', 'sk_live_key_1', 'campaign:cmp_1' );

		ss_same( null, StormSale_Crypto::decrypt( $blob, 'sk_live_key_2', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: ciphertext cannot be replayed into another campaign',
	function () {
		$blob = StormSale_Crypto::encrypt( 'order payload', 'sk_live_key_1', 'campaign:cmp_1' );

		ss_same( null, StormSale_Crypto::decrypt( $blob, 'sk_live_key_1', 'campaign:cmp_2' ) );
	}
);

ss_test(
	'crypto: envelopes with an unknown version are rejected',
	function () {
		$blob     = StormSale_Crypto::encrypt( 'order payload', 'sk_live_key_1', 'campaign:cmp_1' );
		$envelope = json_decode( StormSale_Crypto::unb64( $blob ), true );

		$envelope['version'] = 99;
		$unsupported         = StormSale_Crypto::b64( wp_json_encode( $envelope ) );

		ss_same( null, StormSale_Crypto::decrypt( $unsupported, 'sk_live_key_1', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: garbage blobs decrypt to null instead of fataling',
	function () {
		ss_same( null, StormSale_Crypto::decrypt( 'not-base64-!!!', 'sk_live_key_1', 'campaign:cmp_1' ) );
		ss_same( null, StormSale_Crypto::decrypt( StormSale_Crypto::b64( 'not json at all' ), 'sk_live_key_1', 'campaign:cmp_1' ) );
		ss_same( null, StormSale_Crypto::decrypt( '', 'sk_live_key_1', 'campaign:cmp_1' ) );
		ss_same( null, StormSale_Crypto::encrypt( '', 'sk_live_key_1', 'campaign:cmp_1' ) );
		ss_same( null, StormSale_Crypto::encrypt( 'x', '', 'campaign:cmp_1' ) );
	}
);

ss_test(
	'crypto: HMAC signatures are deterministic, keyed and well formed',
	function () {
		$signature = StormSale_Crypto::sign( 'envelope-blob', 'sk_live_key_1' );

		ss_ok( preg_match( '/^v1=[0-9a-f]{64}$/', $signature ) === 1, 'signature format v1=<hex sha256>' );
		ss_same( $signature, StormSale_Crypto::sign( 'envelope-blob', 'sk_live_key_1' ) );
		ss_not_ok( $signature === StormSale_Crypto::sign( 'envelope-blob', 'sk_live_key_2' ), 'signature depends on the key' );
		ss_not_ok( $signature === StormSale_Crypto::sign( 'other-blob', 'sk_live_key_1' ), 'signature depends on the blob' );
	}
);
