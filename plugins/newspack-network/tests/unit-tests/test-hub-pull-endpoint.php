<?php
/**
 * Tests for the Hub pull endpoint.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Crypto;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Pull_Endpoint;
use Newspack_Network\Hub\Database\Event_Log as Event_Log_Database;

/**
 * Test the Hub pull endpoint.
 *
 * @group hub-pull-endpoint
 */
class TestHubPullEndpoint extends \WP_UnitTestCase {

	/**
	 * A registered Node URL the Hub resolves the pull against.
	 */
	const NODE_URL = 'https://pull-node.example.test';

	/**
	 * The shared secret the fixture Node signs with.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * The fixture Node's post ID.
	 *
	 * @var int
	 */
	private $node_id;

	/**
	 * Create the event log table once, before the per-test transaction.
	 *
	 * The table is created lazily via dbDelta, and DDL implicitly commits the open
	 * transaction, so it is triggered here, outside any test's transaction.
	 *
	 * @param \WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		Event_Log_Database::get_table_name();
	}

	/**
	 * Register a fixture Node on the Hub.
	 */
	public function set_up() {
		parent::set_up();

		$this->secret_key = Crypto::generate_secret_key();

		$this->node_id = self::factory()->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_title'  => 'Pull Node',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->node_id, 'node-url', self::NODE_URL );
		update_post_meta( $this->node_id, 'secret-key', $this->secret_key );
	}

	/**
	 * Build a pull request the way a Node would: the parameters are signed with
	 * the given key under a fresh nonce, and sent alongside the plaintext copies.
	 *
	 * @param string $signing_key The key the request is signed with.
	 * @return \WP_REST_Request
	 */
	private function build_request( $signing_key ) {
		$params = [
			'site'              => self::NODE_URL,
			'last_processed_id' => 0,
			'actions'           => [ 'network_hub_name_updated' ],
		];
		$nonce  = Crypto::generate_nonce();

		$request = new \WP_REST_Request( 'POST', '/newspack-network/v1/pull' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_param( 'nonce', $nonce );
		$request->set_param( 'signature', Crypto::encrypt_message( wp_json_encode( $params ), $signing_key, $nonce ) );
		return $request;
	}

	/**
	 * A verified pull from a Node with no linking on record records one, so a
	 * Node that pulls but rarely pushes stops showing its key.
	 */
	public function test_verified_pull_records_linking() {
		$this->assertSame( '', get_post_meta( $this->node_id, 'paired-at', true ) );

		$before   = time();
		$response = Pull_Endpoint::handle_pull( $this->build_request( $this->secret_key ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThanOrEqual( $before, (int) get_post_meta( $this->node_id, 'paired-at', true ) );
	}

	/**
	 * A pull signed with a different key fails verification and records no linking.
	 */
	public function test_unverified_pull_does_not_record_linking() {
		$response = Pull_Endpoint::handle_pull( $this->build_request( Crypto::generate_secret_key() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_post_meta( $this->node_id, 'paired-at', true ) );
	}
}
