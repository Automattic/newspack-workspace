<?php
/**
 * Class TestNodeRemoteRequests
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Admin\Membership_Plans;
use Newspack_Network\Utils\Network;

/**
 * Requests the Hub makes to a Node's stored URL.
 */
class TestNodeRemoteRequests extends WP_UnitTestCase {

	/**
	 * Requests seen by the pre_http_request intercept, as [ url, args ].
	 *
	 * @var array
	 */
	private $requests = [];

	/**
	 * Intercept outgoing requests so nothing leaves the test run.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests = [];
		add_filter( 'pre_http_request', [ $this, 'intercept' ], 10, 3 );
	}

	/**
	 * Remove the intercept.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'intercept' ], 10 );
		parent::tear_down();
	}

	/**
	 * Record the request and answer it with an empty JSON object.
	 *
	 * @param false|array $preempt Preempt value.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 * @return array
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = [ $url, $args ];
		return [
			'headers'  => [],
			'body'     => '{}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
		];
	}

	/**
	 * Create a Node whose stored URL is $url.
	 *
	 * @param string $url Node URL.
	 * @return Node
	 */
	private function make_node( $url ) {
		$post_id = $this->factory->post->create( [ 'post_type' => Nodes::POST_TYPE_SLUG ] );
		add_post_meta( $post_id, 'node-url', $url );
		add_post_meta( $post_id, 'secret-key', 'secret-key' );
		return new Node( $post_id );
	}

	/**
	 * Node URLs in ranges the Hub should not contact.
	 */
	public function internal_url_provider() {
		return [
			'link-local' => [ 'http://169.254.169.254' ],
			'loopback'   => [ 'http://127.0.0.1' ],
			'private'    => [ 'http://10.0.0.5' ],
			'cgnat'      => [ 'http://100.64.0.1' ],
		];
	}

	/**
	 * Site info is not requested from an internal address.
	 *
	 * @dataProvider internal_url_provider
	 * @param string $url Node URL.
	 */
	public function test_site_info_skips_internal_node_url( $url ) {
		$this->make_node( $url )->get_site_info();
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Collection data is not requested from an internal address.
	 *
	 * @dataProvider internal_url_provider
	 * @param string $url Node URL.
	 */
	public function test_collection_fetch_skips_internal_node_url( $url ) {
		$this->assertNull( Membership_Plans::fetch_collection_from_api( $this->make_node( $url ), 'wc/v2/memberships/plans', 'membership-plans' ) );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Redirect hops are checked by the plugin's own guard while the request runs, and
	 * the guard is removed afterwards. Core's redirect check misses ranges the plugin
	 * blocks, so the intercept records whether the hook is live at request time.
	 */
	public function test_redirect_guard_is_registered_only_during_the_request() {
		$registered = null;
		$probe      = function ( $preempt ) use ( &$registered ) {
			$registered = has_action(
				'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				[ Network::class, 'assert_safe_redirect' ]
			);
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe, 5 );
		$this->make_node( 'http://93.184.216.34' )->get_site_info();
		remove_filter( 'pre_http_request', $probe, 5 );

		$this->assertNotNull( $registered, 'The request must reach the HTTP layer, or this test asserts nothing.' );
		$this->assertNotFalse( $registered );
		$this->assertFalse(
			has_action(
				'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				[ Network::class, 'assert_safe_redirect' ]
			)
		);
	}

	/**
	 * A public node URL is still requested, with core's unsafe-URL checks on.
	 */
	public function test_site_info_requests_public_node_url() {
		$this->make_node( 'http://93.184.216.34' )->get_site_info();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'http://93.184.216.34/wp-json/newspack-network/v1/info', $this->requests[0][0] );
		$this->assertTrue( $this->requests[0][1]['reject_unsafe_urls'] );
	}

	/**
	 * A public node URL is still requested for collection data.
	 */
	public function test_collection_fetch_requests_public_node_url() {
		Membership_Plans::fetch_collection_from_api( $this->make_node( 'http://93.184.216.34' ), 'wc/v2/memberships/plans', 'membership-plans' );
		$this->assertCount( 1, $this->requests );
		$this->assertTrue( $this->requests[0][1]['reject_unsafe_urls'] );
	}
}
