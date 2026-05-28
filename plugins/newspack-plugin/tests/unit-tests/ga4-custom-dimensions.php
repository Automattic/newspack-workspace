<?php
/**
 * Tests for GA4 custom dimension provisioning.
 *
 * @package Newspack\Tests
 */

use Newspack\GA4_Custom_Dimensions;
use Newspack\Google_OAuth_GA4_Client;

/**
 * GA4 custom dimensions provisioning.
 *
 * Mocks the GA4 Admin API at the HTTP layer (via `pre_http_request`) so the
 * provisioner's branching – auth-route selection, idempotency, the
 * property-switch summary merge – is exercised without a real Google project.
 *
 * @group ga4-dimensions
 */
class Newspack_Test_GA4_Custom_Dimensions extends WP_UnitTestCase {

	const SK_SETTINGS_OPTION = 'googlesitekit_analytics-4_settings';

	/**
	 * Recorded HTTP requests, each `[ 'url' => string, 'method' => string, 'body' => string|null ]`.
	 *
	 * @var array
	 */
	private $http_log = [];

	/**
	 * URL-substring => response array (as returned to `pre_http_request`) or `callable( $url, $args )`.
	 *
	 * @var array
	 */
	private $http_routes = [];

	/**
	 * Administrator user id used as the Site Kit module owner.
	 *
	 * @var int
	 */
	private $owner_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->owner_id    = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->http_log    = [];
		$this->http_routes = [];
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );

		delete_option( GA4_Custom_Dimensions::PROVISIONED_OPTION );
		delete_option( self::SK_SETTINGS_OPTION );
		wp_clear_scheduled_hook( GA4_Custom_Dimensions::PROVISION_ACTION );

		// The OAuth proxy constants persist across tests; define them once. The
		// API-key option is what governs whether OAuth counts as configured.
		if ( ! defined( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME' ) ) {
			define( 'NEWSPACK_MANAGER_API_KEY_OPTION_NAME', 'newspack_manager_api_key' );
		}
		if ( ! defined( 'NEWSPACK_GOOGLE_OAUTH_PROXY' ) ) {
			define( 'NEWSPACK_GOOGLE_OAUTH_PROXY', 'https://oauth.example.test' );
		}
		delete_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME );
		delete_option( '_newspack_google_oauth' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * `pre_http_request` handler: records the request and returns the first
	 * registered route whose substring matches the URL, or a 404 otherwise so a
	 * missing mock is obvious.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$method           = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$this->http_log[] = [
			'url'    => $url,
			'method' => $method,
			'body'   => isset( $args['body'] ) ? $args['body'] : null,
		];
		foreach ( $this->http_routes as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return is_callable( $response ) ? call_user_func( $response, $url, $args ) : $response;
			}
		}
		return $this->json_response( 404, [ 'error' => [ 'message' => "Unmocked request to $url" ] ] );
	}

	/**
	 * Build an HTTP response array for `pre_http_request`.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Response body, JSON-encoded.
	 * @return array
	 */
	private function json_response( $code, array $body ) {
		return [
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'body'     => wp_json_encode( $body ),
			'headers'  => [],
			'cookies'  => [],
		];
	}

	/**
	 * Count recorded requests whose URL contains $needle, optionally filtered by method.
	 *
	 * @param string      $needle URL substring.
	 * @param string|null $method HTTP method, or null for any.
	 * @return int
	 */
	private function count_requests( $needle, $method = null ) {
		$count = 0;
		foreach ( $this->http_log as $request ) {
			if ( false === strpos( $request['url'], $needle ) ) {
				continue;
			}
			if ( null !== $method && strtoupper( $method ) !== $request['method'] ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Make Newspack's Google OAuth count as configured, with a stored token
	 * whose scope set may or may not include `analytics.edit`.
	 *
	 * @param bool $with_edit_scope Whether the token carries `analytics.edit`.
	 */
	private function configure_newspack_oauth( $with_edit_scope = true ) {
		update_option( NEWSPACK_MANAGER_API_KEY_OPTION_NAME, 'test-key' );
		update_option(
			'_newspack_google_oauth',
			[
				'access_token'  => 'fake-access-token',
				'expires_at'    => time() + HOUR_IN_SECONDS,
				'refresh_token' => 'fake-refresh-token',
			]
		);
		$scopes = 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/analytics';
		if ( $with_edit_scope ) {
			$scopes .= ' https://www.googleapis.com/auth/analytics.edit';
		}
		$this->http_routes['oauth2/v1/tokeninfo'] = $this->json_response(
			200,
			[
				'scope' => $scopes,
				'email' => 'owner@example.test',
			]
		);
	}

	/**
	 * Record a connected GA4 property (and module owner) in Site Kit's settings.
	 *
	 * @param string $property_id GA4 property ID.
	 */
	private function connect_property( $property_id ) {
		update_option(
			self::SK_SETTINGS_OPTION,
			[
				'propertyID' => $property_id,
				'ownerID'    => $this->owner_id,
			]
		);
	}

	/**
	 * Mock the GA4 Admin API `customDimensions` endpoint for a property: GET
	 * lists $existing_param_names (all EVENT-scoped); POST records and accepts
	 * the create unless $create_status is a non-2xx code.
	 *
	 * @param string $property_id          GA4 property ID.
	 * @param array  $existing_param_names  Parameter names already present.
	 * @param int    $create_status         HTTP status to return from create POSTs.
	 */
	private function mock_admin_api( $property_id, array $existing_param_names, $create_status = 200 ) {
		$existing = array_map(
			function ( $name ) use ( $property_id ) {
				return [
					'name'          => "properties/$property_id/customDimensions/$name",
					'parameterName' => $name,
					'displayName'   => ucfirst( $name ),
					'scope'         => 'EVENT',
				];
			},
			$existing_param_names
		);
		$this->http_routes[ "properties/$property_id/customDimensions" ] = function ( $url, $args ) use ( $existing, $create_status ) {
			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
			if ( 'POST' === $method ) {
				if ( $create_status < 200 || $create_status >= 300 ) {
					return $this->json_response( $create_status, [ 'error' => [ 'message' => 'Request had insufficient authentication scopes.' ] ] );
				}
				$payload = json_decode( $args['body'], true );
				return $this->json_response(
					200,
					[
						'name'          => 'properties/x/customDimensions/y',
						'parameterName' => $payload['parameterName'],
						'scope'         => 'EVENT',
					]
				);
			}
			return $this->json_response( 200, [ 'customDimensions' => $existing ] );
		};
	}

	/**
	 * The provisioned dimension list must fit within GA4's event-scoped cap.
	 */
	public function test_dimension_list_fits_event_scoped_cap() {
		$dimensions = GA4_Custom_Dimensions::get_dimensions();
		$this->assertLessThanOrEqual( 50, count( $dimensions ), 'Provisioned dimension count must not exceed GA4\'s 50 event-scoped cap.' );
		foreach ( $dimensions as $parameter_name => $display_name ) {
			$this->assertNotEmpty( $parameter_name, 'Each dimension must have a non-empty parameter name.' );
			$this->assertNotEmpty( $display_name, 'Each dimension must have a non-empty display name.' );
		}
	}

	/**
	 * Connecting / changing the GA4 property schedules background provisioning,
	 * and the scheduler de-duplicates redundant requests.
	 */
	public function test_schedule_provisioning_is_deduplicated() {
		// A newly connected property schedules provisioning.
		GA4_Custom_Dimensions::on_sitekit_settings_added( self::SK_SETTINGS_OPTION, [ 'propertyID' => 'P1' ] );
		$scheduled = wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION );
		$this->assertNotFalse( $scheduled, 'Connecting a property schedules provisioning.' );

		// A second add for the same property does not queue a second event.
		GA4_Custom_Dimensions::on_sitekit_settings_added( self::SK_SETTINGS_OPTION, [ 'propertyID' => 'P1' ] );
		$this->assertSame( $scheduled, wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION ), 'A second add does not queue a duplicate event.' );

		// A property already recorded as provisioned, with nothing pending, is not rescheduled.
		wp_clear_scheduled_hook( GA4_Custom_Dimensions::PROVISION_ACTION );
		update_option(
			GA4_Custom_Dimensions::PROVISIONED_OPTION,
			[
				'property_id' => 'P1',
				'created'     => [],
			]
		);
		GA4_Custom_Dimensions::on_sitekit_settings_updated( [ 'propertyID' => 'P0' ], [ 'propertyID' => 'P1' ] );
		$this->assertFalse( wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION ), 'An already-provisioned property is not rescheduled.' );

		// A genuine property change schedules provisioning.
		GA4_Custom_Dimensions::on_sitekit_settings_updated( [ 'propertyID' => 'P1' ], [ 'propertyID' => 'P2' ] );
		$this->assertNotFalse( wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION ), 'Changing the property schedules provisioning.' );

		// An update that does not change the property ID is a no-op.
		wp_clear_scheduled_hook( GA4_Custom_Dimensions::PROVISION_ACTION );
		GA4_Custom_Dimensions::on_sitekit_settings_updated( [ 'propertyID' => 'P2' ], [ 'propertyID' => 'P2' ] );
		$this->assertFalse( wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION ), 'An unchanged property does not schedule provisioning.' );

		// A non-array option value is tolerated without scheduling or warnings.
		GA4_Custom_Dimensions::on_sitekit_settings_added( self::SK_SETTINGS_OPTION, 'not-an-array' );
		$this->assertFalse( wp_next_scheduled( GA4_Custom_Dimensions::PROVISION_ACTION ), 'A non-array settings value does not schedule provisioning.' );
	}

	/**
	 * With Newspack OAuth configured and its token carrying `analytics.edit`,
	 * that route is used in preference to Site Kit.
	 */
	public function test_uses_newspack_oauth_when_edit_scope_present() {
		$this->connect_property( 'PROP-A' );
		$this->configure_newspack_oauth( true );
		$this->mock_admin_api( 'PROP-A', [] );

		$status = GA4_Custom_Dimensions::status();
		$this->assertIsArray( $status );
		$this->assertSame( 'newspack', $status['auth_source'] );
		$this->assertCount( count( GA4_Custom_Dimensions::get_dimensions() ), $status['newspack_missing'] );
	}

	/**
	 * If the Newspack OAuth token predates the `analytics.edit` scope, that
	 * route is skipped before any Admin API call, and the failure message says
	 * why.
	 */
	public function test_skips_newspack_oauth_without_edit_scope() {
		$this->connect_property( 'PROP-B' );
		$this->configure_newspack_oauth( false );

		$result = GA4_Custom_Dimensions::status();
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'analytics.edit', $result->get_error_message() );
		$this->assertSame( 0, $this->count_requests( 'analyticsadmin.googleapis.com' ), 'The Admin API is not called when the token lacks the edit scope.' );
	}

	/**
	 * When the Newspack OAuth path errors, the call falls back to Site Kit; if
	 * that is unavailable too the WP_Error names both routes and surfaces the
	 * underlying API error (so a 403 is self-explanatory).
	 */
	public function test_falls_back_and_reports_when_newspack_path_fails() {
		$this->connect_property( 'PROP-C' );
		$this->configure_newspack_oauth( true );
		$this->http_routes['properties/PROP-C/customDimensions'] = $this->json_response( 403, [ 'error' => [ 'message' => 'Request had insufficient authentication scopes.' ] ] );

		$result = GA4_Custom_Dimensions::status();
		$this->assertWPError( $result );
		$this->assertStringContainsString( '403', $result->get_error_message() );
		$this->assertStringContainsString( 'Site Kit', $result->get_error_message() );
	}

	/**
	 * With neither Newspack OAuth nor Site Kit available, the call fails with a
	 * WP_Error that names both routes.
	 */
	public function test_errors_when_no_auth_route_available() {
		$this->connect_property( 'PROP-D' );

		$result = GA4_Custom_Dimensions::status();
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Newspack OAuth', $result->get_error_message() );
		$this->assertStringContainsString( 'Site Kit', $result->get_error_message() );
	}

	/**
	 * A run against a property that already has every dimension creates nothing.
	 */
	public function test_provision_is_idempotent() {
		$this->connect_property( 'PROP-IDEM' );
		$this->configure_newspack_oauth( true );
		$this->mock_admin_api( 'PROP-IDEM', array_keys( GA4_Custom_Dimensions::get_dimensions() ) );

		$summary = GA4_Custom_Dimensions::provision();
		$this->assertIsArray( $summary );
		$this->assertSame( 'newspack', $summary['auth_source'] );
		$this->assertSame( [], $summary['created'], 'Nothing is created when every dimension already exists.' );
		$this->assertCount( count( GA4_Custom_Dimensions::get_dimensions() ), $summary['skipped_exists'] );
		$this->assertSame( 0, $this->count_requests( 'properties/PROP-IDEM/customDimensions', 'POST' ), 'No create requests are made.' );
		$this->assertGreaterThanOrEqual( 1, $this->count_requests( 'properties/PROP-IDEM/customDimensions', 'GET' ), 'Existing dimensions are listed first.' );
	}

	/**
	 * A run against a different property than the previous run does not carry
	 * the previous run's created list into the new summary.
	 */
	public function test_provision_does_not_merge_across_a_property_switch() {
		$this->connect_property( 'PROP-NEW' );
		$this->configure_newspack_oauth( true );
		// 'gate_post_id' already exists on the new property, so this run skips it.
		$this->mock_admin_api( 'PROP-NEW', [ 'gate_post_id' ] );
		// The previous run targeted a different property and created 'gate_post_id' there.
		update_option(
			GA4_Custom_Dimensions::PROVISIONED_OPTION,
			[
				'property_id'    => 'PROP-OLD',
				'auth_source'    => 'newspack',
				'timestamp'      => time(),
				'created'        => [ 'gate_post_id', 'is_reader' ],
				'skipped_exists' => [],
				'errors'         => [],
			]
		);

		$summary = GA4_Custom_Dimensions::provision();
		$expected_created = count( GA4_Custom_Dimensions::get_dimensions() ) - 1;
		$this->assertSame( 'PROP-NEW', $summary['property_id'] );
		$this->assertContains( 'gate_post_id', $summary['skipped_exists'], "'gate_post_id' already exists on the new property." );
		$this->assertNotContains( 'gate_post_id', $summary['created'], "The previous property's created list is not carried over." );
		$this->assertCount( $expected_created, $summary['created'] );
		$this->assertSame( $summary, get_option( GA4_Custom_Dimensions::PROVISIONED_OPTION ), 'The summary is persisted.' );
	}

	/**
	 * A run against the same property as the previous run carries that run's
	 * created list forward, even for a dimension that this run skipped.
	 */
	public function test_provision_merges_created_list_for_same_property() {
		$this->connect_property( 'PROP-SAME' );
		$this->configure_newspack_oauth( true );
		// 'author' already exists, so this run skips it – but the previous run created it.
		$this->mock_admin_api( 'PROP-SAME', [ 'author' ] );
		update_option(
			GA4_Custom_Dimensions::PROVISIONED_OPTION,
			[
				'property_id'    => 'PROP-SAME',
				'auth_source'    => 'newspack',
				'timestamp'      => time(),
				'created'        => [ 'author' ],
				'skipped_exists' => [],
				'errors'         => [],
			]
		);

		$summary = GA4_Custom_Dimensions::provision();
		$this->assertContains( 'author', $summary['skipped_exists'], "'author' already exists on the property." );
		$this->assertContains( 'author', $summary['created'], "The previous run's created list is carried forward for the same property." );
		$this->assertCount( count( GA4_Custom_Dimensions::get_dimensions() ), $summary['created'] );
	}

	/**
	 * `Google_OAuth_GA4_Client::build()` returns null when Newspack OAuth is
	 * not configured.
	 */
	public function test_ga4_client_build_returns_null_when_unconfigured() {
		$this->assertNull( Google_OAuth_GA4_Client::build() );
	}

	/**
	 * The client follows pagination when listing custom dimensions.
	 */
	public function test_ga4_client_lists_custom_dimensions_with_pagination() {
		wp_set_current_user( $this->owner_id );
		$this->configure_newspack_oauth( true );

		$page = 0;
		$this->http_routes['properties/PAGED/customDimensions'] = function ( $url, $args ) use ( &$page ) {
			++$page;
			if ( 1 === $page ) {
				return $this->json_response(
					200,
					[
						'customDimensions' => [
							[
								'name'          => 'properties/PAGED/customDimensions/a',
								'parameterName' => 'a',
								'displayName'   => 'A',
								'scope'         => 'EVENT',
							],
						],
						'nextPageToken'    => 'PAGE2',
					]
				);
			}
			return $this->json_response(
				200,
				[
					'customDimensions' => [
						[
							'name'          => 'properties/PAGED/customDimensions/b',
							'parameterName' => 'b',
							'displayName'   => 'B',
							'scope'         => 'EVENT',
						],
					],
				]
			);
		};

		$client = Google_OAuth_GA4_Client::build();
		$this->assertInstanceOf( Google_OAuth_GA4_Client::class, $client );
		$dimensions = $client->list_custom_dimensions( 'PAGED' );
		$this->assertSame( [ 'a', 'b' ], wp_list_pluck( $dimensions, 'parameterName' ) );
		$this->assertSame( 1, $this->count_requests( 'pageToken=PAGE2', 'GET' ), 'The second page is fetched with the page token.' );
	}

	/**
	 * The client posts an EVENT-scoped dimension with the given parameter and
	 * display names.
	 */
	public function test_ga4_client_creates_event_scoped_dimension() {
		wp_set_current_user( $this->owner_id );
		$this->configure_newspack_oauth( true );

		$captured = null;
		$this->http_routes['properties/PROP/customDimensions'] = function ( $url, $args ) use ( &$captured ) {
			$captured = json_decode( $args['body'], true );
			return $this->json_response(
				200,
				[
					'name'          => 'properties/PROP/customDimensions/z',
					'parameterName' => $captured['parameterName'],
					'scope'         => 'EVENT',
				]
			);
		};

		$client = Google_OAuth_GA4_Client::build();
		$client->create_custom_dimension( 'PROP', 'my_param', 'My Param' );
		$this->assertSame(
			[
				'parameterName' => 'my_param',
				'displayName'   => 'My Param',
				'scope'         => 'EVENT',
			],
			$captured
		);
		$this->assertSame( 1, $this->count_requests( 'properties/PROP/customDimensions', 'POST' ) );
	}

	/**
	 * The client throws on a non-2xx Admin API response, including the status
	 * code and the API's error message.
	 */
	public function test_ga4_client_throws_on_api_error() {
		wp_set_current_user( $this->owner_id );
		$this->configure_newspack_oauth( true );
		$this->http_routes['properties/PROP/customDimensions'] = $this->json_response( 403, [ 'error' => [ 'message' => 'Insufficient Permission' ] ] );

		$client = Google_OAuth_GA4_Client::build();
		try {
			$client->list_custom_dimensions( 'PROP' );
			$this->fail( 'Expected a RuntimeException for a 403 response.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '403', $e->getMessage() );
			$this->assertStringContainsString( 'Insufficient Permission', $e->getMessage() );
		}
	}
}
