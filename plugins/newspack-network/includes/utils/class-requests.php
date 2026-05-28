<?php
/**
 * Newspack Network Requests methods.
 *
 * @package Newspack
 */

namespace Newspack_Network\Utils;

use Newspack_Network\Crypto;
use Newspack_Network\Node\Settings;
use WP_Error;

/**
 * Requests.
 */
class Requests {

	/**
	 * Make a request to the Hub.
	 *
	 * @param string $endpoint The endpoint to request.
	 * @param array  $params The parameters to send.
	 * @param string $method The request method.
	 */
	public static function request_to_hub( $endpoint, $params, $method = 'POST' ) {
		$url = trailingslashit( Settings::get_hub_url() ) . $endpoint;
		return wp_remote_request(
			$url,
			[
				'method'  => $method,
				'body'    => self::sign_params( $params ),
				'timeout' => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);
	}

	/**
	 * Signs the request parameters with the Node's secret key
	 *
	 * @param array $params The request parameters.
	 * @return array The params array with an additional signature key.
	 */
	public static function sign_params( $params ) {
		$message             = wp_json_encode( $params );
		$secret_key          = Settings::get_secret_key();
		$nonce               = Crypto::generate_nonce();
		$signature           = Crypto::encrypt_message( $message, $secret_key, $nonce );
		$params['signature'] = $signature;
		$params['nonce']     = $nonce;
		return $params;
	}

	/**
	 * Validate a request to the Hub and return its authenticated parameters.
	 *
	 * The signature is verified against the requesting Node's secret key; on success the
	 * decrypted request parameters are returned so callers can use those instead of the
	 * plaintext copies (which a man-in-the-middle could tamper with).
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return array|WP_Error The verified request parameters, or WP_Error if verification failed.
	 */
	public static function verify_request_to_hub( $request ) {
		$site      = $request['site'];
		$signature = $request['signature'];
		$nonce     = $request['nonce'];

		if ( empty( $site ) ||
			empty( $nonce ) ||
			empty( $signature )
		) {
			return new WP_Error( 'newspack_network_bad_request', __( 'Bad request.', 'newspack-network' ) );
		}

		$node = \Newspack_Network\Hub\Nodes::get_node_by_url( $site );

		if ( ! $node ) {
			\Newspack_Network\Debugger::log( 'Node not found.' );
			return new WP_Error( 'newspack_network_bad_request_node_not_found', __( 'Bad request. Site not registered in this Hub', 'newspack-network' ) );
		}

		$verified = $node->decrypt_message( $signature, $nonce );
		$params   = is_string( $verified ) ? json_decode( $verified, true ) : null;
		if ( ! is_array( $params ) ) {
			\Newspack_Network\Debugger::log( 'Signature check failed' );
			return new WP_Error( 'newspack_network_bad_request_signature', __( 'Bad request. Invalid signature.', 'newspack-network' ) );
		}

		return $params;
	}
}
