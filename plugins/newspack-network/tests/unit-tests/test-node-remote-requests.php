<?php
/**
 * Class TestNodeRemoteRequests
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Admin\Membership_Plans;
use Newspack_Network\Hub\Admin\Nodes_List;
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
	 * Entries reported through the newspack_log action, as [ code, data ].
	 *
	 * @var array
	 */
	private $logged = [];

	/**
	 * Intercept outgoing requests so nothing leaves the test run, and record what is logged.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests = [];
		$this->logged   = [];
		add_filter( 'pre_http_request', [ $this, 'intercept' ], 10, 3 );
		add_action( 'newspack_log', [ $this, 'record_log' ], 10, 3 );
	}

	/**
	 * Remove the intercept and the log recorder.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'intercept' ], 10 );
		remove_action( 'newspack_log', [ $this, 'record_log' ], 10 );
		parent::tear_down();
	}

	/**
	 * Record an entry reported through the newspack_log action.
	 *
	 * @param string $code    Log code.
	 * @param string $message Message.
	 * @param array  $params  Type, data and the other fields the Logger adds.
	 */
	public function record_log( $code, $message, $params ) {
		$this->logged[] = [
			'code' => $code,
			'data' => $params['data'] ?? null,
		];
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
	 * Site info is not requested from an internal address, and the refusal is logged.
	 */
	public function test_site_info_skips_internal_node_url() {
		$this->make_node( 'http://100.64.0.1' )->get_site_info();
		$this->assertSame( [], $this->requests );
		$this->assertSame(
			[
				'host' => '100.64.0.1',
				'port' => 80,
			],
			$this->only_refusal_logged()['data']
		);
	}

	/**
	 * Collection data is not requested from an internal address.
	 */
	public function test_collection_fetch_skips_internal_node_url() {
		$this->assertNull( Membership_Plans::fetch_collection_from_api( $this->make_node( 'http://100.64.0.1' ), 'wc/v2/memberships/plans', 'membership-plans' ) );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * A redirect core refuses on its own is still logged as a refusal. Production matches
	 * core's generic message, and this test builds its error from the same string, so it
	 * cannot catch core rewording it.
	 */
	public function test_redirect_refused_by_core_is_logged() {
		$refuse = function () {
			return new WP_Error( 'http_request_failed', __( 'A valid URL was not provided.' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string.
		};
		add_filter( 'pre_http_request', $refuse, 11 );
		$this->make_node( 'http://93.184.216.34' )->get_site_info();
		remove_filter( 'pre_http_request', $refuse, 11 );

		$this->assertSame(
			[
				'host' => '93.184.216.34',
				'port' => 80,
			],
			$this->only_refusal_logged()['data']
		);
	}

	/**
	 * A redirect the plugin's guard refuses is logged as a refusal. The intercept turns
	 * the guard's exception into a WP_Error, as WP_Http does for a real redirect.
	 */
	public function test_redirect_refused_by_guard_is_logged() {
		$redirect = function () {
			try {
				Network::assert_safe_redirect( 'http://10.0.0.5' );
			} catch ( Exception $e ) {
				return new WP_Error( 'http_request_failed', $e->getMessage() );
			}
			return new WP_Error( 'http_request_failed', 'The guard let the redirect through.' );
		};
		add_filter( 'pre_http_request', $redirect, 11 );
		$this->make_node( 'http://93.184.216.34' )->get_site_info();
		remove_filter( 'pre_http_request', $redirect, 11 );

		$this->assertSame(
			[
				'host' => '93.184.216.34',
				'port' => 80,
			],
			$this->only_refusal_logged()['data']
		);
	}

	/**
	 * Return the one peer-request refusal logged so far, failing unless there is exactly one.
	 *
	 * @return array
	 */
	private function only_refusal_logged() {
		$refusals = array_values(
			array_filter(
				$this->logged,
				function ( $entry ) {
					return 'newspack_network_peer_request' === $entry['code'];
				}
			)
		);
		$this->assertCount( 1, $refusals );
		return $refusals[0];
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
	 * A request made while the guard is already registered leaves it registered, so a
	 * request nested inside another cannot strip the outer request's guard.
	 */
	public function test_request_leaves_a_guard_it_did_not_register() {
		add_action( 'requests-requests.before_redirect', [ Network::class, 'assert_safe_redirect' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->make_node( 'http://93.184.216.34' )->get_site_info();
		$registered = has_action(
			'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			[ Network::class, 'assert_safe_redirect' ]
		);
		remove_action( 'requests-requests.before_redirect', [ Network::class, 'assert_safe_redirect' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertCount( 1, $this->requests );
		$this->assertNotFalse( $registered );
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

	/**
	 * A Node whose site info comes back empty is requested once per page, not once per column.
	 */
	public function test_nodes_list_requests_unavailable_site_info_once() {
		$empty_body = function ( $response ) {
			$response['body'] = '';
			return $response;
		};
		add_filter( 'pre_http_request', $empty_body, 11 );

		$node_id = $this->make_node( 'http://93.184.216.34' )->get_id();
		ob_start();
		Nodes_List::posts_columns_values( 'links', $node_id );
		Nodes_List::posts_columns_values( 'links', $node_id );
		ob_end_clean();

		remove_filter( 'pre_http_request', $empty_body, 11 );
		$this->assertCount( 1, $this->requests );
	}
}
