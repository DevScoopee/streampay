<?php

final class StormSale_Crypto {

	const CIPHER = 'aes-256-gcm';

	const VERSION = 1;

	const IV_BYTES = 12;

	public static function derive_key( $api_key, $purpose ) {
		return hash_hkdf( 'sha256', (string) $api_key, 32, 'stormsale.' . $purpose . '.v1' );
	}

	public static function key_id( $api_key ) {
		return substr( hash( 'sha256', (string) $api_key ), 0, 16 );
	}

	public static function supports_cipher() {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		return in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	public static function b64( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function unb64( $encoded ) {
		$encoded = (string) $encoded;
		$encoded = strtr( $encoded, '-_', '+/' );
		$padding = strlen( $encoded ) % 4;

		if ( $padding > 0 ) {
			$encoded .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( $encoded, true );
	}

	public static function encrypt( $plaintext, $api_key, $aad ) {
		if ( '' === (string) $plaintext || '' === (string) $api_key || ! self::supports_cipher() ) {
			return null;
		}

		$iv     = random_bytes( self::IV_BYTES );
		$tag    = '';
		$secret = self::derive_key( $api_key, 'payload' );
		$bound  = (string) $aad;

		$ciphertext = openssl_encrypt(
			(string) $plaintext,
			self::CIPHER,
			$secret,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$bound
		);

		if ( false === $ciphertext || '' === $tag ) {
			return null;
		}

		$envelope = wp_json_encode(
			array(
				'version'    => self::VERSION,
				'cipher'     => self::CIPHER,
				'kid'        => self::key_id( $api_key ),
				'aad'        => self::b64( $bound ),
				'iv'         => self::b64( $iv ),
				'tag'        => self::b64( $tag ),
				'ciphertext' => self::b64( $ciphertext ),
			)
		);

		if ( false === $envelope || '' === $envelope ) {
			return null;
		}

		return self::b64( $envelope );
	}

	public static function decrypt( $blob, $api_key, $aad ) {
		if ( '' === (string) $blob || '' === (string) $api_key || ! self::supports_cipher() ) {
			return null;
		}

		$envelope = self::unb64( $blob );

		if ( false === $envelope || '' === $envelope ) {
			return null;
		}

		$data = json_decode( $envelope, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$required = array( 'iv', 'tag', 'ciphertext' );

		if ( ! isset( $data['version'] ) || self::VERSION !== (int) $data['version'] ) {
			return null;
		}

		if ( ! isset( $data['cipher'] ) || self::CIPHER !== $data['cipher'] ) {
			return null;
		}

		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return null;
			}
		}

		$iv         = self::unb64( $data['iv'] );
		$tag        = self::unb64( $data['tag'] );
		$ciphertext = self::unb64( $data['ciphertext'] );

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return null;
		}

		if ( isset( $data['kid'] ) && self::key_id( $api_key ) !== (string) $data['kid'] ) {
			return null;
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::derive_key( $api_key, 'payload' ),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			(string) $aad
		);

		return false === $plaintext ? null : $plaintext;
	}

	public static function sign( $blob, $api_key ) {
		return 'v1=' . hash_hmac( 'sha256', (string) $blob, self::derive_key( $api_key, 'mac' ) );
	}
}
