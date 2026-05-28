<?php
/**
 * Tests the Webhooks functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;

/**
 * Tests the Webhooks functionality.
 */
class Newspack_Test_Webhooks extends WP_UnitTestCase {
	/**
	 * Action endpoint ID
	 *
	 * @var int
	 */
	private $action_endpoint;

	/**
	 * Missing action endpoint ID
	 *
	 * @var int
	 */
	private $missing_action_endpoint;

	/**
	 * All endpoints.
	 *
	 * @var int[]
	 */
	private $endpoints = [];

	/**
	 * Set up.
	 */
	public function set_up() {
		// Delete all lingering endpoints and requests before starting tests.
		$endpoints = Data_Events\Webhooks::get_endpoints();
		foreach ( $endpoints as $endpoint ) {
			Data_Events\Webhooks::delete_endpoint( $endpoint['id'] );
		}
		$requests = get_posts(
			[
				'post_type'      => Data_Events\Webhooks::REQUEST_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			]
		);
		foreach ( $requests as $request ) {
			wp_delete_post( $request->ID, true );
		}

		// Create sample endpoints.
		$this->action_endpoint         = Data_Events\Webhooks::create_endpoint(
			'https://example.com/webhook/test_action',
			[ 'test_action' ]
		)['id'];
		$this->missing_action_endpoint = Data_Events\Webhooks::create_endpoint(
			'https://example.com/webhook/missing_action',
			[ 'missing_action' ]
		)['id'];
		$this->endpoints               = [
			$this->action_endpoint,
			$this->missing_action_endpoint,
		];
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		// Remove all endpoints.
		$terms = get_terms(
			[
				'taxonomy'   => Data_Events\Webhooks::ENDPOINT_TAXONOMY,
				'hide_empty' => false,
			]
		);
		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, Data_Events\Webhooks::ENDPOINT_TAXONOMY );
		}
	}

	/**
	 * Dispatch a sample data event.
	 *
	 * @param string $action_name Action name.
	 * @param array  $data Data.
	 */
	private function dispatch_event( $action_name = 'test_action', $data = [ 'test' => 'data' ] ) {
		Data_Events::register_action( $action_name );
		Data_Events::dispatch( $action_name, $data );
	}

	/**
	 * Test creating an endpoint.
	 */
	public function test_create_endpoint() {
		$id        = $this->action_endpoint;
		$endpoints = Data_Events\Webhooks::get_endpoints();
		foreach ( $endpoints as $endpoint ) {
			if ( $endpoint['id'] === $id ) {
				$this->assertEquals( 'https://example.com/webhook/test_action', $endpoint['url'] );
				$this->assertEquals( [ 'test_action' ], $endpoint['actions'] );
				$this->assertEquals( false, $endpoint['disabled'] );
			}
		}
	}

	/**
	 * Test create endpoint with empty actions.
	 */
	public function test_create_endpoint_empty_actions() {
		$result = Data_Events\Webhooks::create_endpoint( 'https://example.com/webhook/empty_actions', [] );
		$this->assertTrue( is_wp_error( $result ) );
	}

	/**
	 * Test updating an endpoint label.
	 */
	public function test_update_endpoint_label() {
		$id = $this->action_endpoint;
		Data_Events\Webhooks::update_endpoint_label( $id, 'Test Label' );
		$endpoints = Data_Events\Webhooks::get_endpoints();
		foreach ( $endpoints as $endpoint ) {
			if ( $endpoint['id'] === $id ) {
				$this->assertEquals( 'Test Label', $endpoint['label'] );
			}
		}
	}

	/**
	 * Test deleting an endpoint.
	 */
	public function test_delete_endpoint() {
		foreach ( $this->endpoints as $endpoint ) {
			Data_Events\Webhooks::delete_endpoint( $endpoint );
		}
		$endpoints = Data_Events\Webhooks::get_endpoints();
		$this->assertEquals( 0, count( $endpoints ) );
	}

	/**
	 * Test disabling an endpoint.
	 */
	public function test_disable_endpoint() {
		Data_Events\Webhooks::disable_endpoint( $this->action_endpoint );
		$endpoint = Data_Events\Webhooks::get_endpoint( $this->action_endpoint );
		$this->assertEquals( true, $endpoint['disabled'] );
	}

	/**
	 * Test that dispatching an action creates webhook requests for an action
	 * endpoint.
	 */
	public function test_action_endpoint() {
		$this->dispatch_event();
		$requests = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint );
		$this->assertEquals( 1, count( $requests ) );
	}

	/**
	 * Test that dispatching an action does not create requests for an endpoint not
	 * configured for that action.
	 */
	public function test_missing_action_endpoint() {
		$this->dispatch_event();
		$requests = Data_Events\Webhooks::get_endpoint_requests( $this->missing_action_endpoint );
		$this->assertEquals( 0, count( $requests ) );
	}

	/**
	 * Test that a disabled endpoint doesn't create webhook requests.
	 */
	public function test_disabled_endpoints() {
		Data_Events\Webhooks::disable_endpoint( $this->action_endpoint );
		$this->dispatch_event();
		$requests = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint );
		$this->assertEquals( 0, count( $requests ) );
	}

	/**
	 * Test request scheduling.
	 */
	public function test_request_scheduling() {
		add_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
		$this->dispatch_event();
		$requests = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint );
		$post     = get_post( $requests[0]['id'] );
		$this->assertEquals( 'future', $post->post_status );
		$this->assertGreaterThan( time(), strtotime( $post->post_date ) );
		remove_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
	}

	/**
	 * Test that publishing a request processes it.
	 */
	public function test_request_publish() {
		add_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
		$this->dispatch_event();
		$http_args = [];
		$http_url  = '';
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) use ( &$http_args, &$http_url ) {
				$http_args = $args;
				$http_url  = $url;
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
				];
			},
			10,
			3
		);
		$request_id = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint )[0]['id'];
		wp_publish_post( $request_id );
		$this->assertEquals( 'https://example.com/webhook/test_action', $http_url );
		$this->assertEquals( 'POST', $http_args['method'] );
		$this->assertEquals( 'application/json', $http_args['headers']['Content-Type'] );
		$this->assertEquals( get_post_meta( $request_id, 'body', true ), $http_args['body'] );
		remove_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
	}

	/**
	 * Test that a failed send re-schedules the request.
	 */
	public function test_request_error_handling() {
		add_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
		$this->dispatch_event();
		add_filter(
			'pre_http_request',
			function() {
				return [
					'response' => [
						'code'    => 500,
						'message' => 'Internal Server Error',
					],
				];
			}
		);
		$request_id = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint )[0]['id'];
		wp_publish_post( $request_id );
		$this->assertEquals( 'future', get_post_status( $request_id ) );
		$this->assertGreaterThan( time(), strtotime( get_post( $request_id )->post_date ) );
		remove_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
	}

	/**
	 * Test reaching max retries should kill the request and disable the endpoint.
	 */
	public function test_request_max_retries() {
		add_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
		$this->dispatch_event();

		$max_retries = Data_Events\Webhooks::MAX_RETRIES;
		$retries     = 0;

		add_filter(
			'pre_http_request',
			function() {
				return [
					'response' => [
						'code'    => 500,
						'message' => 'Internal Server Error',
					],
				];
			}
		);

		$request_id = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint )[0]['id'];

		while ( $retries <= $max_retries ) {
			wp_publish_post( $request_id );
			$status = 'future';
			if ( $max_retries === $retries ) {
				$status = 'trash';
			}
			$this->assertEquals( $status, get_post_status( $request_id ) );
			$retries++;
		}

		$endpoint = Data_Events\Webhooks::get_endpoint( $this->action_endpoint );
		$this->assertTrue( $endpoint['disabled'] );
		remove_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
	}

	/**
	 * Test that finished requests older than 7 days should be deleted.
	 */
	public function test_finished_request_deletion() {
		add_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );

		$dispatches_count = 10;

		for ( $i = 0; $i < $dispatches_count; $i++ ) {
			$this->dispatch_event();
		}

		$created_requests = get_posts(
			[
				'post_type'      => Data_Events\Webhooks::REQUEST_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		add_filter(
			'pre_http_request',
			function() {
				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
				];
			}
		);

		// Manually publish each request and pretend half happened 10 days ago.
		foreach ( $created_requests as $i => $request_id ) {
			$date = gmdate( 'Y-m-d H:i:s', $i % 2 ? strtotime( '10 days ago' ) : time() );
			wp_update_post(
				[
					'ID'            => $request_id,
					'post_status'   => 'publish',
					'post_date'     => $date,
					'post_date_gmt' => $date,
					'edit_date'     => true,
				]
			);
		}

		Data_Events\Webhooks::clear_finished();

		$requests = get_posts(
			[
				'post_type'      => Data_Events\Webhooks::REQUEST_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		// Half should've been deleted.
		$this->assertEquals( count( $created_requests ) / 2, count( $requests ) );
		remove_filter( 'newspack_data_events_use_action_scheduler', '__return_false' );
	}

	/**
	 * Tests creating and getting system endpoints.
	 */
	public function test_system_endpoint() {
		Data_Events\Webhooks::register_system_endpoint( 'test', 'https://example.com/test' );
		$endpoints = Data_Events\Webhooks::get_endpoints();
		$this->assertSame( 3, count( $endpoints ) );
		$this->assertSame( 'test', $endpoints[2]['id'] );
		$this->assertTrue( $endpoints[2]['system'] );
		$this->assertFalse( $endpoints[1]['system'] );

		$ep = Data_Events\Webhooks::get_endpoint( 'test' );
		$this->assertSame( 'test', $ep['id'] );
		$this->assertSame( 'https://example.com/test', $ep['url'] );
	}

	/**
	 * Tests that getting endpoint requests fetch requests for system endpoints.
	 */
	public function test_get_endpoint_requests() {
		Data_Events\Webhooks::register_system_endpoint( 'test-2', 'https://example2.com/test', [ 'test_action' ] );
		$this->dispatch_event();

		$requests = Data_Events\Webhooks::get_endpoint_requests( $this->action_endpoint );
		$this->assertSame( 1, count( $requests ) );

		$requests = Data_Events\Webhooks::get_endpoint_requests( 'test-2' );
		$this->assertSame( 1, count( $requests ) );
	}

	/**
	 * Data provider for test_register_system_endpoints_throws
	 *
	 * @return array
	 */
	public function register_system_endpoints_throws_data() {
		return [
			[
				'*j30s',
			],
			[
				'asd$',
			],
			[
				'asd@',
			],
			[
				123,
			],
			[
				'123',
			],
			[
				[ 'array' ],
			],
			[
				'asd_asd',
				false,
			],
			[
				'asd_asd-123',
				false,
			],
			[
				'asd-123',
				false,
			],
			[
				'asd-123', // repeated.
				true,
			],
		];
	}

	/**
	 * Test if register_system_endpoints throws on invalid IDs
	 *
	 * @param mixed   $id The ID to be tested.
	 * @param boolean $should_throw Whether the test should throw an exception.
	 * @return void
	 * @dataProvider register_system_endpoints_throws_data
	 */
	public function test_register_system_endpoints_throws( $id, $should_throw = true ) {
		if ( $should_throw ) {
			$this->expectException( 'InvalidArgumentException' );
		}
		$this->assertNull( Data_Events\Webhooks::register_system_endpoint( $id, 'https://example.com/test' ) );
	}
}
