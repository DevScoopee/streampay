<?php

final class StormSale_Client {

	private $campaign_id;

	private $api_key;

	public function __construct( $campaign_id, $api_key ) {
		$this->campaign_id = (string) $campaign_id;
		$this->api_key     = (string) $api_key;
	}

	public function gateway_url() {
		return (string) apply_filters( 'stormsale_gateway_url', STORMSALE_GATEWAY_URL );
	}

	public function aad() {
		return 'campaign:' . $this->campaign_id;
	}

	public function submit( array $payload, $conversion_id ) {
		$conversion_id = (string) $conversion_id;

		$plaintext = wp_json_encode( $payload );

		if ( false === $plaintext || '' === $plaintext ) {
			return self::failure( 'payload_encode_failed' );
		}

		$blob = StormSale_Crypto::encrypt( $plaintext, $this->api_key, $this->aad() );

		if ( null === $blob ) {
			return self::failure( 'encryption_failed' );
		}

		$body = wp_json_encode(
			array(
				'campaign_id'   => $this->campaign_id,
				'conversion_id' => $conversion_id,
				'payload'       => $blob,
			)
		);

		if ( false === $body || '' === $body ) {
			return self::failure( 'request_encode_failed' );
		}

		$response = wp_remote_post(
			$this->gateway_url(),
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'           => 'application/json',
					'Accept'                 => 'application/json',
					'User-Agent'             => 'StormSale-Affiliate/' . STORMSALE_VERSION . '; ' . home_url(),
					'X-StormSale-Campaign'   => $this->campaign_id,
					'X-StormSale-Conversion' => $conversion_id,
					'X-StormSale-Signature'  => StormSale_Crypto::sign( $blob, $this->api_key ),
				),
				'body'        => $body,
			)
		);

		return $this->interpret( $response );
	}

	private function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return self::failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = 'http_' . $code;

			if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) && '' !== $data['message'] ) {
				$message = $data['message'];
			}

			return self::failure( $message, $code );
		}

		$status = is_array( $data ) && isset( $data['status'] ) && is_string( $data['status'] )
			? strtolower( $data['status'] )
			: 'pending';

		if ( 'accepted' === $status || 'processing' === $status ) {
			$status = 'pending';
		}

		if ( ! in_array( $status, array( 'verified', 'pending' ), true ) ) {
			return self::failure( 'unexpected_status:' . $status, $code );
		}

		$tx = null;

		if ( is_array( $data ) && isset( $data['tx'] ) && is_scalar( $data['tx'] ) ) {
			$tx = (string) $data['tx'];
		}

		$ledger = null;

		if ( is_array( $data ) && isset( $data['ledger'] ) && is_numeric( $data['ledger'] ) ) {
			$ledger = (int) $data['ledger'];
		}

		return array(
			'ok'        => true,
			'status'    => $status,
			'tx'        => $tx,
			'ledger'    => $ledger,
			'http_code' => $code,
			'error'     => null,
		);
	}

	public static function failure( $error, $http_code = 0 ) {
		return array(
			'ok'        => false,
			'status'    => 'failed',
			'tx'        => null,
			'ledger'    => null,
			'http_code' => (int) $http_code,
			'error'     => (string) $error,
		);
	}
}
