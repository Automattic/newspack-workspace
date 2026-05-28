<?php
/**
 * Newspack Hub Crypto methods.
 *
 * @package Newspack
 */

namespace Newspack_Network;

/**
 * Class with basic crypto methods
 */
class Crypto {

	/**
	 * Generates a new key pair
	 *
	 * @return array An array with the private and public keys
	 */
	public static function generate_secret_key() {
		$key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
		return bin2hex( $key );
	}

	/**
	 * Whether a value is a non-empty, even-length hexadecimal string (i.e. safe for hex2bin()).
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	private static function is_hex( $value ) {
		return is_string( $value ) && '' !== $value && 0 === strlen( $value ) % 2 && ctype_xdigit( $value );
	}

	/**
	 * Decrypts a message
	 *
	 * @param string $message The hex-encoded message to be decrypted.
	 * @param string $secret_key The secret key to verify the message with.
	 * @param string $nonce The nonce to verify the message with, generated with Crypto::generate_nonce().
	 * @return string|false The decrypted message or false if the message could not be decrypted.
	 */
	public static function decrypt_message( $message, $secret_key, $nonce ) {
		if ( ! self::is_hex( $message ) || ! self::is_hex( $secret_key ) || ! self::is_hex( $nonce ) ) {
			return false;
		}

		try {
			$decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( hex2bin( $message ), '', hex2bin( $nonce ), hex2bin( $secret_key ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		return $decrypted;
	}

	/**
	 * Encrypts a message
	 *
	 * @param string $message The message to be encrypted.
	 * @param string $secret_key The secret key to encrypt the message with.
	 * @param string $nonce The nonce to verify the message with, generated with Crypto::generate_nonce().
	 * @return string|false|WP_Error The encrypted message, false on invalid arguments, or WP_Error if encryption failed.
	 */
	public static function encrypt_message( $message, $secret_key, $nonce ) {
		if ( ! is_string( $message ) || ! self::is_hex( $secret_key ) || ! self::is_hex( $nonce ) ) {
			return false;
		}

		try {
			$encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $message, '', hex2bin( $nonce ), hex2bin( $secret_key ) );
			return bin2hex( $encrypted );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'newspack-network-node-webhook-encrypting-error', $e->getMessage() );
		}
	}

	/**
	 * Generates a nonce to encrypt messages
	 *
	 * @return string
	 */
	public static function generate_nonce() {
		return bin2hex( random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) );
	}
}
