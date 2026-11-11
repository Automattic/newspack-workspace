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
		// Recorded with the flag on only; backdated to the day the test stands for.
		$term = get_term_by( 'slug', $test_id, Newspack_Popups_AB_Tests::TAXONOMY );
		if ( $term ) {
			update_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_STARTED, '2026-10-01T00:00:00Z' );
		}
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
	 * A test still being set up can end without publishing B: A keeps its
	 * status and both prompts leave the test.
	 */
	public function test_draft_test_ends_keeping_a() {
		$this->sign_in();
		$a = $this->create_test_variant( 't', 'a' );
		$b = $this->create_test_variant( 't', 'b', [ 'post_status' => 'draft' ] );

		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
		$this->assertSame( 'publish', get_post_status( $a ) );
		$this->assertSame( 'draft', get_post_status( $b ) );
		foreach ( [ $a, $b ] as $id ) {
			$this->assertSame( '', get_post_meta( $id, Newspack_Popups_AB_Tests::META_TEST_ID, true ), "test ID on prompt $id" );
		}
		$this->assertSame( 'ended', $this->rows( 't' )[0]['status'] );
	}

	/**
	 * Keeping a draft B never publishes it; A goes to draft with the rest.
	 */
	public function test_draft_test_ends_keeping_b_without_publishing_it() {
		$this->sign_in();
		$a = $this->create_test_variant( 't', 'a' );
		$b = $this->create_test_variant( 't', 'b', [ 'post_status' => 'draft' ] );

		$this->assertSame( 200, $this->end_request( 't', 'b' )->get_status() );
		$this->assertSame( 'draft', get_post_status( $a ) );
		$this->assertSame( 'draft', get_post_status( $b ) );
		$this->assertSame( 'b', $this->rows( 't' )[0]['kept'] );
	}

	/**
	 * A test with only version A, before B is made or after it is deleted,
	 * can end too, and A leaves it as it is.
	 */
	public function test_test_with_only_version_a_ends() {
		$this->sign_in();
		$a = $this->create_test_variant( 't', 'a' );

		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
		$this->assertSame( 'publish', get_post_status( $a ) );
		$this->assertSame( '', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 'ended', $this->rows( 't' )[0]['status'] );
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
	 * Ending a test that has already ended is refused, and the first record stays.
	 */
	public function test_ending_an_ended_test_is_refused() {
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
	 * Move an ended test's end back in time.
	 *
	 * @param string $test_id  Test ID.
	 * @param string $ended_at When it ended.
	 */
	private function backdate_end( $test_id, $ended_at ) {
		$term               = get_term_by( 'slug', $test_id, Newspack_Popups_AB_Tests::TAXONOMY );
		$record             = get_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_ENDED, true );
		$record['ended_at'] = $ended_at;
		update_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_ENDED, $record );
	}

	/**
	 * Results aren't final until the end date has settled: until then they come
	 * from the source, for the test's own dates, cached as a running test's are.
	 */
	public function test_results_are_not_final_until_the_end_has_settled() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );

		$stub  = $this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertSame( 27, $stats['variants']['b']['conversions'] );
		$this->assertFalse( $stats['source']['final'] );
		$this->assertSame( '2026-10-01', $stub->calls[0]['start'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $stub->calls[0]['end'] );

		$later = $this->use_provider( $this->counts( 1000, 19, 1000, 30 ) );
		$this->assertSame( 27, $this->stats_request( 't' )->get_data()['variants']['b']['conversions'] );
		$this->assertCount( 0, $later->calls );
	}

	/**
	 * Once the end date has settled, the first answer is frozen and the source
	 * isn't asked again.
	 */
	public function test_settled_results_are_frozen() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$this->backdate_end( 't', gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ) );

		$this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$this->assertTrue( $this->stats_request( 't' )->get_data()['source']['final'] );

		$after = $this->use_provider( $this->counts( 5000, 10, 5000, 10 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertCount( 0, $after->calls );
		$this->assertSame( 27, $stats['variants']['b']['conversions'] );
		$this->assertSame( 'ok', $stats['source']['state'] );
		$this->assertTrue( $stats['source']['final'] );
	}

	/**
	 * An answer with no data yet is never frozen, even once settled.
	 */
	public function test_an_empty_answer_is_not_frozen() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$this->backdate_end( 't', gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ) );

		$this->use_provider(
			[
				'unit'     => 'readers',
				'variants' => [],
			]
		);
		$this->assertFalse( $this->stats_request( 't' )->get_data()['source']['final'] );

		// The empty answer is cached like any other, so the source is asked again
		// once the cache expires, not on every read.
		$cached = $this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$this->assertFalse( $this->stats_request( 't' )->get_data()['source']['final'] );
		$this->assertCount( 0, $cached->calls );

		$this->expire_stats_cache();
		$this->assertTrue( $this->stats_request( 't' )->get_data()['source']['final'] );
	}

	/**
	 * A copy cached before the end settled is fetched again before it is frozen.
	 */
	public function test_results_cached_before_settling_are_fetched_again() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$ended_at = gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS );
		$this->backdate_end( 't', $ended_at );
		// A copy cached the day the test ended, before it settled.
		$test  = $this->rows( 't' )[0];
		$key   = new ReflectionMethod( 'Newspack_Popups_AB_Dashboard', 'cache_key' );
		$key->setAccessible( true );
		$stale = $this->use_provider( $this->counts( 1000, 19, 1000, 20 ) );
		set_transient(
			$key->invoke( null, $test, $stale->get_slug() ),
			[
				'counts'     => $this->counts( 1000, 19, 1000, 20 ),
				'fetched_at' => $ended_at,
			],
			HOUR_IN_SECONDS
		);

		$fresh = $this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertCount( 1, $fresh->calls );
		$this->assertSame( 27, $stats['variants']['b']['conversions'] );
		$this->assertTrue( $stats['source']['final'] );
	}

	/**
	 * When that second fetch fails, the cached copy is still shown, unfrozen.
	 */
	public function test_a_failed_refetch_keeps_the_cached_results() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$ended_at = gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS );
		$this->backdate_end( 't', $ended_at );
		$test  = $this->rows( 't' )[0];
		$key   = new ReflectionMethod( 'Newspack_Popups_AB_Dashboard', 'cache_key' );
		$key->setAccessible( true );
		$stale = $this->use_provider( $this->counts( 1000, 19, 1000, 20 ) );
		set_transient(
			$key->invoke( null, $test, $stale->get_slug() ),
			[
				'counts'     => $this->counts( 1000, 19, 1000, 20 ),
				'fetched_at' => $ended_at,
			],
			HOUR_IN_SECONDS
		);

		$this->use_provider( new WP_Error( 'down', 'The source is down.' ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertSame( 20, $stats['variants']['b']['conversions'] );
		$this->assertFalse( $stats['source']['final'] );
		$this->assertNull( Newspack_Popups_AB_Tests::get_final_counts( 't' ) );
	}

	/**
	 * Forget every cached stats answer, as an hour passing would.
	 */
	private function expire_stats_cache() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
	}

	/**
	 * With the stats source down, the card says it is unavailable, and the first
	 * answer after the end has settled is frozen.
	 */
	public function test_results_unavailable_are_frozen_on_the_first_settled_answer() {
		$this->sign_in();
		$this->create_running_test();
		$this->use_provider( null, new WP_Error( 'down', 'Not connected.' ) );
		$this->assertSame( 200, $this->end_request( 't', 'b' )->get_status() );
		$this->assertSame( 'unavailable', $this->stats_request( 't' )->get_data()['source']['state'] );

		$this->backdate_end( 't', gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * DAY_IN_SECONDS ) );
		$this->use_provider( $this->counts( 1000, 19, 1000, 27 ) );
		$this->assertSame( 27, $this->stats_request( 't' )->get_data()['variants']['b']['conversions'] );

		$after = $this->use_provider( $this->counts( 5000, 10, 5000, 10 ) );
		$this->assertSame( 27, $this->stats_request( 't' )->get_data()['variants']['b']['conversions'] );
		$this->assertCount( 0, $after->calls );
	}

	/**
	 * Two ends at once, each passing core's unique check before the other's
	 * insert: only the first record stands, and the later request drafts nothing.
	 */
	public function test_simultaneous_ends_let_only_the_first_through() {
		global $wpdb;
		$this->sign_in();
		$ids = $this->create_running_test();
		// As if a reader request had seen the rival's record and stored that no
		// test is running.
		update_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS, '0' );
		// The other request's insert lands after this one's check and before its own.
		$rival = function ( $term_id, $meta_key ) use ( $wpdb ) {
			if ( Newspack_Popups_AB_Tests::TERM_META_ENDED !== $meta_key ) {
				return;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->termmeta,
				[
					'term_id'    => $term_id,
					'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => maybe_serialize( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						[
							'ended_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
							'kept'     => 'a',
							'versions' => [],
							'claim'    => 'rival',
						]
					),
				]
			);
		};
		add_action( 'add_term_meta', $rival, 10, 2 );
		$response = $this->end_request( 't', 'b' );
		remove_action( 'add_term_meta', $rival, 10 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $ids['a'] ) );
		$term = get_term_by( 'slug', 't', Newspack_Popups_AB_Tests::TAXONOMY );
		wp_cache_delete( $term->term_id, 'term_meta' );
		$records = get_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_ENDED, false );
		$this->assertCount( 1, $records );
		$this->assertSame( 'rival', $records[0]['claim'] );
		$this->assertNotSame( '0', get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );
	}

	/**
	 * A draft that fails puts back what was drafted and leaves the test running,
	 * so it can be ended again, and its readers keep their buckets.
	 */
	public function test_a_failed_draft_leaves_the_test_running() {
		$this->sign_in();
		$ids    = $this->create_running_test( 't', [ 'a', 'b', 'c' ] );
		$reader = $this->bucket_reader( 't', 'b' );
		// A backslash in a title: the record must still match to be removed.
		wp_update_post(
			[
				'ID'         => $ids['b'],
				'post_title' => wp_slash( 'Path C:\\Users' ),
			]
		);
		// The second draft fails, whichever version that is, so one is put back.
		$attempts = 0;
		$fail     = function ( $maybe_empty, $postarr ) use ( &$attempts ) {
			if ( 'draft' === ( $postarr['post_status'] ?? '' ) && ++$attempts === 2 ) {
				return true;
			}
			return $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$response = $this->end_request( 't', 'a' );
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );

		$this->assertSame( 2, $attempts, 'The end reached the second draft.' );
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		foreach ( $ids as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertSame( 't', get_post_meta( $id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		}
		$this->assertSame( 'active', $this->rows( 't' )[0]['status'] );
		$this->assertSame( 'b', $this->stored_bucket( $reader, 't' ) );
		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
	}

	/**
	 * A reader request that lands mid-end, while the record exists, doesn't leave
	 * the site believing no test is running once a failed end puts it back.
	 */
	public function test_a_failed_end_keeps_the_test_splitting_readers() {
		$this->sign_in();
		$this->create_running_test();
		$fail = function () {
			Newspack_Popups_AB_Tests::reset_config_cache();
			Newspack_Popups_AB_Tests::get_tests_config();
			return true;
		};
		add_filter( 'wp_insert_post_empty_content', $fail );
		$this->end_request( 't', 'a' );
		remove_filter( 'wp_insert_post_empty_content', $fail );

		// Not reset_config_cache(), which would clear the flag this is about.
		$this->assertNotSame( '0', get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );
		$this->assertArrayHasKey( 't', Newspack_Popups_AB_Tests::get_tests_config() );
	}

	/**
	 * A title's backslash survives into the record.
	 */
	public function test_record_keeps_a_backslash_in_a_title() {
		$this->sign_in();
		$ids = $this->create_running_test();
		wp_update_post(
			[
				'ID'         => $ids['b'],
				'post_title' => wp_slash( 'Path C:\\Users' ),
			]
		);
		$this->end_request( 't', 'a' );
		$this->assertSame( 'Path C:\\Users', $this->rows( 't' )[0]['variants']['b']['title'] );
	}

	/**
	 * Giving a prompt of one's own an ended test's ID doesn't let an author
	 * rename that test.
	 */
	public function test_joining_an_ended_test_does_not_grant_rename() {
		$this->sign_in();
		$this->create_running_test();
		$this->end_request( 't', 'b' );
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->create_test_variant( 't', 'a', [ 'post_author' => $author_id ] );
		wp_set_current_user( $author_id );
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/ab-tests/t/name' );
		$request->set_param( 'name', 'Mine now' );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * Prompts given an ended test's ID again don't split readers.
	 */
	public function test_ended_test_is_left_out_of_the_reader_config() {
		$this->sign_in();
		$ids = $this->create_running_test();
		$this->end_request( 't', 'b' );
		foreach ( $ids as $letter => $id ) {
			wp_update_post(
				[
					'ID'          => $id,
					'post_status' => 'publish',
				]
			);
			update_post_meta( $id, Newspack_Popups_AB_Tests::META_TEST_ID, 't' );
			update_post_meta( $id, Newspack_Popups_AB_Tests::META_VARIANT, $letter );
		}
		$this->create_running_test( 'u' );
		Newspack_Popups_AB_Tests::reset_config_cache();
		$config = Newspack_Popups_AB_Tests::get_tests_config();
		$this->assertArrayNotHasKey( 't', $config );
		$this->assertArrayHasKey( 'u', $config );
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
		$this->backdate_end( 't', '2026-10-20T12:00:00Z' );

		$stub  = $this->use_provider( $this->counts( 10, 1, 10, 1 ) );
		$stats = $this->stats_request( 't' )->get_data();
		$this->assertSame( '2026-10-20', $stub->calls[0]['end'] );
		$this->assertSame( '2026-10-20', $stats['window']['end'] );
		$this->assertSame( 19, $stats['flags']['days_running'] );
	}

	/**
	 * Give a reader a stored bucket in a test.
	 *
	 * @param string $test_id Test ID.
	 * @param string $bucket  Variant letter.
	 * @return int Reader ID.
	 */
	private function bucket_reader( $test_id, $bucket = 'a' ) {
		$reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $reader_id, Newspack_Popups_AB_Tests::bucket_meta_key( $test_id ), $bucket );
		return $reader_id;
	}

	/**
	 * A reader's stored bucket, read through the meta cache, so a test that
	 * reads it before the drop also catches a stale cache.
	 *
	 * @param int    $reader_id Reader ID.
	 * @param string $test_id   Test ID.
	 * @return string Stored bucket, or '' when none.
	 */
	private function stored_bucket( $reader_id, $test_id ) {
		return (string) get_user_meta( $reader_id, Newspack_Popups_AB_Tests::bucket_meta_key( $test_id ), true );
	}

	/**
	 * Ending a test drops every reader's bucket in it, and only in it.
	 */
	public function test_ending_drops_the_test_buckets_for_every_reader() {
		$this->sign_in();
		$this->create_running_test();
		$this->create_running_test( 'other' );
		$first  = $this->bucket_reader( 't', 'a' );
		$second = $this->bucket_reader( 't', 'b' );
		$other  = $this->bucket_reader( 'other', 'b' );

		$this->assertSame( 200, $this->end_request( 't', 'a' )->get_status() );
		$this->assertSame( '', $this->stored_bucket( $first, 't' ) );
		$this->assertSame( '', $this->stored_bucket( $second, 't' ) );
		$this->assertSame( 'b', $this->stored_bucket( $other, 'other' ) );
	}

	/**
	 * Deleting the last prompt that carries a test's ID drops its buckets.
	 */
	public function test_deleting_every_prompt_drops_the_buckets() {
		$ids    = $this->create_running_test();
		$reader = $this->bucket_reader( 't', 'b' );

		wp_delete_post( $ids['b'], true );
		$this->assertSame( 'b', $this->stored_bucket( $reader, 't' ), 'A still carries the test.' );

		wp_delete_post( $ids['a'], true );
		$this->assertSame( '', $this->stored_bucket( $reader, 't' ) );
	}

	/**
	 * A trashed prompt still holds the test's ID, so trashing keeps the buckets
	 * until the trash is emptied.
	 */
	public function test_trashing_keeps_the_buckets_until_the_trash_is_emptied() {
		$ids    = $this->create_running_test();
		$reader = $this->bucket_reader( 't', 'a' );

		wp_trash_post( $ids['a'] );
		wp_trash_post( $ids['b'] );
		$this->assertSame( 'a', $this->stored_bucket( $reader, 't' ) );

		wp_delete_post( $ids['a'], true );
		$this->assertSame( 'a', $this->stored_bucket( $reader, 't' ), 'B, in the trash, still carries the test.' );
		wp_delete_post( $ids['b'], true );
		$this->assertSame( '', $this->stored_bucket( $reader, 't' ) );
	}

	/**
	 * Clearing the Test ID on the last prompt drops the buckets, like deleting it.
	 */
	public function test_clearing_the_last_test_id_drops_the_buckets() {
		$ids    = $this->create_running_test();
		$reader = $this->bucket_reader( 't', 'a' );

		delete_post_meta( $ids['b'], Newspack_Popups_AB_Tests::META_TEST_ID );
		$this->assertSame( 'a', $this->stored_bucket( $reader, 't' ), 'A still carries the test.' );

		update_post_meta( $ids['a'], Newspack_Popups_AB_Tests::META_TEST_ID, '' );
		$this->assertSame( '', $this->stored_bucket( $reader, 't' ) );
	}

	/**
	 * Moving the last prompt to another Test ID drops the old test's buckets
	 * and leaves the new one's alone.
	 */
	public function test_changing_the_last_test_id_drops_the_old_buckets() {
		$ids   = $this->create_running_test();
		$old   = $this->bucket_reader( 't', 'a' );
		$other = $this->bucket_reader( 'u', 'b' );

		update_post_meta( $ids['a'], Newspack_Popups_AB_Tests::META_TEST_ID, 'u' );
		update_post_meta( $ids['b'], Newspack_Popups_AB_Tests::META_TEST_ID, 'u' );
		$this->assertSame( '', $this->stored_bucket( $old, 't' ) );
		$this->assertSame( 'b', $this->stored_bucket( $other, 'u' ) );
	}

	/**
	 * With the flag off nothing reads the buckets, and deleting the last
	 * prompt still drops them.
	 */
	public function test_deleting_every_prompt_drops_the_buckets_with_the_flag_off() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$ids    = $this->create_running_test();
		$reader = $this->bucket_reader( 't', 'b' );
		wp_delete_post( $ids['a'], true );
		wp_delete_post( $ids['b'], true );
		$this->assertSame( '', $this->stored_bucket( $reader, 't' ) );
	}

	/**
	 * Deleting a prompt outside any test leaves every bucket alone.
	 */
	public function test_deleting_an_untested_prompt_keeps_the_buckets() {
		$this->create_running_test();
		$reader = $this->bucket_reader( 't', 'a' );
		wp_delete_post( $this->createPopup(), true );
		$this->assertSame( 'a', $this->stored_bucket( $reader, 't' ) );
	}

	/**
	 * Emptying a test drops only this site's buckets: another site in the network
	 * using the same Test ID, and the unprefixed key every site used to share,
	 * keep their rows.
	 */
	public function test_emptying_a_test_keeps_another_sites_buckets() {
		global $wpdb;
		$ids        = $this->create_running_test();
		$reader     = $this->bucket_reader( 't', 'a' );
		$other_site = $wpdb->base_prefix . '99_' . Newspack_Popups_AB_Tests::USER_META_BUCKET_PREFIX . 't';
		$unprefixed = Newspack_Popups_AB_Tests::USER_META_BUCKET_PREFIX . 't';
		update_user_meta( $reader, $other_site, 'b' );
		update_user_meta( $reader, $unprefixed, 'b' );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->assertSame( '', $this->stored_bucket( $reader, 't' ) );
		$this->assertSame( 'b', get_user_meta( $reader, $other_site, true ) );
		$this->assertSame( 'b', get_user_meta( $reader, $unprefixed, true ) );
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
