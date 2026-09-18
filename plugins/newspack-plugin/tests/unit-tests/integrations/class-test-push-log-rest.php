<?php
/**
 * Tests for the push log REST routes of the Integrations wizard.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Audience_Integrations;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Push_Log;

require_once __DIR__ . '/class-sample-integration.php';

/**
 * Push log REST test case.
 *
 * The wizard's constructor registers nothing unless the Integrations screen
 * is enabled, so the callbacks are called directly, as the other wizard
 * tests do.
 *
 * @group integrations
 * @group push-log
 */
class Test_Push_Log_Rest extends \WP_UnitTestCase {

	const ROUTE_BASE = '/newspack/v1/wizard/newspack-audience-integrations/settings';

	/**
	 * Set up the test environment before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		Integrations::register( new \Sample_Integration( 'sample', 'Sample' ) );
	}

	/**
	 * Tear down the test environment after each test.
	 */
	public function tear_down() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );
		}
		$this->reset_integrations();
		Integrations::register_integrations();
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Reset the static integrations registry so each test starts clean.
	 */
	private function reset_integrations() {
		$property = ( new \ReflectionClass( Integrations::class ) )->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Record an attempt, overriding only what the test is about.
	 *
	 * @param array $overrides Arguments to override.
	 * @return int The row ID.
	 */
	private function record( array $overrides = [] ): int {
		return Push_Log::record_attempt(
			array_merge(
				[
					'integration_id' => 'sample',
					'email'          => 'reader@example.test',
					'user_id'        => 7,
					'operation'      => Push_Log::OPERATION_UPSERT,
					'context'        => 'Test context',
					'payload'        => [
						'email'    => 'reader@example.test',
						'name'     => 'Sample Reader',
						'metadata' => [ 'NP_Total Paid' => '120' ],
					],
					'hash_prefix'    => 'NP_',
					'result'         => true,
					'attempts'       => 1,
					'max_attempts'   => 6,
				],
				$overrides
			)
		);
	}

	/**
	 * A request carrying the parameters the route would have defaulted.
	 *
	 * @param array $params Parameters to override.
	 * @return \WP_REST_Request
	 */
	private function list_request( array $params = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET' );
		$params  = array_merge(
			[
				'integration_id'  => 'sample',
				'search'          => '',
				'status'          => '',
				'operation'       => '',
				'needs_attention' => false,
				'page'            => 1,
				'per_page'        => 25,
				'order'           => 'DESC',
			],
			$params
		);
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return $request;
	}

	/**
	 * Both routes sit behind the wizard's capability check.
	 */
	public function test_routes_are_registered_behind_the_wizard_permission_check() {
		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', [ new Audience_Integrations(), 'register_api_endpoints' ] );
		$server = rest_get_server();

		$routes = $server->get_routes( NEWSPACK_API_NAMESPACE );
		$this->assertArrayHasKey( self::ROUTE_BASE . '/(?P<integration_id>[a-zA-Z0-9_-]+)/push-log', $routes );
		$this->assertArrayHasKey( self::ROUTE_BASE . '/(?P<integration_id>[a-zA-Z0-9_-]+)/push-log/(?P<id>[0-9]+)', $routes );

		wp_set_current_user( 0 );
		$this->assertSame( 403, $server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE_BASE . '/sample/push-log' ) )->get_status() );
		$this->assertSame( 403, $server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE_BASE . '/sample/push-log/1' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->record();
		$response = $server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE_BASE . '/sample/push-log' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( 25, $response->get_data()['per_page'] );
	}

	/**
	 * An integration that is not registered has no log to read.
	 */
	public function test_an_unknown_integration_is_a_404() {
		$wizard = new Audience_Integrations();

		$list = $wizard->api_get_push_log( $this->list_request( [ 'integration_id' => 'missing' ] ) );
		$this->assertWPError( $list );
		$this->assertSame( 404, $list->get_error_data()['status'] );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'integration_id', 'missing' );
		$request->set_param( 'id', 1 );
		$this->assertSame( 404, $wizard->api_get_push_log_entry( $request )->get_error_data()['status'] );
	}

	/**
	 * The list carries what the table shows and leaves the payload out.
	 */
	public function test_list_returns_items_without_payloads() {
		$row_id = $this->record();
		$this->record(
			[
				'email'  => 'other@example.test',
				'result' => new \WP_Error( 'provider_down', 'ESP 503' ),
			]
		);

		$data = ( new Audience_Integrations() )->api_get_push_log( $this->list_request( [ 'status' => 'success' ] ) )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 25, $data['per_page'] );
		// The screen names the windows when it finds nothing, and a site can
		// filter them, so they travel with the list rather than being repeated
		// in the JavaScript.
		$this->assertSame( Push_Log::get_retention_days(), $data['retention_days'] );
		$item = $data['items'][0];
		$this->assertSame( $row_id, $item['id'] );
		$this->assertSame( 'reader@example.test', $item['email'] );
		$this->assertNull( $item['retry'] );
		$this->assertArrayNotHasKey( 'payload', $item );
		$this->assertArrayNotHasKey( 'retry_action_id', $item );
		$this->assertArrayNotHasKey( 'integration_id', $item );
	}

	/**
	 * A log that cannot be read is a failure, not an empty list: the screen
	 * would otherwise say nothing was ever sent.
	 */
	public function test_a_log_that_cannot_be_read_is_an_error() {
		$break_reads = function ( $query ) {
			return str_replace( Push_Log::get_table_name(), 'table_that_does_not_exist', $query );
		};
		add_filter( 'query', $break_reads );

		$response = ( new Audience_Integrations() )->api_get_push_log( $this->list_request() );

		remove_filter( 'query', $break_reads );

		$this->assertWPError( $response );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'table_that_does_not_exist', $response->get_error_message() );
	}

	/**
	 * A retrying row says whether its retry is still there to run, and when.
	 */
	public function test_a_retrying_row_reports_its_pending_retry() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		$row_id    = $this->record( [ 'result' => new \WP_Error( 'provider_down', 'ESP 503' ) ] );
		$run_at    = time() + 120;
		$action_id = \as_schedule_single_action( $run_at, Contact_Sync::RETRY_HOOK, [ [ 'log_id' => $row_id ] ], Integrations::get_action_group( 'sample' ) );
		Push_Log::mark_retrying( $row_id, $action_id );

		$wizard = new Audience_Integrations();
		$item   = $wizard->api_get_push_log( $this->list_request() )->get_data()['items'][0];

		$this->assertSame(
			[
				'action_id'    => $action_id,
				'is_pending'   => true,
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $run_at ),
			],
			$item['retry']
		);

		\as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );
		$item = $wizard->api_get_push_log( $this->list_request() )->get_data()['items'][0];
		$this->assertFalse( $item['retry']['is_pending'] );
		$this->assertNull( $item['retry']['scheduled_at'] );
	}

	/**
	 * Opening an entry returns it with what it was compared with and the
	 * fields, named without the integration's prefix.
	 */
	public function test_entry_returns_the_field_comparison() {
		$first  = $this->record();
		$latest = $this->record(
			[
				'payload' => [
					'email'    => 'reader@example.test',
					'name'     => 'Sample Reader',
					'metadata' => [ 'NP_Total Paid' => '180' ],
				],
			]
		);

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'integration_id', 'sample' );
		$request->set_param( 'id', $latest );
		$data = ( new Audience_Integrations() )->api_get_push_log_entry( $request )->get_data();

		$this->assertSame( $latest, $data['entry']['id'] );
		$this->assertArrayNotHasKey( 'payload', $data['entry'] );
		$this->assertSame( $first, $data['compared_to']['id'] );
		$this->assertArrayHasKey( 'updated_at', $data['compared_to'] );
		$this->assertSame( 'Total Paid', $data['fields'][0]['label'] );
		$this->assertSame( '120', $data['fields'][0]['before'] );
		$this->assertSame( '180', $data['fields'][0]['after'] );

		$request->set_param( 'id', $first );
		$this->assertNull( ( new Audience_Integrations() )->api_get_push_log_entry( $request )->get_data()['compared_to'] );
	}

	/**
	 * A row that is gone, or that belongs to another integration, is a 404.
	 */
	public function test_an_entry_of_another_integration_is_a_404() {
		$row_id = $this->record( [ 'integration_id' => 'other' ] );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'integration_id', 'sample' );
		$request->set_param( 'id', $row_id );
		$response = ( new Audience_Integrations() )->api_get_push_log_entry( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'newspack_push_log_entry_not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Five retries of one sync used to read as five identical lines. The
	 * title now says which retry an action is.
	 */
	public function test_a_scheduled_retry_is_titled_with_its_number() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		$group        = Integrations::get_action_group( 'sample' );
		$with_ceiling = \as_schedule_single_action(
			time() + 120,
			Contact_Sync::RETRY_HOOK,
			[
				[
					'integration_id' => 'sample',
					'user_id'        => 7,
					'retry_count'    => 2,
					'max_retries'    => 5,
				],
			],
			$group
		);
		$no_ceiling   = \as_schedule_single_action(
			time() + 240,
			Contact_Sync::RETRY_HOOK,
			[
				[
					'integration_id' => 'sample',
					'user_id'        => 7,
					'retry_count'    => 3,
				],
			],
			$group
		);

		$request = new \WP_REST_Request( 'GET' );
		foreach (
			[
				'integration_id' => 'sample',
				'per_page'       => 25,
				'page'           => 1,
				'orderby'        => 'scheduled_date_gmt',
				'order'          => 'ASC',
				'search'         => '',
				'status'         => '',
			] as $name => $value
		) {
			$request->set_param( $name, $value );
		}
		$wizard = new Audience_Integrations();
		$events = array_column( $wizard->api_get_integration_logs( $request )->get_data()['items'], 'event', 'id' );

		$this->assertSame( 'Contact Sync Retry 2 of 5', $events[ $with_ceiling ] );
		$this->assertSame( 'Contact Sync Retry 3', $events[ $no_ceiling ] );

		$detail = new \WP_REST_Request( 'GET' );
		$detail->set_param( 'integration_id', 'sample' );
		$detail->set_param( 'action_id', $with_ceiling );
		$this->assertSame( 'Contact Sync Retry 2 of 5', $wizard->api_get_integration_log_detail( $detail )->get_data()['action']['event'] );
	}
}
