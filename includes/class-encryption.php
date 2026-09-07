<?php
/**
 * Encryption of secrets at rest.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts short secrets (client secret, OAuth tokens) before they
 * are written to the options table.
 *
 * The key is derived from the site's AUTH_KEY / SECURE_AUTH_KEY salts, so the
 * ciphertext is useless without wp-config.php. If those salts are rotated the
 * stored secrets become undecryptable; that is detected via a key fingerprint
 * and surfaced to the administrator as "please reconnect" rather than as a
 * fatal error.
 */
class Encryption {

	/**
	 * Prefix marking a libsodium secretbox payload.
	 */
	const PREFIX_SODIUM = 'jszr1:';

	/**
	 * Prefix marking an OpenSSL AES-256-CBC payload.
	 */
	const PREFIX_OPENSSL = 'jszr2:';

	/**
	 * Encrypt a string.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Portable ciphertext, or an empty string on failure.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		if ( self::has_sodium() ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary ciphertext for storage in a text option, not obfuscating code.
				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
			} catch ( \Exception $e ) {
				// Fall through to OpenSSL.
				unset( $e );
			}
		}

		if ( self::has_openssl() ) {
			$iv_length = (int) openssl_cipher_iv_length( 'aes-256-cbc' );

			try {
				$iv = random_bytes( $iv_length );
			} catch ( \Exception $e ) {
				return '';
			}

			$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			if ( false === $cipher ) {
				return '';
			}

			$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary ciphertext for storage in a text option, not obfuscating code.
			return self::PREFIX_OPENSSL . base64_encode( $iv . $mac . $cipher );
		}

		return '';
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $ciphertext Stored value.
	 * @return string Empty string when the value cannot be decrypted.
	 */
	public static function decrypt( $ciphertext ) {
		$ciphertext = (string) $ciphertext;

		if ( '' === $ciphertext ) {
			return '';
		}

		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		if ( 0 === strpos( $ciphertext, self::PREFIX_SODIUM ) ) {
			if ( ! self::has_sodium() ) {
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encoding binary ciphertext for storage in a text option, not obfuscating code.
			$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX_SODIUM ) ), true );

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			try {
				$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			} catch ( \Exception $e ) {
				return '';
			}

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $ciphertext, self::PREFIX_OPENSSL ) ) {
			if ( ! self::has_openssl() ) {
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encoding binary ciphertext for storage in a text option, not obfuscating code.
			$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX_OPENSSL ) ), true );

			if ( false === $raw ) {
				return '';
			}

			$iv_length = (int) openssl_cipher_iv_length( 'aes-256-cbc' );

			if ( strlen( $raw ) <= $iv_length + 32 ) {
				return '';
			}

			$iv     = substr( $raw, 0, $iv_length );
			$mac    = substr( $raw, $iv_length, 32 );
			$cipher = substr( $raw, $iv_length + 32 );

			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		// Unrecognised format: treat as unreadable rather than leaking raw data.
		return '';
	}

	/**
	 * Short, non-reversible fingerprint of the current key.
	 *
	 * Stored alongside secrets so a salt rotation can be detected and reported.
	 *
	 * @return string
	 */
	public static function key_fingerprint() {
		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		return substr( hash( 'sha256', 'jszr-fingerprint' . $key ), 0, 16 );
	}

	/**
	 * Whether the environment can encrypt at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::key() && ( self::has_sodium() || self::has_openssl() );
	}

	/**
	 * Derive the 32-byte symmetric key.
	 *
	 * @return string Raw binary key, or empty string when salts are unavailable.
	 */
	private static function key() {
		$material = '';

		if ( defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ) {
			$material .= (string) AUTH_KEY;
		}

		if ( defined( 'SECURE_AUTH_KEY' ) && '' !== (string) SECURE_AUTH_KEY ) {
			$material .= (string) SECURE_AUTH_KEY;
		}

		if ( defined( 'LOGGED_IN_KEY' ) && '' !== (string) LOGGED_IN_KEY ) {
			$material .= (string) LOGGED_IN_KEY;
		}

		if ( '' === $material ) {
			return '';
		}

		return hash( 'sha256', 'jszr-secret-v1|' . $material, true );
	}

	/**
	 * Whether libsodium secretbox is usable.
	 *
	 * @return bool
	 */
	private static function has_sodium() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Whether OpenSSL AES-256-CBC is usable.
	 *
	 * @return bool
	 */
	private static function has_openssl() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( 'aes-256-cbc', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}
}
