<?php
/**
 * Newspack Network reader access envelope.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Gate;

use Newspack_Network\Crypto;
use WP_Error;

/**
 * Seals and opens the hub's answer to a node asking what a reader holds on the
 * other network sites.
 *
 * An answer decides who reads for free, so it is encrypted with the key the hub
 * and the node share, which also proves who sent it, and it names the reader and
 * the request it answers. Without that, a recorded answer could be replayed for a
 * later request or passed off as another reader's.
 */
class Reader_Access_Envelope {

	/**
	 * Encrypt an answer.
	 *
	 * @param array  $payload    The answer, including 'email' and 'request_id'.
	 * @param string $secret_key Key the hub and the node share.
	 * @return array|WP_Error Envelope with 'nonce' and 'data'.
	 */
	public static function seal( $payload, $secret_key ) {
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return new WP_Error( 'newspack_network_reader_access_encode', __( 'Could not encode the reader access answer.', 'newspack-network' ) );
		}
		$nonce = Crypto::generate_nonce();
		$data  = Crypto::encrypt_message( $json, $secret_key, $nonce );
		if ( ! is_string( $data ) ) {
			return new WP_Error( 'newspack_network_reader_access_encrypt', __( 'Could not encrypt the reader access answer.', 'newspack-network' ) );
		}
		return [
			'nonce' => $nonce,
			'data'  => $data,
		];
	}

	/**
	 * Decrypt an answer and check it answers this request about this reader.
	 *
	 * @param mixed  $envelope   Envelope as decoded from the response body.
	 * @param string $secret_key Key the hub and the node share.
	 * @param string $email      Email the request asked about.
	 * @param string $request_id ID the request was sent with.
	 * @return array|WP_Error The answer.
	 */
	public static function open( $envelope, $secret_key, $email, $request_id ) {
		if ( ! is_array( $envelope ) || empty( $envelope['nonce'] ) || ! isset( $envelope['data'] ) || ! is_string( $envelope['data'] ) ) {
			return new WP_Error( 'newspack_network_reader_access_unsealed', __( 'The reader access answer was not encrypted.', 'newspack-network' ) );
		}
		$json = Crypto::decrypt_message( $envelope['data'], $secret_key, $envelope['nonce'] );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'newspack_network_reader_access_signature', __( 'Could not verify the reader access answer.', 'newspack-network' ) );
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'newspack_network_reader_access_malformed', __( 'The reader access answer was malformed.', 'newspack-network' ) );
		}
		$email_matches   = is_string( $payload['email'] ?? null ) && 0 === strcasecmp( $payload['email'], (string) $email );
		$request_matches = is_string( $payload['request_id'] ?? null ) && hash_equals( (string) $request_id, $payload['request_id'] );
		if ( ! $email_matches || ! $request_matches ) {
			return new WP_Error( 'newspack_network_reader_access_mismatch', __( 'The reader access answer was for a different request.', 'newspack-network' ) );
		}
		return $payload;
	}
}
