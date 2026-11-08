<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for ending an A/B test and keeping one version.
 *
 * @package Newspack_Popups
 */

require_once __DIR__ . '/mocks/class-ab-stub-provider.php';

/**
 * End test.
 *
 * @group ab-tests
 */
class ABEndTestTest extends WP_UnitTestCase_PageWithPopups {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		remove_all_filters( 'newspack_popups_ab_testing_enabled' );
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		parent::tear_down();
	}

	/**
	 * A prompt in a test.
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
	 * A published test that started on a fixed past day, with a goal on A.
	 *
	 * @param string   $test_id Test ID.
	 * @param string[] $letters Versions.
	 * @return int[] Prompt IDs by letter.
	 */
	private function create_running_test( $test_id = 't', $letters = [ 'a', 'b' ] ) {
		$ids = [];
		foreach ( $letters as $letter ) {
			$ids[ $letter ] = $this->create_test_variant(
				$test_id,
				$letter,
				[
					'post_title'    => 'Version ' . strtoupper( $letter ),
					'post_date_gmt' => '2026-10-01 00:00:00',
					'post_date'     => '2026-10-01 00:00:00',
				]
			);
		}
		update_post_meta( $ids['a'], Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		update_post_meta( $ids['a'], Newspack_Popups_AB_Tests::META_CONTROL_SHARE, 60 );
		return $ids;
	}

	/**
	 * Register a stub provider.
	 *
	 * @param array|WP_Error $counts    Counts.
	 * @param true|WP_Error  $available Availability.
	 * @return AB_Stub_Provider
	 */
	private function use_provider( $counts, $available = true ) {
		remove_all_filters( 'newspack_popups_ab_stats_provider' );
		$stub = new AB_Stub_Provider( $counts, $available );
		add_filter( 'newspack_popups_ab_stats_provider', fn() => $stub );
		return $stub;
	}

	/**
	 * Counts for a two-version test.
	 *
	 * @param int $a_readers     A readers.
	 * @param int $a_conversions A conversions.
	 * @param int $b_readers     B readers.
	 * @param int $b_conversions B conversions.
	 * @return array
	 */
	private function counts( $a_readers, $a_conversions, $b_readers, $b_conversions ) {
		return [
			'unit'     => 'readers',
			'variants' => [
				'a' => [
					'readers'     => $a_readers,
					'conversions' => $a_conversions,
				],
				'b' => [
					'readers'     => $b_readers,
					'conversions' => $b_conversions,
				],
			],
		];
	}

	/**
	 * Send an end through the route.
	 *
	 * @param string      $test_id Test ID.
	 * @param string|null $keep    Version to keep.
	 * @return WP_REST_Response
	 */
	private function end_request( $test_id, $keep ) {
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/ab-tests/' . $test_id . '/end' );
		if ( null !== $keep ) {
			$request->set_param( 'keep', $keep );
		}
		return rest_do_request( $request );
	}

	/**
	 * Request a test's stats.
	 *
	 * @param string $test_id Test ID.
	 * @return WP_REST_Response
	 */
	private function stats_request( $test_id ) {
		return rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/' . $test_id . '/stats' ) );
	}

	/**
	 * The row get_tests() returns for one test.
	 *
	 * @param string $test_id Test ID.
	 * @return array[] Every row with that ID.
	 */
	private function rows( $test_id ) {
		return array_values(
			array_filter(
				Newspack_Popups_AB_Tests::get_tests(),
				fn( $test ) => $test_id === $test['test_id']
			)
		);
	}

	/**
	 * Sign in as a user with a role.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	private function sign_in( $role = 'administrator' ) {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Keeping B leaves B published, drafts A and takes both out of the test.
	 */
	public function test_keeping_b_drafts_a_and_clears_the_test_from_both() {
		$this->sign_in();
		$ids      = $this->create_running_test();
		$response = $this->end_request( 't', 'b' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $ids['b'] ) );
		$this->assertSame( 'draft', get_post_status( $ids['a'] ) );
		foreach ( $ids as $id ) {
			foreach ( [ Newspack_Popups_AB_Tests::META_TEST_ID, Newspack_Popups_AB_Tests::META_VARIANT, Newspack_Popups_AB_Tests::META_GOAL, Newspack_Popups_AB_Tests::META_CONTROL_SHARE ] as $key ) {
				$this->assertSame( '', get_post_meta( $id, $key, true ), "$key on prompt $id" );
			}
		}
		$this->assertArrayNotHasKey( 't', Newspack_Popups_AB_Tests::get_tests_config() );
	}

	/**
	 * Keeping A drafts B.
	 */
	public function test_keeping_a_drafts_b() {
		$this->sign_in();
		$ids = $this->create_running_test();
		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
		$this->assertSame( 'publish', get_post_status( $ids['a'] ) );
		$this->assertSame( 'draft', get_post_status( $ids['b'] ) );
	}

	/**
	 * With three versions, every one but the kept one goes to draft.
	 */
	public function test_keeping_c_drafts_every_other_version() {
		$this->sign_in();
		$ids = $this->create_running_test( 't', [ 'a', 'b', 'c' ] );
		$this->assertSame( 200, $this->end_request( 't', 'c' )->get_status() );
		$this->assertSame( 'draft', get_post_status( $ids['a'] ) );
		$this->assertSame( 'draft', get_post_status( $ids['b'] ) );
		$this->assertSame( 'publish', get_post_status( $ids['c'] ) );
	}

	/**
	 * The ended test stays listed, once, with what the card needs.
	 */
	public function test_ended_test_stays_listed_with_its_record() {
		$this->sign_in();
		$ids = $this->create_running_test();
		Newspack_Popups_AB_Tests::rename_test( 't', 'Donate banner' );
		$this->end_request( 't', 'b' );

		$rows = $this->rows( 't' );
		$this->assertCount( 1, $rows );
		$test = $rows[0];
		$this->assertSame( 'ended', $test['status'] );
		$this->assertSame( 'b', $test['kept'] );
		$this->assertSame( 'Donate banner', $test['name'] );
		$this->assertSame( 'donation', $test['goal'] );
		$this->assertSame( 60, $test['control_share'] );
		$this->assertSame( '2026-10-01T00:00:00Z', $test['started_at'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $test['ended_at'] );
		$this->assertSame( [ 'a', 'b' ], array_keys( $test['variants'] ) );
		$this->assertSame( $ids['b'], $test['variants']['b']['id'] );
		$this->assertSame( 'Version B', $test['variants']['b']['title'] );
	}

	/**
	 * A test that hasn't ended is listed as active.
	 */
	public function test_running_test_is_active() {
		$this->create_running_test();
		$this->assertSame( 'active', $this->rows( 't' )[0]['status'] );
	}

	/**
	 * The record is a snapshot: renaming the kept prompt afterwards doesn't
	 * change the version's title on the ended card.
	 */
	public function test_record_keeps_the_titles_from_the_end() {
		$this->sign_in();
		$ids = $this->create_running_test();
		$this->end_request( 't', 'b' );
		wp_update_post(
			[
				'ID'         => $ids['b'],
				'post_title' => 'Renamed later',
			]
		);
		$this->assertSame( 'Version B', $this->rows( 't' )[0]['variants']['b']['title'] );
	}

	/**
	 * Starting a new test on the kept prompt leaves the ended test as it was.
	 */
	public function test_retesting_the_kept_prompt_leaves_the_record() {
		$this->sign_in();
		$ids = $this->create_running_test();
		$this->end_request( 't', 'b' );
		$before = $this->rows( 't' )[0];

		$started = Newspack_Popups_AB_Tests::start_test( $ids['b'], 'Second round', 'donation', 50 );
		$this->assertIsArray( $started );
		$this->assertNotSame( 't', $started['test_id'] );
		$this->assertSame( $before, $this->rows( 't' )[0] );
	}

	/**
	 * A test still being set up can't be ended.
	 */
	public function test_draft_test_cannot_be_ended() {
		$this->sign_in();
		$this->create_test_variant( 't', 'a' );
		$b = $this->create_test_variant( 't', 'b', [ 'post_status' => 'draft' ] );
		$response = $this->end_request( 't', 'a' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'draft', get_post_status( $b ) );
		$this->assertSame( 'active', $this->rows( 't' )[0]['status'] );
	}

	/**
	 * Keep must name a version the test has.
	 */
	public function test_keep_must_be_one_of_the_test_versions() {
		$this->sign_in();
		$this->create_running_test();
		$this->assertSame( 400, $this->end_request( 't', 'c' )->get_status() );
		$this->assertSame( 400, $this->end_request( 't', 'z' )->get_status() );
		$this->assertSame( 400, $this->end_request( 't', null )->get_status() );
	}

	/**
	 * An unknown test is refused, as a rename of one is.
	 */
	public function test_unknown_test_is_refused() {
		$this->sign_in();
		$this->assertSame( 403, $this->end_request( 'nope', 'a' )->get_status() );
	}

	/**
	 * Ending needs permission to change every prompt in the test.
	 */
	public function test_ending_needs_every_prompt() {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$own       = $this->create_test_variant( 't', 'a', [ 'post_author' => $author_id ] );
		$other     = $this->create_test_variant( 't', 'b' );
		wp_set_current_user( $author_id );
		$this->assertSame( 403, $this->end_request( 't', 'a' )->get_status() );
		$this->assertSame( 'publish', get_post_status( $other ) );
		$this->assertSame( 't', get_post_meta( $own, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * An editor, who can edit every prompt, can end a test.
	 */
	public function test_editor_can_end_a_test() {
		$this->sign_in( 'editor' );
		$this->create_running_test();
		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
	}

	/**
	 * Ending twice keeps the first record and still answers with it.
	 */
	public function test_ending_twice_keeps_the_first_record() {
		$this->sign_in();
		$this->create_running_test();
		$first  = $this->end_request( 't', 'b' )->get_data();
		$second = $this->end_request( 't', 'a' );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'b', $this->rows( 't' )[0]['kept'] );
		$this->assertSame( $first['ended_at'], $this->rows( 't' )[0]['ended_at'] );
	}

	/**
	 * A trashed prompt leaves the test too, so restoring it can't revive it.
	 */
	public function test_trashed_prompt_leaves_the_test() {
		$this->sign_in();
		$this->create_running_test();
		$trashed = $this->create_test_variant( 't', 'b', [ 'post_status' => 'trash' ] );
		$this->end_request( 't', 'a' );
		$this->assertSame( '', get_post_meta( $trashed, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * An ended test can still be renamed.
	 */
	public function test_ended_test_can_be_renamed() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/ab-tests/t/name' );
		$request->set_param( 'name', 'Summer appeal' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'Summer appeal', $this->rows( 't' )[0]['name'] );
	}

	/**
	 * Final results are taken at the end, for the test's own dates, and never
	 * fetched again.
	 */
	public function test_final_results_are_frozen_at_the_end() {
		$this->sign_in();
		$this->create_running_test();
		$stub = $this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$this->end_request( 't', 'b' );
		$this->assertCount( 1, $stub->calls );
		$this->assertSame( '2026-10-01', $stub->calls[0]['start'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $stub->calls[0]['end'] );

		$later = $this->use_provider( $this->counts( 5000, 10, 5000, 10 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertCount( 0, $later->calls );
		$this->assertSame( 27, $stats['variants']['b']['conversions'] );
		$this->assertSame( 'ok', $stats['source']['state'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $stats['window']['end'] );
	}

	/**
	 * With the stats source down at the end, the test still ends, the card
	 * says the source is unavailable, and the first answer later is frozen.
	 */
	public function test_results_unavailable_at_the_end_are_frozen_on_the_first_answer() {
		$this->sign_in();
		$this->create_running_test();
		$this->use_provider( null, new WP_Error( 'down', 'Not connected.' ) );
		$this->assertSame( 200, $this->end_request( 't', 'b' )->get_status() );
		$this->assertSame( 'unavailable', $this->stats_request( 't' )->get_data()['source']['state'] );

		$this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$this->assertSame( 27, $this->stats_request( 't' )->get_data()['variants']['b']['conversions'] );

		$after = $this->use_provider( $this->counts( 5000, 10, 5000, 10 ) );
		$this->assertSame( 27, $this->stats_request( 't' )->get_data()['variants']['b']['conversions'] );
		$this->assertCount( 0, $after->calls );
	}

	/**
	 * An ended test's age stops at its end date.
	 */
	public function test_days_running_stop_at_the_end() {
		$this->sign_in();
		$this->create_running_test();
		$this->use_provider( $this->counts( 10, 1, 10, 1 ) );
		$this->end_request( 't', 'b' );
		$record = $this->rows( 't' )[0];
		$days   = (int) floor( ( strtotime( $record['ended_at'] ) - strtotime( '2026-10-01T00:00:00Z' ) ) / DAY_IN_SECONDS );
		$this->assertSame( $days, $this->stats_request( 't' )->get_data()['flags']['days_running'] );
	}

	/**
	 * Results taken after the end, such as once the source is back, still stop
	 * at the day the test ended.
	 */
	public function test_late_results_stop_at_the_end_date() {
		$this->sign_in();
		$this->create_running_test();
		$this->use_provider( null, new WP_Error( 'down', 'Not connected.' ) );
		$this->end_request( 't', 'b' );
		// The test ended on an earlier day than this read.
		$term               = get_term_by( 'slug', 't', Newspack_Popups_AB_Tests::TAXONOMY );
		$record             = get_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_ENDED, true );
		$record['ended_at'] = '2026-10-20T12:00:00Z';
		update_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_ENDED, $record );

		$stub  = $this->use_provider( $this->counts( 10, 1, 10, 1 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertSame( '2026-10-20', $stub->calls[0]['end'] );
		$this->assertSame( '2026-10-20', $stats['window']['end'] );
		$this->assertSame( 19, $stats['flags']['days_running'] );
	}

	/**
	 * With the flag off the route does not exist.
	 */
	public function test_route_is_absent_with_the_flag_off() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->sign_in();
		$this->create_running_test();
		$response = $this->end_request( 't', 'a' );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
	}
}
