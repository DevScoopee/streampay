<?php

final class StormSale_Stats {

	const OPTION = 'stormsale_clicks';

	const MAX_CODES = 500;

	public static function defaults() {
		return array(
			'total'     => 0,
			'codes'     => array(),
			'last_code' => '',
			'last_at'   => 0,
		);
	}

	public static function load() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stats = array_merge( self::defaults(), $stored );

		if ( ! is_array( $stats['codes'] ) ) {
			$stats['codes'] = array();
		}

		$stats['total']     = (int) $stats['total'];
		$stats['last_code'] = (string) $stats['last_code'];
		$stats['last_at']   = (int) $stats['last_at'];

		return $stats;
	}

	public static function record_click( $code ) {
		$stats = self::load();
		$codes = $stats['codes'];

		$codes[ (string) $code ] = isset( $codes[ $code ] ) ? (int) $codes[ $code ] + 1 : 1;
		$codes = array_slice( $codes, -1 * self::MAX_CODES, null, true );

		$stats['total']     = $stats['total'] + 1;
		$stats['codes']     = $codes;
		$stats['last_code'] = (string) $code;
		$stats['last_at']   = time();

		update_option( self::OPTION, $stats, false );

		return $stats;
	}

	public static function clear() {
		delete_option( self::OPTION );

		return self::defaults();
	}
}
