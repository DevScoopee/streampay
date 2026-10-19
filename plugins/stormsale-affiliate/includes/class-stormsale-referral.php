<?php

final class StormSale_Referral {

	const COOKIE = 'stormsale_ref';

	const SESSION_KEY = 'stormsale_ref';

	const META = '_stormsale_ref';

	const META_AT = '_stormsale_ref_at';

	const META_SOURCE = '_stormsale_ref_source';

	const TTL_DAYS = 30;

	const QUERY_PARAMS = array( 'ss_ref', 'ref' );

	const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	public function register() {
		add_action( 'init', array( __CLASS__, 'capture' ), 5 );
		add_action( 'init', array( __CLASS__, 'refresh_cookie' ), 6 );
		add_action( 'wp', array( __CLASS__, 'sync_session' ) );
		add_action( 'woocommerce_before_checkout', array( __CLASS__, 'sync_session' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_to_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'attach_to_order' ), 20, 1 );
	}

	public static function query_params() {
		return self::QUERY_PARAMS;
	}

	public static function sanitize_code( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );

		if ( '' === $raw || strlen( $raw ) > 64 ) {
			return null;
		}

		if ( ! preg_match( self::CODE_PATTERN, $raw ) ) {
			return null;
		}

		return $raw;
	}

	public static function code_from_request( $get ) {
		if ( ! is_array( $get ) ) {
			return null;
		}

		foreach ( self::QUERY_PARAMS as $key ) {
			if ( ! isset( $get[ $key ] ) ) {
				continue;
			}

			$code = self::sanitize_code( is_string( $get[ $key ] ) ? $get[ $key ] : '' );

			if ( null !== $code ) {
				return $code;
			}
		}

		return null;
	}

	public static function current_code() {
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? $_COOKIE[ self::COOKIE ] : '';

		return self::sanitize_code( is_string( $value ) ? $value : '' );
	}

	public static function cookie_params( $code, $now = null ) {
		if ( null === $now ) {
			$now = time();
		}

		return array(
			'name'     => self::COOKIE,
			'value'    => (string) $code,
			'expires'  => $now + ( self::TTL_DAYS * 86400 ),
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIEDOMAIN' ) && COOKIEDOMAIN ? COOKIEDOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	private static function write_cookie( $code ) {
		if ( headers_sent() ) {
			return false;
		}

		$params = self::cookie_params( $code );
		$name   = $params['name'];
		$value  = $params['value'];

		unset( $params['name'], $params['value'] );

		return (bool) setcookie( $name, $value, $params );
	}

	public static function capture() {
		$get  = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		$code = self::code_from_request( $get );

		if ( null === $code ) {
			return null;
		}

		$existing = self::current_code();

		self::write_cookie( $code );

		if ( $existing !== $code ) {
			StormSale_Stats::record_click( $code );
		}

		self::store_session( $code );

		return $code;
	}

	public static function refresh_cookie() {
		$code = self::current_code();

		if ( null === $code ) {
			return false;
		}

		return self::write_cookie( $code );
	}

	private static function session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();

		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}

		return $wc->session;
	}

	public static function session_code() {
		$session = self::session();

		if ( null === $session ) {
			return null;
		}

		$value = $session->get( self::SESSION_KEY, '' );

		return self::sanitize_code( is_string( $value ) ? $value : '' );
	}

	private static function store_session( $code ) {
		$session = self::session();

		if ( null === $session ) {
			return false;
		}

		$session->set( self::SESSION_KEY, (string) $code );

		return true;
	}

	public static function sync_session() {
		$code = self::session_code();

		if ( null === $code ) {
			$code = self::current_code();
		}

		if ( null === $code ) {
			return null;
		}

		if ( ! self::store_session( $code ) ) {
			return null;
		}

		return $code;
	}

	public static function attach_to_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		if ( null !== self::sanitize_code( (string) $order->get_meta( self::META ) ) ) {
			return false;
		}

		$session_code = self::session_code();
		$cookie_code  = self::current_code();

		if ( null !== $session_code ) {
			$code   = $session_code;
			$source = 'session';
		} elseif ( null !== $cookie_code ) {
			$code   = $cookie_code;
			$source = 'cookie';
		} else {
			return false;
		}

		$order->update_meta_data( self::META, $code );
		$order->update_meta_data( self::META_AT, gmdate( 'c' ) );
		$order->update_meta_data( self::META_SOURCE, $source );

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return true;
	}

	public static function referral_for_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return array(
				'code'   => null,
				'source' => 'none',
				'at'     => null,
			);
		}

		$code = self::sanitize_code( (string) $order->get_meta( self::META ) );

		if ( null !== $code ) {
			$source = (string) $order->get_meta( self::META_SOURCE );
			$at     = (string) $order->get_meta( self::META_AT );

			return array(
				'code'   => $code,
				'source' => '' !== $source ? $source : 'order',
				'at'     => '' !== $at ? $at : null,
			);
		}

		$session_code = self::session_code();

		if ( null !== $session_code ) {
			return array(
				'code'   => $session_code,
				'source' => 'session',
				'at'     => null,
			);
		}

		$cookie_code = self::current_code();

		if ( null !== $cookie_code ) {
			return array(
				'code'   => $cookie_code,
				'source' => 'cookie',
				'at'     => null,
			);
		}

		return array(
			'code'   => null,
			'source' => 'none',
			'at'     => null,
		);
	}
}
