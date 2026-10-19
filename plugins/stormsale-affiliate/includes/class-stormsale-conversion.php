<?php

final class StormSale_Conversion {

	const ACTION = 'stormsale_process_conversion';

	const META_ID = '_stormsale_conversion_id';

	const META_STATUS = '_stormsale_conversion_status';

	const META_TX = '_stormsale_conversion_tx';

	const META_ERROR = '_stormsale_conversion_error';

	const META_ATTEMPTS = '_stormsale_conversion_attempts';

	const META_AT = '_stormsale_conversion_completed_at';

	const MAX_ATTEMPTS = 5;

	public static function on_payment_complete( $order ) {
		$order_id = self::order_id_from( $order );

		if ( null === $order_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order_object = wc_get_order( $order_id );

		if ( ! $order_object ) {
			return;
		}

		if ( ! StormSale_Settings::is_configured() ) {
			stormsale_log(
				'warning',
				'Conversion skipped: StormSale campaign ID or API key is missing.',
				array( 'order_id' => $order_id )
			);

			return;
		}

		$status = (string) $order_object->get_meta( self::META_STATUS );

		if ( in_array( $status, array( 'queued', 'pending', 'verified' ), true ) ) {
			return;
		}

		StormSale_Referral::attach_to_order( $order_object );

		$conversion_id = (string) $order_object->get_meta( self::META_ID );

		if ( '' === $conversion_id ) {
			$conversion_id = self::conversion_id( $order_object );
			$order_object->update_meta_data( self::META_ID, $conversion_id );
		}

		if ( '' === (string) $order_object->get_meta( self::META_ATTEMPTS ) ) {
			$order_object->update_meta_data( self::META_ATTEMPTS, 0 );
		}

		$order_object->update_meta_data( self::META_STATUS, 'queued' );
		$order_object->update_meta_data( self::META_ERROR, '' );
		$order_object->save();

		if ( self::use_async_queue() ) {
			as_enqueue_async_action( self::ACTION, array( $order_id ), 'stormsale' );

			return;
		}

		self::process( $order_id );
	}

	public static function process( $order_id ) {
		$order_id = (int) $order_id;

		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::result( false, 'failed', 'woocommerce_missing', 0 );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return self::result( false, 'failed', 'order_not_found', 0 );
		}

		$attempts = (int) $order->get_meta( self::META_ATTEMPTS );
		$status   = (string) $order->get_meta( self::META_STATUS );

		if ( 'verified' === $status ) {
			return self::result( true, 'verified', null, $attempts );
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$order->update_meta_data( self::META_STATUS, 'failed' );
			$order->update_meta_data( self::META_ERROR, 'max_attempts_exceeded' );
			$order->save();

			return self::result( false, 'failed', 'max_attempts_exceeded', $attempts );
		}

		if ( ! StormSale_Settings::is_configured() ) {
			return self::result( false, 'failed', 'not_configured', $attempts );
		}

		$conversion_id = (string) $order->get_meta( self::META_ID );

		if ( '' === $conversion_id ) {
			$conversion_id = self::conversion_id( $order );
			$order->update_meta_data( self::META_ID, $conversion_id );
		}

		$attempts = $attempts + 1;

		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		$order->update_meta_data( self::META_STATUS, 'queued' );
		$order->save();

		$payload = self::build_payload( $order, $conversion_id );

		$client = new StormSale_Client(
			StormSale_Settings::get( 'campaign_id' ),
			StormSale_Settings::get( 'api_key' )
		);

		$response = $client->submit( $payload, $conversion_id );
		$result   = self::result(
			(bool) $response['ok'],
			(string) $response['status'],
			$response['error'],
			$attempts
		);

		if ( $response['ok'] ) {
			$order->update_meta_data( self::META_STATUS, $response['status'] );
			$order->update_meta_data( self::META_ERROR, '' );

			if ( ! empty( $response['tx'] ) ) {
				$order->update_meta_data( self::META_TX, (string) $response['tx'] );
			}

			if ( 'verified' === $response['status'] ) {
				$order->update_meta_data( self::META_AT, gmdate( 'c' ) );
			} else {
				self::schedule_retry( $order_id, $attempts );
			}

			$order->save();

			return $result;
		}

		$order->update_meta_data( self::META_STATUS, 'failed' );
		$order->update_meta_data( self::META_ERROR, (string) $response['error'] );
		$order->save();

		stormsale_log(
			'error',
			'StormSale conversion submission failed.',
			array(
				'order_id' => $order_id,
				'error'    => (string) $response['error'],
				'attempts' => $attempts,
			)
		);

		if ( self::retryable( $response ) ) {
			self::schedule_retry( $order_id, $attempts );
		}

		return $result;
	}

	public static function conversion_id( $order ) {
		$campaign = StormSale_Settings::get( 'campaign_id' );
		$key      = StormSale_Settings::get( 'api_key' );
		$material = $campaign . '|' . (int) $order->get_id() . '|' . (string) $order->get_order_key();

		return substr( hash_hmac( 'sha256', $material, '' !== $key ? $key : 'stormsale' ), 0, 32 );
	}

	public static function build_payload( $order, $conversion_id ) {
		$total    = (string) $order->get_total();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$minor    = (int) round( (float) $total * pow( 10, $decimals ) );
		$email    = strtolower( trim( (string) $order->get_billing_email() ) );

		$payload = array(
			'version'       => 1,
			'conversion_id' => (string) $conversion_id,
			'campaign_id'   => StormSale_Settings::get( 'campaign_id' ),
			'occurred_at'   => gmdate( 'c' ),
			'nonce'         => bin2hex( random_bytes( 8 ) ),
			'order'         => array(
				'id'             => (int) $order->get_id(),
				'number'         => (string) $order->get_order_number(),
				'status'         => (string) $order->get_status(),
				'currency'       => (string) $order->get_currency(),
				'total'          => $total,
				'total_minor'    => $minor,
				'decimals'       => $decimals,
				'item_count'     => (int) $order->get_item_count(),
				'created_at'     => self::order_created_at( $order ),
				'payment_method' => (string) $order->get_payment_method(),
			),
			'referral'      => StormSale_Referral::referral_for_order( $order ),
			'customer'      => array(
				'email_sha256' => '' !== $email ? hash( 'sha256', $email ) : null,
			),
			'store'         => array(
				'url'            => home_url( '/' ),
				'plugin_version' => STORMSALE_VERSION,
			),
		);

		return apply_filters( 'stormsale_conversion_payload', $payload, $order );
	}

	public static function use_async_queue() {
		$enabled = apply_filters( 'stormsale_use_action_scheduler', function_exists( 'as_enqueue_async_action' ) );

		return $enabled && function_exists( 'as_enqueue_async_action' );
	}

	public static function schedule_retry( $order_id, $attempts ) {
		if ( ! self::use_async_queue() || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::ACTION, array( (int) $order_id ), 'stormsale' ) ) {
			return false;
		}

		$delay = 60 * (int) pow( 2, min( (int) $attempts, 5 ) );

		as_schedule_single_action( time() + $delay, self::ACTION, array( (int) $order_id ), 'stormsale' );

		return true;
	}

	public static function retryable( $response ) {
		$code = isset( $response['http_code'] ) ? (int) $response['http_code'] : 0;

		return 0 === $code || $code >= 500 || 429 === $code;
	}

	private static function result( $ok, $status, $error, $attempts ) {
		return array(
			'ok'       => (bool) $ok,
			'status'   => (string) $status,
			'error'    => null === $error ? null : (string) $error,
			'attempts' => (int) $attempts,
		);
	}

	private static function order_id_from( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			return (int) $order->get_id();
		}

		if ( is_numeric( $order ) ) {
			return (int) $order;
		}

		return null;
	}

	private static function order_created_at( $order ) {
		$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

		if ( is_object( $created ) && method_exists( $created, 'getTimestamp' ) ) {
			return gmdate( 'c', (int) $created->getTimestamp() );
		}

		return gmdate( 'c' );
	}
}
