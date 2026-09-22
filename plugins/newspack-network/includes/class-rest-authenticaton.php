<?php
/**
 * Newspack Network Rest Authenticator.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack_Network\Node\Settings as Node_Settings;
use WP_REST_Request;

/**
 * This class allows some REST endpoints to be accessed using the shared Hub/Node Secret Key to authenticate.
 */
class Rest_Authenticaton {

	/**
	 * The list of endpoints that can be accessed using the shared Hub/Node Secret Key to authenticate
	 *
	 * The key is the endpoint ID, used to identify the endpoint when signing and checking the signature.
	 *
	 * The endpoint is a regex that will be matched against the REST request route.
	 *
	 * The callback is a function that will be called if a signed request to this endpoints is successfully verified.
	 */
	const ENDPOINTS = [
		'get-woo-orders'           => [
			'endpoint' => '|^/wc/v3/orders/[0-9]+$|',
			'callback' => [ __CLASS__, 'add_filter_for_woo_read_endpoints' ],
		],
		'get-woo-subscriptions'    => [
			'endpoint' => '|^/wc/v3/subscriptions/[0-9]+$|',
			'callback' => [ __CLASS__, 'add_filter_for_woo_read_endpoints' ],
		],
		'get-woo-membership-plans' => [
			'endpoint' => '|^/wc/v2/memberships/plans|',
			'callback' => [ __CLASS__, 'add_filter_for_woo_read_endpoints' ],
		],
		'get-woo-memberships'      => [
			'endpoint' => '|^/wc/v2/memberships|',
			'callback' => [ __CLASS__, 'add_filter_for_woo_read_endpoints' ],
		],
	];

	/**
	 * Requests whose signature has already been accepted during this PHP request,
	 * mapped to the endpoint ID it was accepted for.
	 *
	 * Core calls a route's permission callback a second time with the same request
	 * object while building the Allow header. Without this, that call finds the nonce
	 * already claimed and the route's methods drop out of the header. The request
	 * itself is unaffected, and a later HTTP request is always a new object.
	 *
	 * @var \WeakMap|null
	 */
	private static ?\WeakMap $verified_requests = null;

	/**
	 * Initializes the hook used in the Node to override the authentication to some REST endpoints.
	 *
	 * @return void
	 */
	public static function init_node_filters() {
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'rest_pre_dispatch' ], 10, 3 );
	}

	/**
	 * Generates the signature headers to be used in a REST request.
	 *
	 * @param string $endpoint_id The ID of the endpoint to be accessed.
	 * @param string $secret_key The shared secret key.
	 * @return array|\WP_Error An array with the signature headers, or a WP_Error if the signature could not be generated.
	 */
	public static function generate_signature_headers( $endpoint_id, $secret_key ) {
		$params    = [
			'timestamp'   => time(),
			'endpoint_id' => $endpoint_id,
		];
		$nonce     = Crypto::generate_nonce();
		$signature = Crypto::encrypt_message( wp_json_encode( $params ), $secret_key, $nonce );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}
		return [
			'X-NP-Network-Signature' => $signature,
			'X-NP-Network-Nonce'     => $nonce,
		];
	}

	/**
	 * Verifies the signature of a REST request.
	 *
	 * Each signature is accepted once: its nonce is recorded in {@see Used_Nonces}
	 * and a repeat is refused, on top of the 60-second freshness window.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param string          $endpoint_id The ID of the endpoint to be accessed.
	 * @param string          $secret_key The shared secret key.
	 * @return bool|\WP_Error True if the signature is valid, or a WP_Error if the signature is invalid.
	 */
	public static function verify_signature( WP_REST_Request $request, $endpoint_id, $secret_key ) {
		if ( null === self::$verified_requests ) {
			self::$verified_requests = new \WeakMap();
		}
		if ( ( self::$verified_requests[ $request ] ?? null ) === $endpoint_id ) {
			return true;
		}

		$signature = $request->get_header( 'X-NP-Network-Signature' );
		$nonce     = $request->get_header( 'X-NP-Network-Nonce' );

		$verified = Crypto::decrypt_message( $signature, $secret_key, $nonce );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$verified = json_decode( $verified, true );

		if ( ! is_array( $verified ) ) {
			return new \WP_Error( 'newspack-network-authentication-error', 'Invalid Signature', [ 'status' => 401 ] );
		}

		if ( ( $verified['endpoint_id'] ?? null ) !== $endpoint_id ) {
			return new \WP_Error( 'newspack-network-authentication-error', 'Signature mismatch', [ 'status' => 401 ] );
		}

		if ( ! is_int( $verified['timestamp'] ?? null ) || time() - $verified['timestamp'] > 60 ) {
			return new \WP_Error( 'newspack-network-authentication-error', 'Signature expired', [ 'status' => 401 ] );
		}

		// Claimed last, so only a signature that passed every other check uses up
		// its nonce. Anything but a fresh claim is refused, including a store that
		// could not record it: accepting then would make the signature reusable.
		$claim = Used_Nonces::claim( $nonce );
		if ( null === $claim ) {
			return new \WP_Error( 'newspack-network-authentication-error', 'Could not record signature', [ 'status' => 500 ] );
		}
		if ( Used_Nonces::CLAIMED !== $claim ) {
			return new \WP_Error( 'newspack-network-authentication-error', 'Signature already used', [ 'status' => 401 ] );
		}
		// Nothing follows the claim that could fail, so it is final at once rather
		// than left pending for a later outcome.
		Used_Nonces::complete( $nonce );

		self::$verified_requests[ $request ] = $endpoint_id;

		return true;
	}

	/**
	 * Checks if a REST request is signed.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public static function is_request_signed( WP_REST_Request $request ) {
		return ! is_null( $request->get_header( 'X-NP-Network-Signature' ) ) && ! is_null( $request->get_header( 'X-NP-Network-Nonce' ) );
	}


	/**
	 * Callback for the rest_request_before_callbacks filter.
	 *
	 * @param mixed           $response The response to be filtered.
	 * @param array           $handler Route handler used for the request.
	 * @param WP_REST_Request $request The REST request received.
	 * @return mixed The response to be sent.
	 */
	public static function rest_pre_dispatch( $response, $handler, $request ) {
		if ( ! self::is_request_signed( $request ) ) {
			return $response;
		}

		Debugger::log( 'Signed API request received' );

		$secret_key = Node_Settings::get_secret_key();

		foreach ( self::ENDPOINTS as $endpoint_id => $endpoint ) {
			if ( preg_match( $endpoint['endpoint'], $request->get_route() ) ) {

				Debugger::log( 'Route matched: ' . $request->get_route() );

				$verified = self::verify_signature( $request, $endpoint_id, $secret_key );

				if ( is_wp_error( $verified ) ) {
					Debugger::log( 'Signature verification failed' );
					Debugger::log( $verified->get_error_message() );
					return $verified;
				}

				Debugger::log( 'Signature verified' );

				call_user_func( $endpoint['callback'] );

				break;
			}
		}

		return $response;
	}

	/**
	 * Adds a filter to allow read access to WooCommerce REST endpoints.
	 *
	 * @return void
	 */
	public static function add_filter_for_woo_read_endpoints() {
		add_filter( 'woocommerce_rest_check_permissions', [ __CLASS__, 'allow_woo_read_endpoints' ], 10, 2 );
	}

	/**
	 * Callback for the woocommerce_rest_check_permissions filter. Enables access to read endpoints.
	 *
	 * @param bool   $permission Whether the user has permission to access the endpoint.
	 * @param string $context The context for the permission check.
	 * @return bool
	 */
	public static function allow_woo_read_endpoints( $permission, $context ) {
		if ( 'read' === $context ) {
			return true;
		}
		return $permission;
	}
}
