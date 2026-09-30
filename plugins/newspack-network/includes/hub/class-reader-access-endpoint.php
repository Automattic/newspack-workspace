<?php
/**
 * Newspack Network Hub reader access endpoint.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Content_Gate\Reader_Access_Collector;
use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Debugger;
use Newspack_Network\Node\Reader_Access_Endpoint as Node_Endpoint;
use Newspack_Network\Utils\Requests;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers a node's question about what a reader holds across the rest of the
 * network: the hub's own data, plus each other node's answer.
 *
 * Nodes only share a key with the hub, so the hub is the one site that can ask
 * all of them.
 */
class Reader_Access_Endpoint {

	/**
	 * Seconds the hub waits for each node's answer.
	 */
	const NODE_TIMEOUT = 5;

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/reader-access',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_request' ],
					// The signed payload is verified in the callback, as the pull endpoint does.
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Answer a node's request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$verified_params = Requests::verify_request_to_hub( $request );
		if ( is_wp_error( $verified_params ) ) {
			return new WP_REST_Response( [ 'error' => $verified_params->get_error_message() ], 403 );
		}

		// The plaintext 'site' selected the key that verified the signature; the signed copy must agree.
		$site = $request['site'];
		if ( ( $verified_params['site'] ?? null ) !== $site ) {
			return new WP_REST_Response( [ 'error' => 'Site mismatch.' ], 403 );
		}

		$email      = sanitize_email( (string) ( $verified_params['email'] ?? '' ) );
		$request_id = (string) ( $verified_params['request_id'] ?? '' );
		if ( ! $email || ! $request_id ) {
			return new WP_REST_Response( [ 'error' => 'Bad request.' ], 400 );
		}

		$node   = Nodes::get_node_by_url( $site );
		$result = self::collect( $email, $node->get_id() );

		$envelope = Reader_Access_Envelope::seal(
			array_merge(
				$result,
				[
					'email'      => $email,
					'request_id' => $request_id,
				]
			),
			$node->get_secret_key()
		);
		if ( is_wp_error( $envelope ) ) {
			return new WP_REST_Response( [ 'error' => $envelope->get_error_message() ], 500 );
		}
		return new WP_REST_Response( $envelope );
	}

	/**
	 * What the reader holds on every network site except the one asking.
	 *
	 * @param string   $email             Reader email.
	 * @param int|null $asking_node_id    The node asking, or null when the hub asks for itself.
	 * @return array {
	 *     @type array    $sites        Site URL => what the reader holds there.
	 *     @type string[] $failed_sites Sites that didn't answer; what was stored for them should be kept.
	 * }
	 */
	public static function collect( $email, $asking_node_id = null ) {
		$sites  = [];
		$failed = [];

		if ( null !== $asking_node_id ) {
			$sites[ get_bloginfo( 'url' ) ] = Reader_Access_Collector::collect( $email );
		}

		foreach ( Nodes::get_all_nodes() as $node ) {
			if ( null !== $asking_node_id && (int) $node->get_id() === (int) $asking_node_id ) {
				continue;
			}
			$answer = self::ask_node( $node, $email );
			if ( is_wp_error( $answer ) ) {
				Debugger::log( sprintf( 'Reader access: no answer from %s: %s', $node->get_url(), $answer->get_error_message() ) );
				$failed[] = $node->get_url();
				continue;
			}
			$sites[ $node->get_url() ] = [
				'subscriptions' => (array) ( $answer['subscriptions'] ?? [] ),
				'groups'        => (array) ( $answer['groups'] ?? [] ),
				'orders'        => (array) ( $answer['orders'] ?? [] ),
			];
		}

		return [
			'sites'        => $sites,
			'failed_sites' => $failed,
		];
	}

	/**
	 * Ask one node what the reader holds there.
	 *
	 * @param Node   $node  Node.
	 * @param string $email Reader email.
	 * @return array|\WP_Error The node's answer.
	 */
	private static function ask_node( $node, $email ) {
		$headers = $node->get_authorization_headers( Node_Endpoint::ENDPOINT_ID );
		if ( ! is_array( $headers ) ) {
			return new \WP_Error( 'newspack_network_reader_access_sign', __( 'Could not sign the request.', 'newspack-network' ) );
		}
		$request_id = wp_generate_uuid4();
		$response   = wp_remote_post(
			$node->get_url() . '/wp-json/newspack-network/v1/reader-access',
			[
				'headers' => $headers,
				'body'    => [
					'email'      => $email,
					'request_id' => $request_id,
				],
				'timeout' => self::NODE_TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'newspack_network_reader_access_status', sprintf( 'HTTP %d', wp_remote_retrieve_response_code( $response ) ) );
		}
		return Reader_Access_Envelope::open( json_decode( wp_remote_retrieve_body( $response ), true ), $node->get_secret_key(), $email, $request_id );
	}
}
