<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ABTests Test
 *
 * @package Newspack_Popups
 */

require_once __DIR__ . '/mocks/class-audience-campaigns.php';

/**
 * A/B tests display-integration test case.
 *
 * @group ab-tests
 */
class ABTestsTest extends WP_UnitTestCase_PageWithPopups {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		unset( $GLOBALS['admin_page_hooks']['newspack-audience'] );
		parent::tear_down();
	}

	/**
	 * Create a prompt participating in an A/B test.
	 *
	 * @param string $test_id Test ID.
	 * @param string $variant Variant key.
	 * @param array  $options Popup options.
	 * @param array  $post_options Post options (e.g. post_status).
	 * @param int    $control_share Control share, stored on variant A only.
	 * @return int Popup ID.
	 */
	private function create_test_variant( $test_id, $variant, $options = null, $post_options = [], $control_share = 0 ) {
		$popup_id = $this->createPopup( null, $options, $post_options );
		update_post_meta( $popup_id, Newspack_Popups_AB_Tests::META_TEST_ID, $test_id );
		update_post_meta( $popup_id, Newspack_Popups_AB_Tests::META_VARIANT, $variant );
		if ( $control_share ) {
			update_post_meta( $popup_id, Newspack_Popups_AB_Tests::META_CONTROL_SHARE, $control_share );
		}
		return $popup_id;
	}

	/**
	 * The dashboard lists drafts and tests missing an arm; trashed prompts are gone.
	 */
	public function test_get_tests_lists_drafts_and_incomplete_tests() {
		$this->create_test_variant( 'draft-test', 'a', null, [ 'post_status' => 'draft' ] );
		$this->create_test_variant( 'lonely', 'a' );
		$this->create_test_variant( 'gone', 'a', null, [ 'post_status' => 'trash' ] );
		$ids = array_column( Newspack_Popups_AB_Tests::get_tests(), 'test_id' );
		$this->assertSame( [ 'draft-test', 'lonely' ], $ids );
	}

	/**
	 * A prompt on a manual or custom placement is reported as ineligible, so the
	 * page can say why the test can't run.
	 */
	public function test_get_tests_reports_variants_on_ineligible_placements() {
		$this->create_test_variant( 'elig', 'a' );
		$manual = $this->create_test_variant( 'elig', 'b' );
		update_post_meta( $manual, 'placement', 'manual' );
		$test = Newspack_Popups_AB_Tests::get_tests()[0];
		$this->assertTrue( $test['variants']['a']['eligible'] );
		$this->assertFalse( $test['variants']['b']['eligible'] );
	}

	/**
	 * An overlay set to Manual only is never inserted on its own, so it is
	 * ineligible like a manual placement, whatever wrote the setting.
	 */
	public function test_get_tests_reports_a_manual_only_overlay_as_ineligible() {
		$this->create_test_variant( 'mo', 'a' );
		$overlay = $this->create_test_variant( 'mo', 'b' );
		update_post_meta( $overlay, 'placement', 'center' );
		update_post_meta( $overlay, 'manual_only', '1' );
		$test = Newspack_Popups_AB_Tests::get_tests()[0];
		$this->assertTrue( $test['variants']['a']['eligible'] );
		$this->assertFalse( $test['variants']['b']['eligible'] );
	}

	/**
	 * A legacy Test ID with an underscore is its own test, as readers see it,
	 * not part of the hyphenated test it would sanitize to.
	 */
	public function test_get_tests_keeps_a_legacy_test_id_apart_from_its_hyphenated_spelling() {
		global $wpdb;
		$legacy = [ $this->create_test_variant( 'footer-test', 'a' ), $this->create_test_variant( 'footer-test', 'b' ) ];
		$this->create_test_variant( 'footer-test', 'a' );
		$this->create_test_variant( 'footer-test', 'b' );
		foreach ( $legacy as $prompt_id ) {
			// A value the earlier sanitizer stored; the current one can't write it.
			$wpdb->update( // phpcs:ignore WordPress.DB
				$wpdb->postmeta,
				[ 'meta_value' => 'footer_test' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				[
					'post_id'  => $prompt_id,
					'meta_key' => Newspack_Popups_AB_Tests::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				]
			);
			wp_cache_delete( $prompt_id, 'post_meta' );
		}
		$tests = array_column( Newspack_Popups_AB_Tests::get_tests(), null, 'test_id' );
		$this->assertSame( [ 'footer-test', 'footer_test' ], array_keys( $tests ) );
		$this->assertSame( [], $tests['footer_test']['duplicates'] );
		$this->assertSame( [], $tests['footer-test']['duplicates'] );
		$this->assertSame( $legacy[0], $tests['footer_test']['variants']['a']['id'] );
	}

	/**
	 * The control share is read from the control; one stored on a challenger is ignored.
	 */
	public function test_get_tests_control_share_defaults_to_fifty_even_when_set_on_a_challenger() {
		$this->create_test_variant( 'cs', 'a' );
		$this->create_test_variant( 'cs', 'b', null, [], 70 );
		$this->assertSame( 50, Newspack_Popups_AB_Tests::get_tests()[0]['control_share'] );
	}

	/**
	 * The control's stored share is reported.
	 */
	public function test_get_tests_reads_control_share_from_the_control() {
		$this->create_test_variant( 'cs2', 'a', null, [], 60 );
		$this->assertSame( 60, Newspack_Popups_AB_Tests::get_tests()[0]['control_share'] );
	}

	/**
	 * A test recorded before starts were stored starts when the split did: the
	 * later of the control's and the challenger's publish dates.
	 */
	public function test_get_tests_started_at_falls_back_to_publish_dates_without_a_record() {
		$this->create_test_variant(
			'dates',
			'a',
			null,
			[
				'post_date_gmt' => '2026-10-05 00:00:00',
				'post_date'     => '2026-10-05 00:00:00',
			]
		);
		$this->create_test_variant(
			'dates',
			'b',
			null,
			[
				'post_date_gmt' => '2026-10-01 00:00:00',
				'post_date'     => '2026-10-01 00:00:00',
			]
		);
		$this->forget_start( 'dates' );
		$this->assertSame( '2026-10-05T00:00:00Z', Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
	}

	/**
	 * Publishing the challenger starts the test then, not at the prompts'
	 * publish dates.
	 */
	public function test_publishing_the_challenger_records_the_start() {
		$this->create_test_variant( 'go', 'a', null, $this->backdated() );
		$b = $this->create_test_variant( 'go', 'b', null, [ 'post_status' => 'draft' ] );
		$this->assertNull( Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
		wp_publish_post( $b );
		$this->assertStartedNow( 'go' );
	}

	/**
	 * A challenger published before its control starts the test when the
	 * control goes live.
	 */
	public function test_publishing_the_control_last_records_the_start() {
		$a = $this->create_test_variant( 'late-a', 'a', null, [ 'post_status' => 'draft' ] );
		$this->create_test_variant( 'late-a', 'b', null, $this->backdated() );
		$this->assertNull( $this->recorded_start( 'late-a' ) );
		wp_publish_post( $a );
		$this->assertStartedNow( 'late-a' );
	}

	/**
	 * A scheduled challenger starts the test when it goes live, which is when
	 * cron publishes it.
	 */
	public function test_a_scheduled_challenger_records_the_start_when_it_goes_live() {
		$this->create_test_variant( 'sched', 'a', null, $this->backdated() );
		$future = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
		$b      = $this->create_test_variant(
			'sched',
			'b',
			null,
			[
				'post_status'   => 'future',
				'post_date_gmt' => $future,
				'post_date'     => $future,
			]
		);
		$this->assertNull( $this->recorded_start( 'sched' ) );
		// What cron's check_and_publish_future_post() calls once the date arrives.
		wp_publish_post( $b );
		$this->assertSame( 'publish', get_post_status( $b ) );
		$this->assertStartedNow( 'sched' );
	}

	/**
	 * Two prompts published weeks ago start a test when they are put in one,
	 * so its results don't read as weeks old.
	 */
	public function test_putting_two_live_prompts_in_a_test_records_the_start() {
		$a = $this->createPopup( null, null, $this->backdated() );
		$b = $this->createPopup( null, null, $this->backdated() );
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, 'joined' );
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_VARIANT, 'a' );
		update_post_meta( $b, Newspack_Popups_AB_Tests::META_TEST_ID, 'joined' );
		$this->assertNull( $this->recorded_start( 'joined' ) );
		update_post_meta( $b, Newspack_Popups_AB_Tests::META_VARIANT, 'b' );
		$this->assertStartedNow( 'joined' );
	}

	/**
	 * Unpublishing and republishing the challenger keeps the first start, since
	 * the results still count from then.
	 */
	public function test_republishing_keeps_the_first_start() {
		$this->create_test_variant( 'again', 'a' );
		$b = $this->create_test_variant( 'again', 'b' );
		$this->set_recorded_start( 'again', '2026-10-01T00:00:00Z' );
		wp_update_post(
			[
				'ID'          => $b,
				'post_status' => 'draft',
			]
		);
		$this->assertNull( Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
		wp_publish_post( $b );
		$this->assertSame( '2026-10-01T00:00:00Z', Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
	}

	/**
	 * Resuming a test with a new challenger starts it again: readers saw the
	 * control alone while it had none, so the old start would count that gap.
	 */
	public function test_resuming_with_a_new_challenger_records_a_new_start() {
		$a     = $this->create_test_variant( 'rerun', 'a' );
		$old_b = $this->create_test_variant( 'rerun', 'b' );
		$this->set_recorded_start( 'rerun', '2026-10-01T00:00:00Z' );
		wp_trash_post( $old_b );

		$resumed = Newspack_Popups_AB_Tests::start_test( $a, Newspack_Popups_AB_Tests::get_test_name( 'rerun' ), 'donation', 50 );
		$this->assertIsArray( $resumed );
		$this->assertNull( $this->recorded_start( 'rerun' ) );
		wp_publish_post( $resumed['version_b']['id'] );
		$this->assertStartedNow( 'rerun' );
	}

	/**
	 * A resume that can't create the new challenger keeps the recorded start.
	 */
	public function test_a_failed_resume_keeps_the_start() {
		$a     = $this->create_test_variant( 'retry', 'a' );
		$old_b = $this->create_test_variant( 'retry', 'b' );
		$this->set_recorded_start( 'retry', '2026-10-01T00:00:00Z' );
		wp_trash_post( $old_b );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$resumed = Newspack_Popups_AB_Tests::start_test( $a, Newspack_Popups_AB_Tests::get_test_name( 'retry' ), 'donation', 50 );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->assertWPError( $resumed );
		$this->assertSame( 'newspack_popups_ab_version_b', $resumed->get_error_code() );
		$this->assertSame( '2026-10-01T00:00:00Z', $this->recorded_start( 'retry' ) );
	}

	/**
	 * Nothing is recorded until a control and a challenger are both live.
	 */
	public function test_no_start_is_recorded_for_a_control_alone() {
		$this->create_test_variant( 'alone', 'a' );
		$this->create_test_variant( 'alone', 'b', null, [ 'post_status' => 'draft' ] );
		$this->assertNull( $this->recorded_start( 'alone' ) );
	}

	/**
	 * With the flag off readers aren't split, so publishing records no start.
	 */
	public function test_no_start_is_recorded_with_the_flag_off() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->create_test_variant( 'off', 'a' );
		$this->create_test_variant( 'off', 'b' );
		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$this->assertNull( $this->recorded_start( 'off' ) );
	}

	/**
	 * Post fields for a prompt published on a fixed past day.
	 *
	 * @return array
	 */
	private function backdated() {
		return [
			'post_date_gmt' => '2026-09-01 00:00:00',
			'post_date'     => '2026-09-01 00:00:00',
		];
	}

	/**
	 * The start stored on a test's term, or null.
	 *
	 * @param string $test_id Test ID.
	 * @return string|null
	 */
	private function recorded_start( $test_id ) {
		$term = get_term_by( 'slug', $test_id, Newspack_Popups_AB_Tests::TAXONOMY );
		$meta = $term ? get_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_STARTED, true ) : '';
		return '' === $meta ? null : $meta;
	}

	/**
	 * Store a start on a test's term, as a test recorded on that day would have.
	 *
	 * @param string $test_id Test ID.
	 * @param string $started ISO 8601 UTC.
	 */
	private function set_recorded_start( $test_id, $started ) {
		$term = get_term_by( 'slug', $test_id, Newspack_Popups_AB_Tests::TAXONOMY );
		update_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_STARTED, $started );
	}

	/**
	 * Remove a test's recorded start, as for a test from before starts were stored.
	 *
	 * @param string $test_id Test ID.
	 */
	private function forget_start( $test_id ) {
		$term = get_term_by( 'slug', $test_id, Newspack_Popups_AB_Tests::TAXONOMY );
		if ( $term ) {
			delete_term_meta( $term->term_id, Newspack_Popups_AB_Tests::TERM_META_STARTED );
		}
	}

	/**
	 * Assert a test's start was recorded within the last minute, and is the one listed.
	 *
	 * @param string $test_id Test ID.
	 */
	private function assertStartedNow( $test_id ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches PHPUnit's assertion names.
		$recorded = $this->recorded_start( $test_id );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $recorded );
		$this->assertLessThan( 60, abs( time() - strtotime( $recorded ) ) );
		$rows = array_values( array_filter( Newspack_Popups_AB_Tests::get_tests(), fn( $test ) => $test_id === $test['test_id'] ) );
		$this->assertSame( $recorded, $rows[0]['started_at'] );
	}

	/**
	 * A published control with only a draft challenger has not started: readers
	 * still see the control alone.
	 */
	public function test_get_tests_started_at_is_null_until_a_challenger_is_published() {
		$this->create_test_variant( 'solo', 'a' );
		$this->create_test_variant( 'solo', 'b', null, [ 'post_status' => 'draft' ] );
		$this->assertNull( Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
	}

	/**
	 * A test with nothing published has not started.
	 */
	public function test_get_tests_started_at_is_null_without_a_published_prompt() {
		$this->create_test_variant( 'unpub', 'a', null, [ 'post_status' => 'draft' ] );
		$this->assertNull( Newspack_Popups_AB_Tests::get_tests()[0]['started_at'] );
	}

	/**
	 * Two prompts on one letter: the published one is the arm, whichever was
	 * saved first, and the other is reported so the page can point at it.
	 */
	public function test_get_tests_prefers_the_published_prompt_when_a_letter_is_duplicated() {
		$this->create_test_variant( 'dup', 'a' );
		$live  = $this->create_test_variant( 'dup', 'b' );
		$draft = $this->create_test_variant( 'dup', 'b', null, [ 'post_status' => 'draft' ] );
		$this->create_test_variant( 'dup2', 'a' );
		$draft2 = $this->create_test_variant( 'dup2', 'b', null, [ 'post_status' => 'draft' ] );
		$live2  = $this->create_test_variant( 'dup2', 'b' );
		$tests  = Newspack_Popups_AB_Tests::get_tests();
		$this->assertSame( $live, $tests[0]['variants']['b']['id'] );
		$this->assertSame( [ 'b' ], array_column( $tests[0]['duplicates'], 'letter' ) );
		$this->assertSame( [ $draft ], array_column( $tests[0]['duplicates'], 'id' ) );
		$this->assertArrayHasKey( 'edit_link', $tests[0]['duplicates'][0] );
		$this->assertSame( $live2, $tests[1]['variants']['b']['id'] );
		$this->assertSame( [ $draft2 ], array_column( $tests[1]['duplicates'], 'id' ) );
	}

	/**
	 * A duplicate never supplies the share or the goal.
	 */
	public function test_get_tests_ignores_a_duplicate_control_s_share_and_goal() {
		$live = $this->create_test_variant( 'dupa', 'a', null, [], 60 );
		update_post_meta( $live, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		$draft = $this->create_test_variant( 'dupa', 'a', null, [ 'post_status' => 'draft' ], 30 );
		update_post_meta( $draft, Newspack_Popups_AB_Tests::META_GOAL, 'link_click' );
		$test = Newspack_Popups_AB_Tests::get_tests()[0];
		$this->assertSame( 60, $test['control_share'] );
		$this->assertSame( 'donation', $test['goal'] );
		$this->assertSame( $live, $test['variants']['a']['id'] );
	}

	/**
	 * The control's goal is the test's goal even when it is empty, because the
	 * editor sets the goal on version A only; a challenger's stored goal is used
	 * only while the test has no control.
	 */
	public function test_get_tests_takes_the_goal_from_the_control_even_when_empty() {
		$challenger = $this->create_test_variant( 'goalless', 'b' );
		update_post_meta( $challenger, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		$this->assertSame( 'donation', Newspack_Popups_AB_Tests::get_tests()[0]['goal'], 'With no control, the challenger\'s goal stands in.' );

		$this->create_test_variant( 'goalless', 'a' );
		$test = Newspack_Popups_AB_Tests::get_tests()[0];
		$this->assertSame( '', $test['goal'] );
		$this->assertSame( '', $test['goal_label'] );
	}

	/**
	 * A Test ID made of digits stays the string it was saved as, so the route
	 * that looks a test up by its sanitized ID still finds it.
	 */
	public function test_get_tests_keeps_a_numeric_test_id_a_string() {
		$this->create_test_variant( '123', 'a' );
		$this->create_test_variant( '123', 'b' );
		$this->create_test_variant( 'alpha', 'a' );
		$ids = array_column( Newspack_Popups_AB_Tests::get_tests(), 'test_id' );
		$this->assertSame( [ '123', 'alpha' ], $ids );
	}

	/**
	 * The goal and its label come along; variants carry what the page shows.
	 */
	public function test_get_tests_goal_label_and_variants() {
		$id = $this->create_test_variant( 'goal', 'a' );
		update_post_meta( $id, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		$test = Newspack_Popups_AB_Tests::get_tests()[0];
		$this->assertSame( 'donation', $test['goal'] );
		$this->assertSame( 'Donation', $test['goal_label'] );
		$this->assertSame( $id, $test['variants']['a']['id'] );
		$this->assertSame( 'publish', $test['variants']['a']['status'] );
		$this->assertSame( 'Published', $test['variants']['a']['status_label'] );
		$this->assertArrayHasKey( 'edit_link', $test['variants']['a'] );
		$this->assertArrayHasKey( 'title', $test['variants']['a'] );
	}

	/**
	 * A prompt whose variant key is not a letter the schema knows is left out.
	 */
	public function test_get_tests_skips_prompts_with_an_unknown_variant() {
		$this->create_test_variant( 'odd', 'a' );
		$this->create_test_variant( 'odd', 'z' );
		$this->assertSame( [ 'a' ], array_keys( Newspack_Popups_AB_Tests::get_tests()[0]['variants'] ) );
	}

	/**
	 * A site with no tests records the flag, so later requests skip the query.
	 */
	public function test_zero_test_site_records_the_has_tests_flag() {
		self::assertSame( [], Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( '0', get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );
	}

	/**
	 * A recorded "no tests" must not survive a test being created.
	 *
	 * This is the failure the flag can cause: a stale '0' short-circuits the config
	 * builder, and every live test silently stops running with nothing in the logs.
	 * Creating a variant has to invalidate it through the meta hook.
	 */
	public function test_creating_a_test_invalidates_a_stale_flag() {
		update_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS, '0' );

		$this->create_test_variant( 'flag-test', 'a' );
		$this->create_test_variant( 'flag-test', 'b' );

		$config = Newspack_Popups_AB_Tests::get_tests_config();
		self::assertArrayHasKey( 'flag-test', $config, 'A stale flag must not hide a newly created test.' );
	}

	/**
	 * Adding test meta to an existing prompt invalidates the flag.
	 *
	 * Distinct from the case above: creating a prompt fires save_post, which
	 * invalidates on its own, so that path exercises the post hook and never the
	 * meta hook. update_post_meta() on an already-saved prompt fires neither
	 * save_post nor any post-lifecycle hook, so only the meta hook can catch it --
	 * and a test id set this way is how a test can appear with no post save at all.
	 */
	public function test_setting_test_meta_on_an_existing_prompt_invalidates_the_flag() {
		$control    = $this->createPopup();
		$challenger = $this->createPopup();

		// Record "no tests" the way a real request would.
		self::assertSame( [], Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( '0', get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );

		update_post_meta( $control, Newspack_Popups_AB_Tests::META_TEST_ID, 'meta-only-test' );
		update_post_meta( $control, Newspack_Popups_AB_Tests::META_VARIANT, 'a' );
		update_post_meta( $challenger, Newspack_Popups_AB_Tests::META_TEST_ID, 'meta-only-test' );
		update_post_meta( $challenger, Newspack_Popups_AB_Tests::META_VARIANT, 'b' );

		$config = Newspack_Popups_AB_Tests::get_tests_config();
		self::assertArrayHasKey( 'meta-only-test', $config, 'A test id set via meta must invalidate the flag.' );
	}

	/**
	 * Deleting the last prompt of a test invalidates the flag.
	 */
	public function test_deleting_a_prompt_invalidates_the_flag() {
		$control    = $this->create_test_variant( 'gone-test', 'a' );
		$challenger = $this->create_test_variant( 'gone-test', 'b' );
		self::assertArrayHasKey( 'gone-test', Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( '1', get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );

		wp_delete_post( $control, true );
		wp_delete_post( $challenger, true );

		self::assertFalse( get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ), 'Deleting a prompt must drop the flag.' );
		self::assertSame( [], Newspack_Popups_AB_Tests::get_tests_config() );
	}

	/**
	 * A/B meta keys are registered on the prompts CPT with REST support.
	 */
	public function test_ab_meta_registered() {
		do_action( 'init' );
		$registered = get_registered_meta_keys( 'post', Newspack_Popups::NEWSPACK_POPUPS_CPT );
		foreach ( [
			Newspack_Popups_AB_Tests::META_TEST_ID,
			Newspack_Popups_AB_Tests::META_VARIANT,
			Newspack_Popups_AB_Tests::META_GOAL,
			Newspack_Popups_AB_Tests::META_CONTROL_SHARE,
		] as $key ) {
			self::assertArrayHasKey( $key, $registered, "Meta key $key should be registered." );
			self::assertNotEmpty( $registered[ $key ]['show_in_rest'], "Meta key $key should be exposed in REST." );
		}

		// The variant REST schema rejects invalid values with a 400 instead of a
		// silent coercion; the empty string (no test) stays writable.
		$variant_enum = $registered[ Newspack_Popups_AB_Tests::META_VARIANT ]['show_in_rest']['schema']['enum'];
		self::assertContains( '', $variant_enum );
		self::assertContains( 'a', $variant_enum );

		// An unset control share must read back as the same 50 the display path
		// assumes — never the integer schema default 0 (which would clamp to 20
		// on a round-trip and silently re-bucket anonymous readers mid-test).
		self::assertSame( 50, $registered[ Newspack_Popups_AB_Tests::META_CONTROL_SHARE ]['default'] );
		self::assertSame( 50, get_post_meta( self::$popup_id, Newspack_Popups_AB_Tests::META_CONTROL_SHARE, true ) );
	}

	/**
	 * The popup object carries A/B fields when the prompt is part of a valid test.
	 */
	public function test_popup_object_has_ab_fields() {
		$control_id    = $this->create_test_variant( 'donate-q3', 'a' );
		$challenger_id = $this->create_test_variant( 'donate-q3', 'b' );
		$popup         = Newspack_Popups_Model::create_popup_object( get_post( $challenger_id ) );
		self::assertSame( 'donate-q3', $popup['ab_test_id'] );
		self::assertSame( 'b', $popup['ab_variant'] );

		$plain_popup = Newspack_Popups_Model::create_popup_object( get_post( self::$popup_id ) );
		self::assertArrayNotHasKey( 'ab_test_id', $plain_popup );
	}

	/**
	 * An invalid test (no published challenger) must not present itself as a live
	 * experiment: no popup-object fields, no markup attributes, no GA params.
	 */
	public function test_invalid_test_does_not_emit_ab_fields() {
		$control_only = $this->create_test_variant( 'control-only-test', 'a' );
		$popup        = Newspack_Popups_Model::create_popup_object( get_post( $control_only ) );
		self::assertArrayNotHasKey( 'ab_test_id', $popup, 'A control-only test must not emit A/B fields.' );

		// The unvalidated accessor still returns the raw fields (editor use).
		$raw = Newspack_Popups_AB_Tests::get_popup_ab_fields( $control_only );
		self::assertSame( 'control-only-test', $raw['test_id'] );
	}

	/**
	 * Rendered containers expose data-ab-test-id and data-ab-variant attributes.
	 */
	public function test_container_markup_has_ab_attributes() {
		$overlay_options = [
			'frequency' => 'always',
			'placement' => 'center',
		];
		$this->create_test_variant( 'overlay-test', 'a', $overlay_options, [], 60 );
		$this->create_test_variant( 'overlay-test', 'b', $overlay_options );
		$this->renderPost();

		$nodes = self::$dom_xpath->query( '//*[@data-ab-test-id="overlay-test"]' );
		self::assertSame( 2, $nodes->length, 'Both test variants should carry the test ID attribute.' );

		$variants = [];
		foreach ( $nodes as $node ) {
			$variants[] = $node->getAttribute( 'data-ab-variant' );
		}
		sort( $variants );
		self::assertSame( [ 'a', 'b' ], $variants );

		// The non-test popup created in set_up must not carry A/B attributes.
		$plain = self::$dom_xpath->query( '//*[contains(@class,"newspack-popup-container") and not(@data-ab-test-id)]' );
		self::assertGreaterThanOrEqual( 1, $plain->length );
	}

	/**
	 * The tests config includes only valid tests (control + challenger, published).
	 */
	public function test_tests_config_builder() {
		$this->create_test_variant( 'valid-test', 'a', null, [], 70 );
		$this->create_test_variant( 'valid-test', 'b' );
		// Control-only test: invalid.
		$this->create_test_variant( 'control-only', 'a' );
		// Draft challenger: its test is control-only among published prompts.
		$this->create_test_variant( 'draft-challenger', 'a' );
		$this->create_test_variant( 'draft-challenger', 'b', null, [ 'post_status' => 'draft' ] );

		$config = Newspack_Popups_AB_Tests::get_tests_config();

		self::assertArrayHasKey( 'valid-test', $config );
		self::assertSame( [ 'a', 'b' ], $config['valid-test']['variants'] );
		self::assertSame( 70, $config['valid-test']['control_share'] );
		self::assertArrayNotHasKey( 'control-only', $config );
		self::assertArrayNotHasKey( 'draft-challenger', $config );
	}

	/**
	 * Control share defaults to 50 when the control prompt has no explicit share.
	 */
	public function test_tests_config_default_control_share() {
		$this->create_test_variant( 'default-share', 'a' );
		$this->create_test_variant( 'default-share', 'b' );
		$config = Newspack_Popups_AB_Tests::get_tests_config();
		self::assertSame( 50, $config['default-share']['control_share'] );
	}

	/**
	 * Store a raw control share the way an importer or a direct query would,
	 * skipping the meta sanitize callback that update_post_meta() runs.
	 *
	 * @param int    $popup_id Prompt ID.
	 * @param string $value    Raw stored value.
	 */
	private function store_raw_control_share( $popup_id, $value ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->postmeta,
			[
				'post_id'    => $popup_id,
				'meta_key'   => Newspack_Popups_AB_Tests::META_CONTROL_SHARE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $popup_id, 'post_meta' );
	}

	/**
	 * An out-of-range share that bypassed the sanitize callback is clamped when the
	 * config is built, so the client never receives a range it would skew on.
	 */
	public function test_tests_config_clamps_unsanitized_control_share() {
		$high = $this->create_test_variant( 'too-high', 'a' );
		$this->create_test_variant( 'too-high', 'b' );
		$this->store_raw_control_share( $high, '95' );

		$low = $this->create_test_variant( 'too-low', 'a' );
		$this->create_test_variant( 'too-low', 'b' );
		$this->store_raw_control_share( $low, '5' );

		$config = Newspack_Popups_AB_Tests::get_tests_config();
		self::assertSame( 80, $config['too-high']['control_share'] );
		self::assertSame( 20, $config['too-low']['control_share'] );
	}

	/**
	 * A stored share of 0 falls back to the default when the config is built. The
	 * config is the only thing the client receives, so it never sees a 0; the
	 * shared parity vectors cover what both bucketing functions do if it ever did.
	 */
	public function test_tests_config_reads_stored_zero_share_as_default() {
		$control = $this->create_test_variant( 'zero-share', 'a' );
		$this->create_test_variant( 'zero-share', 'b' );
		$this->store_raw_control_share( $control, '0' );

		$config = Newspack_Popups_AB_Tests::get_tests_config();
		self::assertSame( Newspack_Popups_AB_Tests::DEFAULT_CONTROL_SHARE, $config['zero-share']['control_share'] );
	}

	/**
	 * Vectors shared with src/view/utils/ab.test.js, so both implementations are
	 * checked against one expectation rather than two copies kept in step by hand.
	 *
	 * @return array[] Each case: reader, test ID, config, expected hash, expected bucket.
	 */
	public function parity_vectors() {
		$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/ab-bucket-parity.json' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$cases   = [];
		foreach ( $fixture['vectors'] as $vector ) {
			$cases[ $vector['note'] ] = [ $vector['reader'], $vector['test_id'], $vector['config'], $vector['hash'], $vector['bucket'] ];
		}
		return $cases;
	}

	/**
	 * The server-side hash and bucket agree with the client's on every shared vector.
	 *
	 * @dataProvider parity_vectors
	 *
	 * @param string $reader  Reader key.
	 * @param string $test_id Test ID.
	 * @param array  $config  Test config.
	 * @param int    $hash    Expected hash of "reader|test_id".
	 * @param string $bucket  Expected variant.
	 */
	public function test_bucketing_matches_client_vectors( $reader, $test_id, $config, $hash, $bucket ) {
		self::assertSame( $hash, Newspack_Popups_AB_Tests::hash_djb2( $reader . '|' . $test_id ) );
		self::assertSame( $bucket, Newspack_Popups_AB_Tests::compute_bucket( $reader, $test_id, $config ) );
	}

	/**
	 * Bucket computation is deterministic and respects weighted ranges.
	 */
	public function test_compute_bucket() {
		$config = [
			'variants'      => [ 'a', 'b' ],
			'control_share' => 80,
		];

		// Deterministic: same input, same output.
		$first = Newspack_Popups_AB_Tests::compute_bucket( 'reader-123', 'test-x', $config );
		self::assertSame( $first, Newspack_Popups_AB_Tests::compute_bucket( 'reader-123', 'test-x', $config ) );
		self::assertContains( $first, [ 'a', 'b' ] );

		// Weighted distribution: with an 80% control share, most readers land on A.
		$a_count = 0;
		for ( $i = 0; $i < 500; $i++ ) {
			if ( 'a' === Newspack_Popups_AB_Tests::compute_bucket( "reader-$i", 'test-x', $config ) ) {
				$a_count++;
			}
		}
		self::assertGreaterThan( 350, $a_count, 'Roughly 80% of readers should be bucketed to control.' );
		self::assertLessThan( 470, $a_count, 'Some readers should be bucketed to the challenger.' );

		// Degenerate config falls back to control.
		$degenerate_config = [
			'variants'      => [ 'a' ],
			'control_share' => 50,
		];
		self::assertSame( 'a', Newspack_Popups_AB_Tests::compute_bucket( 'reader-123', 'test-x', $degenerate_config ) );
	}

	/**
	 * Logged-in buckets are persisted in user meta and stable across config changes.
	 */
	public function test_logged_in_bucket_persisted() {
		$this->create_test_variant( 'sticky-test', 'a', null, [], 50 );
		$this->create_test_variant( 'sticky-test', 'b' );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertArrayHasKey( 'sticky-test', $buckets );
		$assigned = $buckets['sticky-test'];
		self::assertContains( $assigned, [ 'a', 'b' ] );
		self::assertSame( $assigned, get_user_meta( $user_id, Newspack_Popups_AB_Tests::bucket_meta_key( 'sticky-test' ), true ) );

		// A changed control share must not re-bucket an already-assigned reader.
		update_user_meta( $user_id, Newspack_Popups_AB_Tests::bucket_meta_key( 'sticky-test' ), 'b' );
		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( 'b', $buckets['sticky-test'] );

		wp_set_current_user( 0 );
	}

	/**
	 * First logged-in assignment prefers the client ID cookie, so a reader who
	 * registers mid-test stays in the arm they were seeing anonymously.
	 */
	public function test_logged_in_bucket_prefers_client_id() {
		$this->create_test_variant( 'continuity-test', 'a', null, [], 50 );
		$this->create_test_variant( 'continuity-test', 'b' );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$_COOKIE['newspack-cid'] = 'reader-cid-123'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$config   = Newspack_Popups_AB_Tests::get_tests_config();
		$buckets  = Newspack_Popups_AB_Tests::get_logged_in_buckets( $config );
		$expected = Newspack_Popups_AB_Tests::compute_bucket( 'reader-cid-123', 'continuity-test', $config['continuity-test'] );
		self::assertSame( $expected, $buckets['continuity-test'], 'The persisted bucket should derive from the client ID, matching the anonymous assignment.' );

		unset( $_COOKIE['newspack-cid'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		wp_set_current_user( 0 );
	}

	/**
	 * Drafting one arm of a three-arm test must not lose the assignment of the
	 * readers holding it: they see a published arm meanwhile, and return to theirs
	 * when it is republished.
	 */
	public function test_logged_in_bucket_survives_its_variant_being_unpublished() {
		$this->create_test_variant( 'three-arm-test', 'a', null, [], 50 );
		$this->create_test_variant( 'three-arm-test', 'b' );
		$challenger_id = $this->create_test_variant( 'three-arm-test', 'c' );

		$user_id  = self::factory()->user->create();
		$meta_key = Newspack_Popups_AB_Tests::bucket_meta_key( 'three-arm-test' );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, $meta_key, 'c' );

		wp_update_post(
			[
				'ID'          => $challenger_id,
				'post_status' => 'draft',
			]
		);
		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertContains( $buckets['three-arm-test'], [ 'a', 'b' ], 'An unpublished arm must not be shown.' );
		self::assertSame( 'c', get_user_meta( $user_id, $meta_key, true ), 'The stored assignment must outlive the unpublish.' );

		wp_update_post(
			[
				'ID'          => $challenger_id,
				'post_status' => 'publish',
			]
		);
		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( 'c', $buckets['three-arm-test'], 'Republishing must return the reader to their arm.' );

		wp_set_current_user( 0 );
	}

	/**
	 * A stored value that was never a variant has no assignment worth keeping, so
	 * it is replaced with a real one.
	 */
	public function test_logged_in_bucket_replaces_an_invalid_stored_value() {
		$this->create_test_variant( 'invalid-bucket-test', 'a', null, [], 50 );
		$this->create_test_variant( 'invalid-bucket-test', 'b' );

		$user_id  = self::factory()->user->create();
		$meta_key = Newspack_Popups_AB_Tests::bucket_meta_key( 'invalid-bucket-test' );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, $meta_key, 'not-a-variant' );

		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertContains( $buckets['invalid-bucket-test'], [ 'a', 'b' ] );
		self::assertSame( $buckets['invalid-bucket-test'], get_user_meta( $user_id, $meta_key, true ) );

		wp_set_current_user( 0 );
	}

	/**
	 * A reader's bucket belongs to this site: one stored by another site in the
	 * network, or under the unprefixed key, is not read or overwritten.
	 */
	public function test_logged_in_bucket_is_scoped_to_the_site() {
		global $wpdb;
		$this->create_test_variant( 'shared-id-test', 'a', null, [], 50 );
		$this->create_test_variant( 'shared-id-test', 'b' );

		$user_id    = self::factory()->user->create();
		$other_site = $wpdb->base_prefix . '99_' . Newspack_Popups_AB_Tests::USER_META_BUCKET_PREFIX . 'shared-id-test';
		$unprefixed = Newspack_Popups_AB_Tests::USER_META_BUCKET_PREFIX . 'shared-id-test';
		wp_set_current_user( $user_id );
		$config   = Newspack_Popups_AB_Tests::get_tests_config();
		$computed = Newspack_Popups_AB_Tests::compute_bucket( (string) $user_id, 'shared-id-test', $config['shared-id-test'] );
		$other    = 'a' === $computed ? 'b' : 'a';
		update_user_meta( $user_id, $other_site, $other );
		update_user_meta( $user_id, $unprefixed, $other );

		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( $config );
		self::assertSame( $computed, $buckets['shared-id-test'], 'Another site\'s bucket must not be read.' );
		self::assertSame( $wpdb->get_blog_prefix() . $unprefixed, Newspack_Popups_AB_Tests::bucket_meta_key( 'shared-id-test' ) );
		self::assertSame( $computed, get_user_meta( $user_id, Newspack_Popups_AB_Tests::bucket_meta_key( 'shared-id-test' ), true ) );
		self::assertSame( $other, get_user_meta( $user_id, $other_site, true ) );
		self::assertSame( $other, get_user_meta( $user_id, $unprefixed, true ) );

		wp_set_current_user( 0 );
	}

	/**
	 * Without a client ID cookie, the logged-in bucket hashes the user ID.
	 */
	public function test_logged_in_bucket_user_id_fallback() {
		$this->create_test_variant( 'fallback-test', 'a', null, [], 50 );
		$this->create_test_variant( 'fallback-test', 'b' );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		unset( $_COOKIE['newspack-cid'] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$config   = Newspack_Popups_AB_Tests::get_tests_config();
		$buckets  = Newspack_Popups_AB_Tests::get_logged_in_buckets( $config );
		$expected = Newspack_Popups_AB_Tests::compute_bucket( (string) $user_id, 'fallback-test', $config['fallback-test'] );
		self::assertSame( $expected, $buckets['fallback-test'], 'With no client ID cookie, the bucket should derive from the user ID.' );

		wp_set_current_user( 0 );
	}

	/**
	 * A site with no live test sends the view script none of the A/B keys.
	 */
	public function test_view_script_ab_data_is_empty_without_a_live_test() {
		self::assertSame( [], Newspack_Popups_Inserter::get_view_script_ab_data() );

		// A test with no published challenger is not live either.
		$this->create_test_variant( 'control-only', 'a' );
		Newspack_Popups_AB_Tests::reset_config_cache();
		self::assertSame( [], Newspack_Popups_Inserter::get_view_script_ab_data() );
	}

	/**
	 * Pins the server's half of the PHP-to-JS contract for a live test: the front
	 * end reads these keys from newspack_popups_view, and renaming `ab_tests` or
	 * `variants` here alone would stop every test splitting traffic. The JS tests
	 * build that object by hand, so they would not notice.
	 */
	public function test_view_script_ab_data_for_a_live_test() {
		$this->create_test_variant( 'live-test', 'a', null, [], 60 );
		$this->create_test_variant( 'live-test', 'b' );

		$data = Newspack_Popups_Inserter::get_view_script_ab_data();
		self::assertSame(
			[
				'live-test' => [
					'variants'      => [ 'a', 'b' ],
					'control_share' => 60,
				],
			],
			$data['ab_tests']
		);
		self::assertSame( 'newspack-cid', $data['cid_cookie'] );
		self::assertArrayNotHasKey( 'ab_buckets', $data, 'An anonymous reader is bucketed client-side only.' );
		self::assertArrayNotHasKey( 'ab_view_as', $data, 'No preview was requested.' );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$data = Newspack_Popups_Inserter::get_view_script_ab_data();
		self::assertArrayHasKey( 'live-test', $data['ab_buckets'], 'A logged-in reader gets the server-persisted bucket.' );
		wp_set_current_user( 0 );
	}

	/**
	 * A variant preview reaches the view script only for an admin, and only for a
	 * letter a test can have.
	 */
	public function test_view_script_ab_data_echoes_admin_variant_preview() {
		$this->create_test_variant( 'preview-test', 'a' );
		$this->create_test_variant( 'preview-test', 'b' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_GET['view_as'] = 'ab_variant:b';
		self::assertSame( 'b', Newspack_Popups_Inserter::get_view_script_ab_data()['ab_view_as'] );

		$_GET['view_as'] = 'ab_variant:z';
		self::assertArrayNotHasKey( 'ab_view_as', Newspack_Popups_Inserter::get_view_script_ab_data() );

		$_GET['view_as'] = 'ab_variant:b';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertArrayNotHasKey( 'ab_view_as', Newspack_Popups_Inserter::get_view_script_ab_data() );

		unset( $_GET['view_as'] );
		wp_set_current_user( 0 );
	}

	/**
	 * The view_as spec (which feeds ab_view_as into script data) is admin-gated:
	 * a non-privileged request must not be able to force a variant preview.
	 */
	public function test_view_as_parsing_is_admin_gated() {
		$_GET['view_as'] = 'ab_variant:b';

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );
		self::assertEmpty( Newspack_Popups_View_As::parse_view_as(), 'A non-privileged user must not resolve a view_as spec.' );

		wp_set_current_user( 0 );
		self::assertEmpty( Newspack_Popups_View_As::parse_view_as(), 'An anonymous request must not resolve a view_as spec.' );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
		$parsed = Newspack_Popups_View_As::parse_view_as();
		self::assertSame( 'b', $parsed['ab_variant'], 'An admin request should resolve the previewed variant.' );

		unset( $_GET['view_as'] );
		wp_set_current_user( 0 );
	}

	/**
	 * GA event metadata includes A/B params for valid test prompts only.
	 */
	public function test_ga_metadata_includes_ab_params() {
		$this->create_test_variant( 'ga-test', 'a' );
		$popup_id = $this->create_test_variant( 'ga-test', 'b' );
		$metadata = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		self::assertSame( 'ga-test', $metadata['ab_test_id'] );
		self::assertSame( 'b', $metadata['ab_variant'] );

		$plain_metadata = Newspack_Popups_Data_Api::get_popup_metadata( self::$popup_id );
		self::assertArrayNotHasKey( 'ab_test_id', $plain_metadata );
	}

	/**
	 * With the flag off, a stored test has no effect: no config reaches the view
	 * script, so there is no variant selection and no reader bucket assignment.
	 * It must not record "no tests" either: a cached '0' would outlive the flag
	 * and keep every test from running once the flag is turned back on.
	 */
	public function test_flag_off_ignores_stored_tests_without_recording_none() {
		$this->create_test_variant( 'flag-toggle-test', 'a' );
		$this->create_test_variant( 'flag-toggle-test', 'b' );

		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		self::assertSame( [], Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertFalse( get_option( Newspack_Popups_AB_Tests::OPTION_HAS_TESTS ) );

		remove_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		self::assertArrayHasKey( 'flag-toggle-test', Newspack_Popups_AB_Tests::get_tests_config() );
	}

	/**
	 * With the flag off, test prompts behave like plain prompts: no A/B fields on
	 * the popup object, no markup attributes, and no A/B params on GA events.
	 */
	public function test_flag_off_prompts_carry_no_ab_fields() {
		$overlay_options = [
			'frequency' => 'always',
			'placement' => 'center',
		];
		$this->create_test_variant( 'flag-off-fields', 'a', $overlay_options );
		$challenger_id = $this->create_test_variant( 'flag-off-fields', 'b', $overlay_options );
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );

		self::assertNull( Newspack_Popups_AB_Tests::get_popup_ab_fields( $challenger_id ) );
		self::assertArrayNotHasKey( 'ab_test_id', Newspack_Popups_Model::create_popup_object( get_post( $challenger_id ) ) );
		self::assertArrayNotHasKey( 'ab_test_id', Newspack_Popups_Data_Api::get_popup_metadata( $challenger_id ) );

		$this->renderPost();
		self::assertSame( 3, $this->getRenderedPopupsAmount(), 'Both test prompts and the plain one should still render.' );
		self::assertSame( 0, self::$dom_xpath->query( '//*[@data-ab-test-id]' )->length );
	}

	/**
	 * Save a prompt through REST the way the block editor does: the stored meta
	 * object sent back whole, with the changes merged in.
	 *
	 * @param int    $prompt_id Prompt post ID.
	 * @param string $role      Role of the user saving.
	 * @param array  $changes   Post fields to change; a 'meta' entry merges into the stored meta.
	 * @return WP_REST_Response
	 */
	private function save_prompt_as( $prompt_id, $role, $changes ) {
		$this->start_rest_as( $role );

		$route   = '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $prompt_id;
		$stored  = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data();
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( array_merge( $changes, [ 'meta' => array_merge( $stored['meta'], $changes['meta'] ?? [] ) ] ) );
		return rest_do_request( $request );
	}

	/**
	 * Create a prompt through REST the way a REST client does, sending every
	 * registered A/B key at its default unless the test overrides it. The block
	 * editor never takes this path: it saves an auto-draft by ID.
	 *
	 * @param string $role Role of the user creating it.
	 * @param array  $meta A/B values to send in place of the defaults.
	 * @return WP_REST_Response
	 */
	private function create_prompt_as( $role, $meta ) {
		$this->start_rest_as( $role );
		$registered = get_registered_meta_keys( 'post', Newspack_Popups::NEWSPACK_POPUPS_CPT );
		$defaults   = [];
		foreach ( Newspack_Popups_AB_Tests::META_KEYS as $meta_key ) {
			$defaults[ $meta_key ] = $registered[ $meta_key ]['default'] ?? '';
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT );
		$request->set_body_params(
			[
				'title' => 'Created by a restricted editor',
				'meta'  => array_merge( $defaults, $meta ),
			]
		);
		return rest_do_request( $request );
	}

	/**
	 * Reset the REST server and act as a new user with the given role.
	 *
	 * @param string $role Role of the user.
	 */
	private function start_rest_as( $role ) {
		// Meta registered at boot does not survive into later tests in this suite,
		// and REST silently ignores an unregistered key.
		Newspack_Popups::register_meta();
		Newspack_Popups_AB_Tests::register_meta();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * Count prompts in any status.
	 *
	 * @return int
	 */
	private function count_prompts() {
		$query = new WP_Query(
			[
				'post_type'      => Newspack_Popups::NEWSPACK_POPUPS_CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			]
		);
		return $query->found_posts;
	}

	/**
	 * Assert the refusal came from the guard. Core's own meta refusals are also
	 * 403s with the same error code, so only the message tells them apart.
	 *
	 * @param WP_REST_Response $response Response to a refused save.
	 */
	private function assert_refused_by_guard( $response ) {
		self::assertStringStartsWith( "You can't change this prompt's test settings.", $response->get_data()['message'] );
	}

	/**
	 * Narrow test management to administrators, as a site or a later capability
	 * model might, so the guard's refusal path has someone to refuse.
	 */
	private function restrict_tests_to_admins() {
		add_filter(
			'newspack_popups_ab_can_manage_tests',
			function ( $allowed, $user_id ) {
				return user_can( $user_id, 'manage_options' );
			},
			10,
			2
		);
	}

	/**
	 * By default, anyone who can edit a prompt can change its test, and anyone
	 * who can't edit it can't.
	 */
	public function test_prompt_editors_manage_tests_by_default() {
		$prompt_id = $this->createPopup();

		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => 'donation' ] ] );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'donation', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		self::assertFalse( Newspack_Popups_AB_Tests::can_manage_tests( $subscriber_id, $prompt_id ) );
		self::assertFalse( Newspack_Popups_AB_Tests::can_manage_tests( $subscriber_id, 0 ) );

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		self::assertTrue( Newspack_Popups_AB_Tests::can_manage_tests( $editor_id, 0 ), 'Anyone who can create prompts can set up a test on a new one.' );

		// A Contributor can edit prompts in general, but not someone else's published one.
		$contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		self::assertTrue( Newspack_Popups_AB_Tests::can_manage_tests( $contributor_id, 0 ) );
		self::assertFalse( Newspack_Popups_AB_Tests::can_manage_tests( $contributor_id, $prompt_id ) );
	}

	/**
	 * A user who may edit a prompt but not its test can still save its other
	 * settings: A/B values sent back unchanged never need test permission.
	 */
	public function test_restricted_editor_can_save_a_prompt_without_changing_its_test() {
		$this->restrict_tests_to_admins();
		$plain_prompt  = $this->createPopup();
		$tested_prompt = $this->create_test_variant( 'editor-save-test', 'a', null, [], 60 );

		foreach ( [ $plain_prompt, $tested_prompt ] as $prompt_id ) {
			$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ 'trigger_delay' => 7 ] ] );
			self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			self::assertSame( '7', get_post_meta( $prompt_id, 'trigger_delay', true ) );
		}
		self::assertSame( 'editor-save-test', get_post_meta( $tested_prompt, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '60', get_post_meta( $tested_prompt, Newspack_Popups_AB_Tests::META_CONTROL_SHARE, true ) );
	}

	/**
	 * A restricted user's request that changes a test is refused before anything
	 * saves, so the editor never reports a failure over a half-applied change.
	 */
	public function test_restricted_editor_cannot_change_a_test_and_nothing_saves() {
		$this->restrict_tests_to_admins();
		$prompt_id     = $this->createPopup();
		$title_before  = get_the_title( $prompt_id );
		$delay_before  = get_post_meta( $prompt_id, 'trigger_delay', true );

		$response = $this->save_prompt_as(
			$prompt_id,
			'editor',
			[
				'title' => 'Edited alongside a test change',
				'meta'  => [
					Newspack_Popups_AB_Tests::META_GOAL => 'donation',
					'trigger_delay'                     => 7,
				],
			]
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
		self::assertSame( $delay_before, get_post_meta( $prompt_id, 'trigger_delay', true ) );
		self::assertSame( $title_before, get_the_title( $prompt_id ) );
	}

	/**
	 * A non-scalar A/B value from a restricted user is refused without being cast
	 * to a string, which would raise an "Array to string conversion" warning. The
	 * 403 alone doesn't pin this: a cast array never matches a stored value either.
	 */
	public function test_restricted_editor_non_scalar_ab_value_is_refused_without_a_warning() {
		$this->restrict_tests_to_admins();
		$prompt_id = $this->createPopup();

		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => [ 'x' ] ] ] );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
	}

	/**
	 * On the autosave route the guard drops a restricted user's A/B keys instead of
	 * refusing: core's autosave controller ignores a refusal and would record an
	 * empty autosave in place of the editor's changes. Called directly, because
	 * the controller defines DOING_AUTOSAVE for the rest of the test run.
	 */
	public function test_guard_drops_ab_meta_on_autosaves_instead_of_refusing() {
		$this->restrict_tests_to_admins();
		$prompt_id = $this->createPopup();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $prompt_id . '/autosaves' );
		$request->set_body_params(
			[
				'meta' => [
					Newspack_Popups_AB_Tests::META_TEST_ID => 'editor-made-test',
					'trigger_delay'                        => 7,
				],
			]
		);
		$prepared_post = (object) [ 'ID' => $prompt_id ];

		self::assertSame( $prepared_post, Newspack_Popups_AB_Tests::guard_rest_ab_meta( $prepared_post, $request ) );
		self::assertSame( [ 'trigger_delay' => 7 ], $request->get_param( 'meta' ) );

		// The placement rule is skipped on autosaves too: a draft mid-edit is not
		// a save, and refusing it would make the editor flash an error while typing.
		$tested_id = $this->create_test_variant( 'autosave-placement', 'a', [ 'placement' => 'inline' ] );
		$request   = new WP_REST_Request( 'POST', '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $tested_id . '/autosaves' );
		$request->set_body_params( [ 'meta' => [ 'placement' => 'manual' ] ] );
		$prepared_post = (object) [ 'ID' => $tested_id ];
		self::assertSame( $prepared_post, Newspack_Popups_AB_Tests::guard_rest_ab_meta( $prepared_post, $request ) );
	}

	/**
	 * A REST create asks the guard with post ID 0, before the prompt exists. A
	 * restricted user sending the registered defaults is creating a prompt, not a
	 * test, so the create goes through. The control share's default is 50, not an
	 * empty string, which is what this pins.
	 */
	public function test_restricted_editor_can_create_a_prompt_with_ab_defaults() {
		$this->restrict_tests_to_admins();
		$before = $this->count_prompts();

		$response = $this->create_prompt_as( 'editor', [] );

		self::assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( $before + 1, $this->count_prompts() );
	}

	/**
	 * A restricted user's create that sets test settings is refused before the
	 * prompt is created. Without the guard, core would insert the post and only
	 * then refuse the meta, leaving a prompt behind with the request reported as failed.
	 */
	public function test_restricted_editor_cannot_create_a_prompt_with_test_settings() {
		$this->restrict_tests_to_admins();
		$before = $this->count_prompts();

		$response = $this->create_prompt_as( 'editor', [ Newspack_Popups_AB_Tests::META_GOAL => 'donation' ] );

		self::assertSame( 403, $response->get_status() );
		$this->assert_refused_by_guard( $response );
		self::assertSame( $before, $this->count_prompts() );
	}

	/**
	 * Only starting, resuming and ending a test set a prompt's Test ID and
	 * version, so a create can't put a new prompt into a test, whoever sends it.
	 */
	public function test_a_create_cannot_put_a_prompt_into_a_test() {
		$response = $this->create_prompt_as(
			'administrator',
			[
				Newspack_Popups_AB_Tests::META_TEST_ID => 'created-in-a-test',
				Newspack_Popups_AB_Tests::META_VARIANT => 'b',
			]
		);

		self::assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$prompt_id = $response->get_data()['id'];
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
	}

	/**
	 * An editor tab opened before Start A/B Test still holds version A's old,
	 * empty Test ID and version, and sends them back with its next save. The save
	 * goes through, and A stays in its test.
	 */
	public function test_a_stale_editor_save_keeps_a_prompt_in_its_test() {
		$prompt_id = $this->createPopup( null, [ 'placement' => 'inline' ] );
		$route     = '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $prompt_id;
		$this->start_rest_as( 'administrator' );
		$stale_meta = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()['meta'];
		self::assertSame( '', $stale_meta[ Newspack_Popups_AB_Tests::META_TEST_ID ] );

		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, 'stale-tab-test' );
		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, 'a' );

		$stale_meta['frequency'] = 'once';
		$request                 = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( [ 'meta' => $stale_meta ] );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'once', get_post_meta( $prompt_id, 'frequency', true ) );
		self::assertSame( 'stale-tab-test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( 'a', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
		// The response carries the stored values, so the editor shows the test again.
		self::assertSame( 'stale-tab-test', $response->get_data()['meta'][ Newspack_Popups_AB_Tests::META_TEST_ID ] );
	}

	/**
	 * A null asks core to delete the key, which leaves an unset control share at
	 * its default of 50. Nothing changes, so a restricted user's save goes through.
	 */
	public function test_restricted_editor_null_control_share_on_an_unset_share_saves() {
		$this->restrict_tests_to_admins();
		$prompt_id = $this->createPopup();

		$response = $this->save_prompt_as(
			$prompt_id,
			'editor',
			[
				'meta' => [
					Newspack_Popups_AB_Tests::META_CONTROL_SHARE => null,
					'trigger_delay' => 7,
				],
			]
		);

		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( '7', get_post_meta( $prompt_id, 'trigger_delay', true ) );
	}

	/**
	 * A null for a stored control share of 60 would reset it to 50, which changes
	 * the live split, so a restricted user's save is refused before anything saves.
	 */
	public function test_restricted_editor_null_control_share_on_a_stored_share_is_refused() {
		$this->restrict_tests_to_admins();
		$prompt_id    = $this->create_test_variant( 'null-share-test', 'a', null, [], 60 );
		$title_before = get_the_title( $prompt_id );

		$response = $this->save_prompt_as(
			$prompt_id,
			'editor',
			[
				'title' => 'Edited alongside a share reset',
				'meta'  => [ Newspack_Popups_AB_Tests::META_CONTROL_SHARE => null ],
			]
		);

		self::assertSame( 403, $response->get_status() );
		$this->assert_refused_by_guard( $response );
		self::assertSame( '60', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_CONTROL_SHARE, true ) );
		self::assertSame( $title_before, get_the_title( $prompt_id ) );
	}

	/**
	 * Administrators can create and change tests through REST, including when
	 * test management is narrowed to them.
	 */
	public function test_administrator_can_write_ab_meta() {
		$this->restrict_tests_to_admins();
		$prompt_id = $this->createPopup();

		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => 'donation' ] ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'donation', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
	}

	/**
	 * The flag gates what reads A/B meta, never its registration, so REST writes
	 * keep their sanitizers and schema checks while the feature is off. A gate
	 * added where the class hooks in at load would not show here, since the suite
	 * runs with the flag on.
	 */
	public function test_flag_off_keeps_meta_registered() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		unregister_post_meta( Newspack_Popups::NEWSPACK_POPUPS_CPT, Newspack_Popups_AB_Tests::META_VARIANT );

		Newspack_Popups_AB_Tests::register_meta();

		$registered = get_registered_meta_keys( 'post', Newspack_Popups::NEWSPACK_POPUPS_CPT );
		self::assertArrayHasKey( Newspack_Popups_AB_Tests::META_VARIANT, $registered );
	}

	/**
	 * The conversion goal is one of four fixed values. A value outside the list
	 * is refused with a schema error the editor can show, not coerced to "no goal".
	 */
	public function test_goal_meta_accepts_only_the_four_goals() {
		$prompt_id = $this->createPopup();
		foreach ( Newspack_Popups_AB_Tests::VALID_GOALS as $goal ) {
			$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => $goal ] ] );
			self::assertSame( 200, $response->get_status(), $goal . ': ' . wp_json_encode( $response->get_data() ) );
			self::assertSame( $goal, get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
		}
		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => 'any_interaction' ] ] );
		self::assertSame( 400, $response->get_status(), 'A goal outside the list is a schema error, not a silent reset.' );
		self::assertSame( 'subscription', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
		// The empty string is the "no goal" state and stays writable.
		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_GOAL => '' ] ] );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ 'form_submission', 'link_click', 'donation', 'subscription' ], array_keys( Newspack_Popups_AB_Tests::get_goal_labels() ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::sanitize_goal( 'Donation' ) );
	}

	/**
	 * Manual and custom placements render through a block or a shortcode, so the
	 * bucketing has nothing to split.
	 */
	public function test_manual_and_custom_placements_are_ineligible() {
		self::assertFalse( Newspack_Popups_AB_Tests::is_eligible_placement( 'manual' ) );
		self::assertFalse( Newspack_Popups_AB_Tests::is_eligible_placement( 'custom1' ) );
		self::assertFalse( Newspack_Popups_AB_Tests::is_eligible_placement( 'custom2' ) );
		self::assertTrue( Newspack_Popups_AB_Tests::is_eligible_placement( 'inline' ) );
		self::assertTrue( Newspack_Popups_AB_Tests::is_eligible_placement( 'center' ) );
		self::assertFalse( Newspack_Popups_AB_Tests::is_eligible_placement( 'center', true ) );
		// Manual only is an overlay setting; an inline prompt ignores it.
		self::assertTrue( Newspack_Popups_AB_Tests::is_eligible_placement( 'inline', true ) );
	}

	/**
	 * An overlay set to Manual only is placed by a block or shortcode as a manual
	 * prompt is, so a tested overlay can't be switched to it.
	 */
	public function test_a_tested_overlay_cannot_be_set_to_manual_only() {
		$tested_id = $this->create_test_variant( 'manual-only-move', 'a', [ 'placement' => 'center' ] );
		$response  = $this->save_prompt_as( $tested_id, 'administrator', [ 'meta' => [ 'manual_only' => true ] ] );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'newspack_popups_ab_ineligible_placement', $response->get_data()['code'] );
		self::assertStringContainsString( 'Manual only', $response->get_data()['message'] );
		self::assertSame( '', (string) get_post_meta( $tested_id, 'manual_only', true ) );
	}

	/**
	 * The rule reads the placement the save would leave, not only the one it sends,
	 * so moving a tested prompt to an ineligible placement is refused as well.
	 */
	public function test_moving_a_tested_prompt_to_an_ineligible_placement_is_refused() {
		$prompt_id = $this->create_test_variant( 'placement-move', 'a', [ 'placement' => 'inline' ] );
		$response  = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ 'placement' => 'manual' ] ] );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'inline', get_post_meta( $prompt_id, 'placement', true ) );
		// An empty Test ID in the same save is ignored, so the move is still
		// refused; ending a running test is the way out.
		$response = $this->save_prompt_as(
			$prompt_id,
			'administrator',
			[
				'meta' => [
					'placement'                            => 'manual',
					Newspack_Popups_AB_Tests::META_TEST_ID => '',
				],
			]
		);
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'placement-move', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * The rule refuses the save that creates a violation, not every later edit of
	 * a prompt already in one; the guard's own comment says why that matters.
	 */
	public function test_a_prompt_already_breaking_the_placement_rule_keeps_taking_other_edits() {
		$prompt_id = $this->create_test_variant( 'stuck-test', 'a', [ 'placement' => 'inline' ] );
		update_post_meta( $prompt_id, 'placement', 'manual' );

		$response = $this->save_prompt_as(
			$prompt_id,
			'administrator',
			[
				'meta' => [
					'placement'                            => 'manual',
					Newspack_Popups_AB_Tests::META_TEST_ID => 'stuck-test',
					'frequency'                            => 'once',
				],
			]
		);
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'once', get_post_meta( $prompt_id, 'frequency', true ) );
	}

	/**
	 * A Test ID a save sends is left out of the write, so an unrelated save
	 * cannot rewrite one the earlier sanitizer stored, and a deliberate one
	 * can't either: only starting, resuming and ending a test set it.
	 */
	public function test_a_save_never_rewrites_a_stored_test_id() {
		global $wpdb;
		$prompt_id = $this->create_test_variant( 'footer-test', 'a' );
		// A value the earlier sanitizer stored; the current one can't write it.
		$wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->postmeta,
			[ 'meta_value' => 'footer_test' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[
				'post_id'  => $prompt_id,
				'meta_key' => Newspack_Popups_AB_Tests::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		wp_cache_delete( $prompt_id, 'post_meta' );
		self::assertSame( 'footer_test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );

		// Sent back as stored, the way the editor sends its whole meta object.
		$response = $this->save_prompt_as(
			$prompt_id,
			'administrator',
			[
				'meta' => [
					'frequency'                            => 'once',
					Newspack_Popups_AB_Tests::META_TEST_ID => 'footer_test',
				],
			]
		);
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'once', get_post_meta( $prompt_id, 'frequency', true ) );
		self::assertSame( 'footer_test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );

		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => 'Footer_Test 2' ] ] );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'footer_test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * What the prompt editor is told: the flag and the permission decide whether
	 * the panel shows and whether its fields are writable.
	 */
	public function test_editor_data_reflects_the_flag_and_the_permission() {
		$prompt_id = $this->createPopup();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = Newspack_Popups_AB_Tests::get_editor_data( $prompt_id );
		self::assertTrue( $data['enabled'] );
		self::assertTrue( $data['can_manage_tests'] );
		self::assertSame( [ 'form_submission', 'link_click', 'donation', 'subscription' ], array_keys( $data['goals'] ) );
		self::assertContains( 'manual', $data['ineligible_placements'] );
		self::assertContains( 'custom1', $data['ineligible_placements'] );
		self::assertSame( '', $data['results_url'], 'The results page is for administrators only.' );

		$editor_id = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['results_url'], 'Without the Audience menu there is no results page.' );
		$GLOBALS['admin_page_hooks']['newspack-audience'] = 'audience'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for newspack-plugin's Audience menu.
		self::assertStringEndsWith( 'page=newspack-audience-campaigns#/ab-tests', Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['results_url'] );
		wp_set_current_user( $editor_id );

		$this->restrict_tests_to_admins();
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['can_manage_tests'] );
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( 0 )['can_manage_tests'], 'A new prompt asks with post ID 0.' );

		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['enabled'] );
	}

	/**
	 * With the flag off the editor still receives this data, so it must not
	 * link to a results page that doesn't exist.
	 */
	public function test_editor_data_has_no_results_link_with_the_flag_off() {
		$prompt_id = $this->createPopup();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		self::assertSame( '', Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['results_url'] );
	}

	/**
	 * The editor's "other prompts in this test" list reads this route: every
	 * prompt carrying the Test ID except trashed ones, in ID order, for anyone
	 * who can edit prompts.
	 */
	public function test_test_prompts_route_lists_every_prompt_in_the_test() {
		$control    = $this->create_test_variant( 'lookup-test', 'a', null, [], 60 );
		$challenger = $this->create_test_variant( 'lookup-test', 'b', null, [ 'post_status' => 'draft' ] );
		update_post_meta( $control, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		$this->create_test_variant( 'lookup-test', 'b', null, [ 'post_status' => 'trash' ] );
		$this->create_test_variant( 'other-test', 'a' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/lookup-test' ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ $control, $challenger ], wp_list_pluck( $response->get_data(), 'id' ) );
		self::assertSame( [ 'a', 'b' ], wp_list_pluck( $response->get_data(), 'variant' ) );
		self::assertStringContainsString( 'post=' . $control, $response->get_data()[0]['edit_link'] );
		self::assertSame( [ 'donation', '' ], wp_list_pluck( $response->get_data(), 'goal' ) );
		self::assertSame( [ 60, 50 ], wp_list_pluck( $response->get_data(), 'control_share' ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => '0' ],
			[
				'post_id'  => $control,
				'meta_key' => Newspack_Popups_AB_Tests::META_CONTROL_SHARE,
			]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		wp_cache_delete( $control, 'post_meta' );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/lookup-test' ) );
		self::assertSame( [ 50, 50 ], wp_list_pluck( $response->get_data(), 'control_share' ) );

		self::assertSame( [], rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/no-such-test' ) )->get_data() );
		self::assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/Not%20A%20Slug' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/lookup-test' ) )->get_status() );
	}

	/**
	 * A Test ID stored by the earlier sanitize_title() callback keeps its underscore,
	 * since the save guard leaves an unchanged ID alone, so the lookup still finds it.
	 */
	public function test_test_prompts_route_finds_a_legacy_test_id() {
		global $wpdb;
		$control = $this->create_test_variant( 'footer-test', 'a' );
		$other   = $this->create_test_variant( 'footer-test', 'b' );
		// A value the earlier sanitizer stored; the current one can't write it.
		$wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->postmeta,
			[ 'meta_value' => 'footer_test' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[
				'post_id'  => $control,
				'meta_key' => Newspack_Popups_AB_Tests::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		wp_cache_delete( $control, 'post_meta' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/footer_test' ) );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( [ $control ], wp_list_pluck( $response->get_data(), 'id' ) );
		// A different test on the front end, so not one of this test's prompts.
		self::assertNotContains( $other, wp_list_pluck( $response->get_data(), 'id' ) );
		// Nor does an underscore ID with no stored match borrow the hyphenated test.
		$this->create_test_variant( 'header-test', 'a' );
		self::assertSame( [], rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/header_test' ) )->get_data() );
	}

	/**
	 * Another author's private prompt is listed to administrators and hidden from
	 * a user without read_private_posts, which is the rule the Prompts list and
	 * core's REST endpoint already apply. Drafts stay listed to everyone who can
	 * edit prompts, since a challenger's author needs to see the control.
	 */
	public function test_test_prompts_route_hides_other_authors_private_prompts() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->create_test_variant(
			'private-test',
			'a',
			null,
			[
				'post_status' => 'private',
				'post_author' => $admin_id,
			]
		);
		$this->create_test_variant(
			'private-test',
			'b',
			null,
			[
				'post_status' => 'draft',
				'post_author' => $admin_id,
			]
		);

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/private-test' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		self::assertSame( [ 'draft' ], wp_list_pluck( rest_do_request( $request )->get_data(), 'status' ) );

		wp_set_current_user( $admin_id );
		$prompts = rest_do_request( $request )->get_data();
		self::assertSame( [ 'private', 'draft' ], wp_list_pluck( $prompts, 'status' ) );
		// The panel shows the label, not the slug.
		self::assertSame( [ 'Private', 'Draft' ], wp_list_pluck( $prompts, 'status_label' ) );
	}

	/**
	 * A stored Test ID uses the characters the editor's slug and the lookup route
	 * allow: lowercase letters, digits and hyphens. sanitize_title() alone keeps
	 * underscores and percent-encodes what it can't transliterate, and an ID with
	 * either could be stored and never looked up.
	 */
	public function test_test_id_is_normalized_to_the_editor_charset() {
		$prompt_id = $this->createPopup();

		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, '  Footer_Test v1.2  ' );

		self::assertSame( 'footer-test-v1-2', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::sanitize_test_id( 'тест' ) );
		self::assertSame( [ $prompt_id ], wp_list_pluck( Newspack_Popups_AB_Tests::get_test_prompts( 'footer_test v1.2' ), 'id' ) );
	}

	/**
	 * Clearing a Test ID leaves an empty string, not a phantom test: an empty
	 * value must never fall back to the meta key's name. WordPress calls a meta
	 * sanitize callback with the meta key as its second argument, which is the
	 * fallback a sanitizer built on sanitize_title() would otherwise store.
	 */
	public function test_clearing_the_test_id_stores_an_empty_string() {
		$prompt_id = $this->create_test_variant( 'clear-me', 'a' );

		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, '' );
		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, '' );

		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::sanitize_test_id( [ 'x' ] ) );
		self::assertSame( 'spring-drive-2026', Newspack_Popups_AB_Tests::sanitize_test_id( 'Spring Drive 2026' ) );
	}

	/**
	 * With the flag off, stored tests have no effect, the placement rule included.
	 *
	 * A site that ran tests and turned the flag off may still hold a tested prompt
	 * on a manual placement, and the End test route that clears the Test ID is
	 * gone with the flag, so a refusal here would leave that prompt unsaveable.
	 */
	public function test_placement_rule_is_off_with_the_flag() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		$prompt_id = $this->create_test_variant( 'flag-off-move', 'a', [ 'placement' => 'inline' ] );

		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ 'placement' => 'manual' ] ] );

		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'manual', get_post_meta( $prompt_id, 'placement', true ) );
		self::assertSame( 'flag-off-move', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}
}
