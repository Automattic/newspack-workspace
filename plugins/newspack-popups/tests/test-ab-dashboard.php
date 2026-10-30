<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the A/B Tests dashboard: provider seam, stats route and page.
 *
 * @package Newspack_Popups
 */

require_once __DIR__ . '/mocks/class-ab-stub-provider.php';

/**
 * Dashboard tests.
 *
 * @group ab-tests
 */
class ABDashboardTest extends WP_UnitTestCase_PageWithPopups {
	/**
	 * Built asset files moved aside for a fixture, original => backup.
	 *
	 * @var string[]
	 */
	private $asset_backups = [];

	/**
	 * Fixture asset files this test wrote.
	 *
	 * @var string[]
	 */
	private $asset_fixtures = [];

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		// WP_UnitTestCase keeps one REST server for the whole run, so routes
		// registered under one test's flag state would outlive it.
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		foreach ( $this->asset_fixtures as $file ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Removes only the fixture assets this test wrote.
				unlink( $file );
			}
		}
		foreach ( $this->asset_backups as $file => $backup ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Restores the built asset this test moved aside.
			rename( $backup, $file );
		}
		$this->asset_fixtures = [];
		$this->asset_backups  = [];
		wp_dequeue_script( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE );
		wp_deregister_script( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE );
		wp_dequeue_style( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE );
		wp_deregister_style( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE );
		parent::tear_down();
	}

	/**
	 * Replace the dashboard's built assets with fixtures for one test.
	 *
	 * The build output is gitignored, so CI's PHPUnit job has none; a test
	 * that reads it has to supply its own. A built copy, where one exists,
	 * is moved aside and put back in tear_down().
	 *
	 * @param array  $dependencies Dependencies the fixture manifest declares.
	 * @param string $version      Version the fixture manifest declares.
	 */
	private function plant_asset_fixtures( $dependencies, $version ) {
		$dist = dirname( __DIR__ ) . '/dist';
		if ( ! is_dir( $dist ) ) {
			wp_mkdir_p( $dist );
		}
		$manifest = sprintf(
			'<?php return [ \'dependencies\' => %s, \'version\' => %s ];',
			wp_json_encode( $dependencies ),
			wp_json_encode( $version )
		);
		$fixtures = [
			'abTests.asset.php' => $manifest,
			'abTests.js'        => '',
			'abTests.css'       => '',
		];
		foreach ( $fixtures as $name => $contents ) {
			$path = $dist . '/' . $name;
			if ( file_exists( $path . '.bak' ) ) {
				// A run killed between planting and tear_down() leaves the build in
				// the backup; put it back first, or the fixture would overwrite it.
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Restores a stranded backup of the built asset.
				rename( $path . '.bak', $path );
			}
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Moves the built asset aside for the fixture.
				rename( $path, $path . '.bak' );
				$this->asset_backups[ $path ] = $path . '.bak';
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture under the generated dist directory.
			file_put_contents( $path, $contents );
			$this->asset_fixtures[] = $path;
		}
	}

	/**
	 * Without a filter there is no provider.
	 */
	public function test_no_provider_by_default() {
		$this->assertNull( Newspack_Popups_AB_Dashboard::get_provider() );
	}

	/**
	 * The filter supplies the provider.
	 */
	public function test_filter_supplies_the_provider() {
		$stub = new AB_Stub_Provider(
			[
				'unit'     => 'readers',
				'variants' => [],
			] 
		);
		add_filter( 'newspack_popups_ab_stats_provider', fn() => $stub );
		$this->assertSame( $stub, Newspack_Popups_AB_Dashboard::get_provider() );
	}

	/**
	 * A filter value that is not a provider is treated as none.
	 */
	public function test_filter_value_that_is_not_a_provider_is_ignored() {
		add_filter( 'newspack_popups_ab_stats_provider', fn() => new stdClass() );
		$this->assertNull( Newspack_Popups_AB_Dashboard::get_provider() );
	}

	/**
	 * Create a prompt in a test.
	 *
	 * @param string $test_id      Test ID.
	 * @param string $variant      Variant letter.
	 * @param array  $post_options Post fields.
	 * @return int Prompt ID.
	 */
	private function create_test_variant( $test_id, $variant, $post_options = [] ) {
		$popup_id = $this->createPopup( null, null, $post_options );
		update_post_meta( $popup_id, Newspack_Popups_AB_Tests::META_TEST_ID, $test_id );
		update_post_meta( $popup_id, Newspack_Popups_AB_Tests::META_VARIANT, $variant );
		return $popup_id;
	}

	/**
	 * A published two-arm test that started on a fixed day.
	 *
	 * @param string $test_id Test ID.
	 * @param string $started Publish date, Y-m-d.
	 * @return int[] Prompt IDs by variant letter.
	 */
	private function create_running_test( $test_id = 't', $started = '2026-10-01' ) {
		$dates = [
			'post_date_gmt' => $started . ' 00:00:00',
			'post_date'     => $started . ' 00:00:00',
		];
		return [
			'a' => $this->create_test_variant( $test_id, 'a', $dates ),
			'b' => $this->create_test_variant( $test_id, 'b', $dates ),
		];
	}

	/**
	 * Register a stub provider with the given counts.
	 *
	 * @param array|WP_Error $counts    Counts.
	 * @param true|WP_Error  $available Availability.
	 * @return AB_Stub_Provider
	 */
	private function use_provider( $counts, $available = true ) {
		$stub = new AB_Stub_Provider( $counts, $available );
		add_filter( 'newspack_popups_ab_stats_provider', fn() => $stub );
		return $stub;
	}

	/**
	 * Counts for a two-arm test.
	 *
	 * @param int $a_readers     Control readers.
	 * @param int $a_conversions Control conversions.
	 * @param int $b_readers     Challenger readers.
	 * @param int $b_conversions Challenger conversions.
	 * @param int $a_logged_in   Control logged-in readers.
	 * @param int $b_logged_in   Challenger logged-in readers.
	 * @return array
	 */
	private function counts( $a_readers, $a_conversions, $b_readers, $b_conversions, $a_logged_in = 0, $b_logged_in = 0 ) {
		return [
			'unit'     => 'readers',
			'variants' => [
				'a' => [
					'readers'           => $a_readers,
					'conversions'       => $a_conversions,
					'logged_in_readers' => $a_logged_in,
				],
				'b' => [
					'readers'           => $b_readers,
					'conversions'       => $b_conversions,
					'logged_in_readers' => $b_logged_in,
				],
			],
		];
	}

	/**
	 * Request a test's stats.
	 *
	 * @param string $test_id Test ID.
	 * @param array  $params  Query parameters.
	 * @return WP_REST_Response
	 */
	private function stats_request( $test_id, $params = [] ) {
		$request = new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/' . $test_id . '/stats' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * A Test ID made of digits reaches the route as the string it was saved as.
	 */
	public function test_stats_route_finds_a_numeric_test_id() {
		$this->as_admin();
		$this->create_running_test( '123' );
		$response = $this->stats_request( '123' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '123', $response->get_data()['test_id'] );
	}

	/**
	 * Sign in as an administrator.
	 */
	private function as_admin() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Editors can manage prompts but not read the dashboard.
	 */
	public function test_route_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->create_running_test();
		$this->assertSame( 403, $this->stats_request( 't' )->get_status() );
	}

	/**
	 * An unknown Test ID is a 404.
	 */
	public function test_unknown_test_is_404() {
		$this->as_admin();
		$this->assertSame( 404, $this->stats_request( 'nope' )->get_status() );
	}

	/**
	 * With the flag off the route does not exist.
	 */
	public function test_route_is_absent_with_the_flag_off() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->as_admin();
		$this->create_running_test();
		$response = $this->stats_request( 't' );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
	}

	/**
	 * Without a provider the response says so and every variant is zero.
	 */
	public function test_no_provider_is_unavailable_with_a_reason() {
		$this->as_admin();
		$this->create_running_test();
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'unavailable', $data['source']['state'] );
		$this->assertSame( 'Results will show here once a stats source is connected.', $data['source']['reason'] );
		$this->assertNull( $data['source']['provider'] );
		$this->assertSame( 'collecting', $data['comparisons']['b']['label'] );
		$this->assertNull( $data['comparisons']['b']['confidence'] );
		$this->assertSame( 0, $data['variants']['a']['readers'] );
		$this->assertSame( 0, $data['variants']['b']['conversions'] );
		$this->assertNull( $data['variants']['a']['rate'] );
	}

	/**
	 * Ways is_available() can say no, with the reason the page should show.
	 *
	 * @return array[]
	 */
	public function not_available_answers() {
		return [
			'error'     => [ new WP_Error( 'x', 'Connect the thing.' ), 'Connect the thing.' ],
			'false'     => [ false, 'The stats source is not available on this site.' ],
			'exception' => [ new RuntimeException( 'No client.' ), 'No client.' ],
		];
	}

	/**
	 * A provider that is not available is unavailable on the route and the page,
	 * with the same reason, and is never asked for counts.
	 *
	 * @dataProvider not_available_answers
	 * @param mixed  $answer What is_available() returns or throws.
	 * @param string $reason What the page shows.
	 */
	public function test_not_available_provider_is_unavailable_everywhere( $answer, $reason ) {
		$this->as_admin();
		$this->create_running_test();
		$stub = $this->use_provider( $this->counts( 1, 1, 1, 1 ), $answer );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'unavailable', $data['source']['state'] );
		$this->assertSame( $reason, $data['source']['reason'] );
		$this->assertSame( 'stub', $data['source']['provider'] );
		$this->assertCount( 0, $stub->calls );
		$boot = Newspack_Popups_AB_Dashboard::get_boot_data();
		$this->assertFalse( $boot['provider']['available'] );
		$this->assertSame( 'stub', $boot['provider']['slug'] );
		$this->assertSame( $reason, $boot['provider']['reason'] );
	}

	/**
	 * Ways get_counts() can fail.
	 *
	 * @return array[]
	 */
	public function count_failures() {
		return [
			'error'     => [ new WP_Error( 'x', 'The source timed out.' ), 'The source timed out.' ],
			'exception' => [ new RuntimeException( 'The client blew up.' ), 'The client blew up.' ],
			'malformed' => [ [ 'error' => 'no variants key' ], 'The stats source returned an unexpected answer.' ],
		];
	}

	/**
	 * A failed count is reported with its reason and never cached.
	 *
	 * @dataProvider count_failures
	 * @param mixed  $failure What get_counts() returns or throws.
	 * @param string $reason  What the page shows.
	 */
	public function test_count_failure_is_unavailable_and_not_cached( $failure, $reason ) {
		$this->as_admin();
		$this->create_running_test();
		$stub = $this->use_provider( $failure );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'unavailable', $data['source']['state'] );
		$this->assertSame( $reason, $data['source']['reason'] );
		$this->stats_request( 't' );
		$this->assertCount( 2, $stub->calls );
	}

	/**
	 * A provider with nothing yet means the test is collecting.
	 */
	public function test_empty_counts_are_collecting() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider(
			[
				'unit'     => 'readers',
				'variants' => [],
			] 
		);
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'collecting', $data['source']['state'] );
		$this->assertNull( $data['source']['reason'] );
		$this->assertSame( [ 'a', 'b' ], array_keys( $data['variants'] ) );
		$this->assertSame( 0, $data['variants']['b']['readers'] );
	}

	/**
	 * A provider can add one sentence about its counts, and the route passes it through.
	 */
	public function test_provider_note_is_passed_through() {
		$this->as_admin();
		$this->create_running_test();
		$counts         = $this->counts( 1200, 36, 1180, 48 );
		$counts['note'] = 'Counts update once a day.';
		$this->use_provider( $counts );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'Counts update once a day.', $data['source']['note'] );
	}

	/**
	 * A missing or non-string note reads as none.
	 */
	public function test_missing_or_invalid_note_is_null() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider( $this->counts( 1200, 36, 1180, 48 ) );
		$this->assertNull( $this->stats_request( 't' )->get_data()['source']['note'] );
		$counts         = $this->counts( 1200, 36, 1180, 48 );
		$counts['note'] = [ 'not', 'a', 'string' ];
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		$this->use_provider( $counts );
		$this->assertNull( $this->stats_request( 't', [ 'refresh' => '1' ] )->get_data()['source']['note'] );
	}

	/**
	 * Counts become rates, lift against control, and a confidence per challenger.
	 */
	public function test_counts_become_rates_lift_and_confidence() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider( $this->counts( 1200, 36, 1180, 48 ) );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'ok', $data['source']['state'] );
		$this->assertSame( 'readers', $data['source']['unit'] );
		$this->assertSame( 3.0, $data['variants']['a']['rate'] );
		$this->assertSame( 4.07, $data['variants']['b']['rate'] );
		$this->assertSame( 35.6, $data['variants']['b']['lift'] );
		$this->assertNull( $data['variants']['a']['lift'] );
		$this->assertIsFloat( $data['comparisons']['b']['confidence'] );
		$this->assertContains( $data['comparisons']['b']['label'], [ 'directional', 'reliable' ] );
		$this->assertArrayNotHasKey( 'a', $data['comparisons'] );
	}

	/**
	 * Lift comes from the raw counts: two rates that display the same can still
	 * differ by a third.
	 */
	public function test_lift_is_computed_before_the_rates_are_rounded() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider( $this->counts( 1000000, 100, 1500000, 200 ) );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 0.01, $data['variants']['a']['rate'] );
		$this->assertSame( 0.01, $data['variants']['b']['rate'] );
		$this->assertSame( 33.3, $data['variants']['b']['lift'] );
	}

	/**
	 * The provider's variants are matched to discovery's, not trusted as the list.
	 */
	public function test_unknown_variants_are_dropped_and_missing_ones_zero_filled() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider(
			[
				'unit'     => 'readers',
				'variants' => [
					'c' => [
						'readers'           => 500,
						'conversions'       => 5,
						'logged_in_readers' => 0,
					],
					'a' => [
						'readers'           => 100,
						'conversions'       => 1,
						'logged_in_readers' => 0,
					],
				],
			]
		);
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( [ 'a', 'b' ], array_keys( $data['variants'] ) );
		$this->assertSame( 100, $data['variants']['a']['readers'] );
		$this->assertSame( 0, $data['variants']['b']['readers'] );
		$this->assertSame( 'ok', $data['source']['state'] );
	}

	/**
	 * Zero readers everywhere produces nulls, not division errors.
	 */
	public function test_both_arms_zero_readers_give_nulls() {
		$this->as_admin();
		$this->create_running_test();
		$this->use_provider( $this->counts( 0, 0, 0, 0 ) );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( 'collecting', $data['source']['state'] );
		$this->assertNull( $data['variants']['a']['rate'] );
		$this->assertNull( $data['variants']['b']['lift'] );
		$this->assertNull( $data['comparisons']['b']['confidence'] );
		$this->assertNull( $data['flags']['anonymous_share'] );
		$this->assertFalse( $data['flags']['high_anonymous'] );
	}

	/**
	 * The window runs from the day the split began to today and reaches the provider.
	 */
	public function test_window_runs_from_started_at_and_is_passed_to_the_provider() {
		$this->as_admin();
		$this->create_running_test( 't', '2026-10-01' );
		$stub = $this->use_provider( $this->counts( 10, 1, 10, 1 ) );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertSame( '2026-10-01', $data['window']['start'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $data['window']['end'] );
		$this->assertSame( '2026-10-01', $stub->calls[0]['start'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $stub->calls[0]['end'] );
		$this->assertSame( 't', $stub->calls[0]['test']['test_id'] );
	}

	/**
	 * A test with nothing published has no window and asks the provider nothing.
	 */
	public function test_unpublished_test_has_no_window_and_is_collecting() {
		$this->as_admin();
		$this->create_test_variant( 'd', 'a', [ 'post_status' => 'draft' ] );
		$stub = $this->use_provider( $this->counts( 10, 1, 10, 1 ) );
		$data = $this->stats_request( 'd' )->get_data();
		$this->assertNull( $data['window'] );
		$this->assertSame( 'collecting', $data['source']['state'] );
		$this->assertCount( 0, $stub->calls );
		$this->assertTrue( $data['flags']['young_test'] );
		$this->assertNull( $data['flags']['days_running'] );
	}

	/**
	 * Counts are cached for an hour; refresh asks the provider again.
	 */
	public function test_counts_are_cached_and_refresh_bypasses() {
		$this->as_admin();
		$this->create_running_test();
		$stub = $this->use_provider( $this->counts( 100, 5, 100, 6 ) );
		$first = $this->stats_request( 't' )->get_data();
		$second = $this->stats_request( 't' )->get_data();
		$this->assertCount( 1, $stub->calls );
		$this->assertFalse( $first['source']['cached'] );
		$this->assertTrue( $second['source']['cached'] );
		$this->assertSame( $first['source']['fetched_at'], $second['source']['fetched_at'] );
		$third = $this->stats_request( 't', [ 'refresh' => '1' ] )->get_data();
		$this->assertCount( 2, $stub->calls );
		$this->assertFalse( $third['source']['cached'] );
	}

	/**
	 * A new goal must not be served from the old goal's counts.
	 */
	public function test_changing_the_goal_misses_the_cache() {
		$this->as_admin();
		$ids  = $this->create_running_test();
		$stub = $this->use_provider( $this->counts( 100, 5, 100, 6 ) );
		$this->stats_request( 't' );
		update_post_meta( $ids['a'], Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		$data = $this->stats_request( 't' )->get_data();
		$this->assertCount( 2, $stub->calls );
		$this->assertSame( 'donation', $stub->calls[1]['goal'] );
		$this->assertSame( 'donation', $data['goal'] );
	}

	/**
	 * Swapping which prompt carries a letter must not be served the old arm's counts.
	 */
	public function test_changing_an_arm_s_prompt_misses_the_cache() {
		$this->as_admin();
		$ids  = $this->create_running_test();
		$stub = $this->use_provider( $this->counts( 100, 5, 100, 6 ) );
		$this->stats_request( 't' );
		wp_update_post(
			[
				'ID'          => $ids['b'],
				'post_status' => 'draft',
			]
		);
		$replacement = $this->create_test_variant(
			't',
			'b',
			[
				'post_date_gmt' => '2026-10-01 00:00:00',
				'post_date'     => '2026-10-01 00:00:00',
			]
		);
		$data        = $this->stats_request( 't' )->get_data();
		$this->assertCount( 2, $stub->calls );
		$this->assertSame( $replacement, $stub->calls[1]['test']['variants']['b']['id'] );
		$this->assertFalse( $data['source']['cached'] );
	}

	/**
	 * A test younger than fourteen days is flagged; one at fourteen is not.
	 */
	public function test_young_test_flag_boundaries() {
		$this->as_admin();
		$this->use_provider( $this->counts( 100, 5, 100, 6 ) );
		$this->create_running_test( 'young', gmdate( 'Y-m-d', strtotime( '-13 days' ) ) );
		$this->create_running_test( 'settled', gmdate( 'Y-m-d', strtotime( '-14 days' ) ) );
		$young   = $this->stats_request( 'young' )->get_data();
		$settled = $this->stats_request( 'settled' )->get_data();
		$this->assertTrue( $young['flags']['young_test'] );
		$this->assertSame( 13, $young['flags']['days_running'] );
		$this->assertFalse( $settled['flags']['young_test'] );
		$this->assertSame( 14, $settled['flags']['days_running'] );
	}

	/**
	 * Eighty percent anonymous is high; seventy-nine is not.
	 */
	public function test_high_anonymous_boundaries() {
		$this->as_admin();
		$this->create_running_test( 'high' );
		$this->create_running_test( 'low' );
		$stub = $this->use_provider( $this->counts( 100, 5, 100, 6, 20, 20 ) );
		$high = $this->stats_request( 'high' )->get_data();
		$this->assertSame( 0.8, $high['flags']['anonymous_share'] );
		$this->assertTrue( $high['flags']['high_anonymous'] );
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		$this->use_provider( $this->counts( 100, 5, 100, 6, 21, 21 ) );
		$low = $this->stats_request( 'low' )->get_data();
		$this->assertSame( 0.79, $low['flags']['anonymous_share'] );
		$this->assertFalse( $low['flags']['high_anonymous'] );
	}

	/**
	 * The provider's counting unit is passed through.
	 */
	public function test_unit_is_passed_through() {
		$this->as_admin();
		$this->create_running_test();
		$counts         = $this->counts( 100, 5, 100, 6 );
		$counts['unit'] = 'events';
		$this->use_provider( $counts );
		$this->assertSame( 'events', $this->stats_request( 't' )->get_data()['source']['unit'] );
	}

	/**
	 * Slugs of the Tools submenu after admin_menu runs.
	 *
	 * @return string[]
	 */
	private function tools_menu_slugs() {
		$GLOBALS['submenu'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the menu between tests.
		do_action( 'admin_menu' );
		return array_column( $GLOBALS['submenu']['tools.php'] ?? [], 2 );
	}

	/**
	 * With the flag on, Tools gains an A/B Tests page.
	 */
	public function test_menu_is_registered_with_the_flag_on() {
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$slugs = $this->tools_menu_slugs();
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$this->assertContains( Newspack_Popups_AB_Dashboard::PAGE_SLUG, $slugs );
	}

	/**
	 * With the flag off the page is not registered.
	 */
	public function test_menu_is_absent_with_the_flag_off() {
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$slugs = $this->tools_menu_slugs();
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->assertNotContains( Newspack_Popups_AB_Dashboard::PAGE_SLUG, $slugs );
	}

	/**
	 * Under the Audience menu the page sits directly below Campaigns, the
	 * breadcrumb links back to it, and the script registers under that hook.
	 */
	public function test_menu_sits_below_campaigns_in_the_audience_menu() {
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$GLOBALS['admin_page_hooks']['newspack-audience'] = 'audience'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for newspack-plugin's Audience menu.
		$register_audience = function () {
			add_submenu_page( 'newspack-audience', 'Campaigns', 'Campaigns', 'manage_options', 'newspack-audience-campaigns', '__return_null' );
			add_submenu_page( 'newspack-audience', 'Donations', 'Donations', 'manage_options', 'newspack-audience-donations', '__return_null' );
		};
		add_action( 'admin_menu', $register_audience );
		$GLOBALS['submenu'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the menu between tests.
		do_action( 'admin_menu' );
		$data = Newspack_Popups_AB_Dashboard::get_boot_data();
		remove_action( 'admin_menu', $register_audience );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		unset( $GLOBALS['admin_page_hooks']['newspack-audience'] );

		$this->assertSame(
			[ 'newspack-audience-campaigns', Newspack_Popups_AB_Dashboard::PAGE_SLUG, 'newspack-audience-donations' ],
			array_column( $GLOBALS['submenu']['newspack-audience'], 2 )
		);
		$this->assertTrue( $data['in_audience_menu'] );
		$this->assertStringContainsString( 'page=newspack-audience-campaigns', $data['campaigns_url'] );

		$this->plant_asset_fixtures( [ 'wp-element' ], 'audience-fixture' );
		$hook_property = new ReflectionProperty( Newspack_Popups_AB_Dashboard::class, 'page_hook' );
		$page_hook     = $hook_property->getValue();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		Newspack_Popups_AB_Dashboard::enqueue( $page_hook );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$hook_property->setValue( null, false );

		$this->assertNotEmpty( $page_hook );
		$this->assertArrayHasKey( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE, wp_scripts()->registered );
	}

	/**
	 * The body class marks this page and no other admin screen.
	 */
	public function test_body_class_only_on_the_page() {
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$this->tools_menu_slugs();
		$hook_property = new ReflectionProperty( Newspack_Popups_AB_Dashboard::class, 'page_hook' );
		$page_hook     = $hook_property->getValue();
		$GLOBALS['hook_suffix'] = $page_hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress sets it per admin request.
		$with_page              = Newspack_Popups_AB_Dashboard::add_body_class( 'wp-admin' );
		$GLOBALS['hook_suffix'] = 'toplevel_page_other'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$elsewhere              = Newspack_Popups_AB_Dashboard::add_body_class( 'wp-admin' );
		unset( $GLOBALS['hook_suffix'] );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$hook_property->setValue( null, false );

		$this->assertNotEmpty( $page_hook );
		$this->assertStringContainsString( Newspack_Popups_AB_Dashboard::BODY_CLASS, $with_page );
		$this->assertSame( 'wp-admin', $elsewhere );
	}

	/**
	 * The script's dependencies and version come from the build manifest, so a
	 * dependency the bundle gains is loaded without anyone editing a PHP list.
	 */
	public function test_page_script_is_registered_from_the_build_manifest() {
		$this->plant_asset_fixtures( [ 'wp-element', 'wp-api-fetch' ], 'ab-tests-fixture' );
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$this->tools_menu_slugs();
		$hook = get_plugin_page_hookname( Newspack_Popups_AB_Dashboard::PAGE_SLUG, 'tools.php' );
		Newspack_Popups_AB_Dashboard::enqueue( $hook );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$script = wp_scripts()->registered[ Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE ] ?? null;
		$this->assertNotNull( $script );
		// wp_set_script_translations() adds wp-i18n to whatever the manifest listed.
		$this->assertSame( [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ], $script->deps );
		$this->assertSame( 'ab-tests-fixture', $script->ver );
		$style = wp_styles()->registered[ Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE ] ?? null;
		$this->assertNotNull( $style );
		$this->assertSame( [ 'wp-components' ], $style->deps );
		$this->assertSame( 'ab-tests-fixture', $style->ver );
	}

	/**
	 * Without a build there is nothing to register, and nothing breaks.
	 */
	public function test_page_script_is_skipped_without_a_build() {
		$this->plant_asset_fixtures( [], 'unused' );
		foreach ( $this->asset_fixtures as $file ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Removes the fixture to stand in for an unbuilt checkout.
			unlink( $file );
		}
		$this->as_admin();
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$this->tools_menu_slugs();
		$hook = get_plugin_page_hookname( Newspack_Popups_AB_Dashboard::PAGE_SLUG, 'tools.php' );
		Newspack_Popups_AB_Dashboard::enqueue( $hook );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_true' );
		$this->assertArrayNotHasKey( Newspack_Popups_AB_Dashboard::SCRIPT_HANDLE, wp_scripts()->registered );
	}

	/**
	 * Boot data carries the tests, the provider state, the thresholds and the copy.
	 */
	public function test_boot_data_reports_provider_state_and_copy() {
		$this->as_admin();
		$this->create_running_test();
		$data = Newspack_Popups_AB_Dashboard::get_boot_data();
		$this->assertSame( 't', $data['tests'][0]['test_id'] );
		$this->assertFalse( $data['provider']['available'] );
		$this->assertNull( $data['provider']['slug'] );
		$this->assertSame( 'Results will show here once a stats source is connected.', $data['provider']['reason'] );
		$this->assertSame( 80, $data['thresholds']['directional'] );
		$this->assertSame( 95, $data['thresholds']['reliable'] );
		$this->assertSame( 30, $data['thresholds']['min_readers'] );
		$this->assertStringStartsWith( "Most readers in this test aren't logged in", $data['copy']['high_anonymous'] );
		$this->assertStringStartsWith( 'Too early to call a winner.', $data['copy']['young_test'] );
		$this->assertSame( 'Too early', $data['labels']['collecting'] );
		$this->assertFalse( $data['in_audience_menu'], 'Without the Audience menu the page sits under Tools.' );
		$this->assertStringContainsString( 'edit.php?post_type=' . Newspack_Popups::NEWSPACK_POPUPS_CPT, $data['campaigns_url'] );
		$this->assertSame( 'newspack-popups/v1', $data['rest_base'] );
	}

	/**
	 * An available provider is reported with its slug and no reason.
	 */
	public function test_boot_data_with_an_available_provider() {
		$this->use_provider( $this->counts( 1, 1, 1, 1 ) );
		$data = Newspack_Popups_AB_Dashboard::get_boot_data();
		$this->assertTrue( $data['provider']['available'] );
		$this->assertSame( 'stub', $data['provider']['slug'] );
		$this->assertNull( $data['provider']['reason'] );
	}
}
