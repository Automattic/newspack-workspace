<?php
/**
 * Class TestCrypto
 *
 * @package Newspack_Network
 */

use Newspack_Network\Crypto;

/**
 * Test the Node class.
 */
class TestCrypto extends WP_UnitTestCase {

	/**
	 * Test verify with empty key
	 */
	public function test_verify_empty_key() {
		$verified = Crypto::decrypt_message( 'test', '', 'asdasd' );
		$this->assertFalse( $verified );
	}

	/**
	 * Test verify with invalid key
	 */
	public function test_verify_invalid_key() {
		$verified = Crypto::decrypt_message( 'test', 'asdasd', 'asd' );
		$this->assertFalse( $verified );

		$verified = Crypto::decrypt_message( 'test', [ 'asdasd' ], 'asd' );
		$this->assertFalse( $verified );
	}

	/**
	 * Test verify with wrong key
	 */
	public function test_verify_wrong_key() {
		$keys1          = Crypto::generate_secret_key();
		$keys2          = Crypto::generate_secret_key();
		$nonce          = Crypto::generate_nonce();
		$signed_message = Crypto::encrypt_message( 'test', $keys1, $nonce );
		$this->assertFalse( Crypto::decrypt_message( $signed_message, $keys2, $nonce ) );
	}

	/**
	 * Test verify with correct key
	 */
	public function test_verify_correct_key() {
		$keys           = Crypto::generate_secret_key();
		$nonce          = Crypto::generate_nonce();
		$signed_message = Crypto::encrypt_message( 'test', $keys, $nonce );
		$this->assertSame( 'test', Crypto::decrypt_message( $signed_message, $keys, $nonce ) );
	}
}
