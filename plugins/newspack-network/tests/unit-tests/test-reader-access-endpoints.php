<?php
/**
 * Class TestReaderAccessEndpoints
 *
 * @package Newspack_Network
 */

use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Crypto;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Reader_Access_Endpoint as Hub_Endpoint;
use Newspack_Network\Node\Reader_Access_Endpoint as Node_Endpoint;
use Newspack_Network\Rest_Authenticaton;
use Newspack_Network\Site_Role;
use Newspack_Network\Utils\Requests;

/**
 * How network sites ask each other, through the hub, what a reader holds.
 */
class TestReaderAccessEndpoints extends WP_UnitTestCase {

	const EMAIL = 'reader@example.test';

	/**
	 * Node URL => secret key.
	 *
	 * @var array
	 */
	private $nodes = [];

	/**
	 * URLs the hub requested during a test.
	 *
	 * @var string[]
	 */
	private $requested_urls = [];

	/**
	 * Register three nodes on a hub.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );
		foreach ( [ 'https://asking.example.test', 'https://answering.example.test', 'https://down.example.test' ] as $url ) {
			$secret  = Crypto::generate_secret_key();
			$node_id = self::factory()->post->create(
				[
					'post_type'   => Nodes::POST_TYPE_SLUG,
					'post_status' => 'publish',
				]
			);
			update_post_meta( $node_id, 'node-url', $url );
			update_post_meta( $node_id, 'secret-key', $secret );
			$this->nodes[ $url ] = $secret;
		}
		$this->requested_urls = [];
	}

	/**
	 * Clean up options and filters.
	 */
	public function tear_down() {
		delete_option( Site_Role::OPTION_NAME );
		delete_option( 'newspack_node_secret_key' );
		parent::tear_down();
	}

	/**
	 * A request to the node endpoint, optionally signed.
	 *
	 * @param string|null $secret Key to sign with, or null for an unsigned request.
	 * @return WP_REST_Request
	 */
	private function node_request( $secret ) {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/reader-access' );
		$request->set_param( 'email', self::EMAIL );
		$request->set_param( 'request_id', 'request-1' );
		if ( $secret ) {
			foreach ( Rest_Authenticaton::generate_signature_headers( Node_Endpoint::ENDPOINT_ID, $secret ) as $name => $value ) {
				$request->set_header( $name, $value );
			}
		}
		return $request;
	}

	/**
	 * A request to the hub endpoint signed by the asking node.
	 *
	 * @param string $request_id Request ID.
	 * @return WP_REST_Request
	 */
	private function hub_request( $request_id ) {
		update_option( 'newspack_node_secret_key', $this->nodes['https://asking.example.test'] );
		$params = Requests::sign_params(
			[
				'site'       => 'https://asking.example.test',
				'email'      => self::EMAIL,
				'request_id' => $request_id,
			]
		);
		delete_option( 'newspack_node_secret_key' );
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/reader-access' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Answer the hub's calls to nodes: the answering node reports a subscription,
	 * the down node errors.
	 *
	 * @param string|null $answer_request_id Request ID the answering node echoes; null echoes the one it was sent.
	 */
	private function mock_nodes( $answer_request_id = null ) {
		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) use ( $answer_request_id ) {
				$this->requested_urls[] = $url;
				if ( 0 === strpos( $url, 'https://answering.example.test/' ) ) {
					$envelope = Reader_Access_Envelope::seal(
						[
							'email'         => $args['body']['email'],
							'request_id'    => $answer_request_id ?? $args['body']['request_id'],
							'subscriptions' => [
								42 => [
									'id'       => 42,
									'status'   => 'active',
									'products' => [],
								],
							],
							'groups'        => [],
							'orders'        => [],
						],
						$this->nodes['https://answering.example.test']
					);
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( $envelope ),
					];
				}
				return [
					'response' => [ 'code' => 500 ],
					'body'     => '',
				];
			},
			10,
			3
		);
	}

	/**
	 * The node only answers requests signed with its hub's key.
	 */
	public function test_node_endpoint_requires_hub_signature() {
		$secret = Crypto::generate_secret_key();
		update_option( 'newspack_node_secret_key', $secret );

		$this->assertInstanceOf( WP_Error::class, Node_Endpoint::check_permission( $this->node_request( null ) ) );
		$this->assertInstanceOf( WP_Error::class, Node_Endpoint::check_permission( $this->node_request( Crypto::generate_secret_key() ) ) );
		$this->assertTrue( Node_Endpoint::check_permission( $this->node_request( $secret ) ) );
	}

	/**
	 * The node's answer is encrypted with the shared key and names the reader and
	 * request it answers, so it can't be forged or replayed for another reader.
	 */
	public function test_node_answer_is_sealed_and_bound_to_request() {
		$secret = Crypto::generate_secret_key();
		update_option( 'newspack_node_secret_key', $secret );

		$envelope = Node_Endpoint::handle_request( $this->node_request( $secret ) )->get_data();

		$payload = Reader_Access_Envelope::open( $envelope, $secret, self::EMAIL, 'request-1' );
		$this->assertIsArray( $payload );
		$this->assertSame( [], $payload['subscriptions'] );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, $secret, self::EMAIL, 'request-2' ) );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, $secret, 'someone@example.test', 'request-1' ) );
		$this->assertInstanceOf( WP_Error::class, Reader_Access_Envelope::open( $envelope, Crypto::generate_secret_key(), self::EMAIL, 'request-1' ) );
	}

	/**
	 * The hub refuses a request no node signed.
	 */
	public function test_hub_endpoint_rejects_unsigned_request() {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/reader-access' );
		$request->set_param( 'site', 'https://asking.example.test' );
		$request->set_param( 'email', self::EMAIL );
		$request->set_param( 'request_id', 'request-1' );

		$this->assertSame( 403, Hub_Endpoint::handle_request( $request )->get_status() );
	}

	/**
	 * The hub answers for itself and every other node, never asks the node that asked,
	 * reports the nodes that didn't answer, and seals the answer for the asking node.
	 */
	public function test_hub_answers_for_itself_and_other_nodes() {
		$this->mock_nodes();

		$envelope = Hub_Endpoint::handle_request( $this->hub_request( 'request-1' ) )->get_data();
		$payload  = Reader_Access_Envelope::open( $envelope, $this->nodes['https://asking.example.test'], self::EMAIL, 'request-1' );

		$this->assertIsArray( $payload );
		$this->assertEqualsCanonicalizing( [ get_bloginfo( 'url' ), 'https://answering.example.test' ], array_keys( $payload['sites'] ) );
		$this->assertSame( 'active', $payload['sites']['https://answering.example.test']['subscriptions'][42]['status'] );
		$this->assertSame( [ 'https://down.example.test' ], $payload['failed_sites'] );
		$this->assertEmpty( preg_grep( '#^https://asking\.example\.test#', $this->requested_urls ) );
	}

	/**
	 * A node answer that doesn't name the request the hub sent is treated as no answer.
	 */
	public function test_hub_discards_node_answer_for_another_request() {
		$this->mock_nodes( 'stale-request' );

		$result = Hub_Endpoint::collect( self::EMAIL );

		$this->assertArrayNotHasKey( 'https://answering.example.test', $result['sites'] );
		$this->assertContains( 'https://answering.example.test', $result['failed_sites'] );
	}

	/**
	 * When the hub itself is the site being read, it asks every node and leaves its own data out.
	 */
	public function test_hub_reading_for_itself_asks_every_node() {
		$this->mock_nodes();

		$result = Hub_Endpoint::collect( self::EMAIL );

		$this->assertSame( [ 'https://answering.example.test' ], array_keys( $result['sites'] ) );
		$this->assertEqualsCanonicalizing( [ 'https://asking.example.test', 'https://down.example.test' ], $result['failed_sites'] );
	}
}
