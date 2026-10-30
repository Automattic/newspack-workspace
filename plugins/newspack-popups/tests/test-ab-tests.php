<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ABTests Test
 *
 * @package Newspack_Popups
 */

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
		self::assertSame( $assigned, get_user_meta( $user_id, 'newspack_popups_ab_bucket_sticky-test', true ) );

		// A changed control share must not re-bucket an already-assigned reader.
		update_user_meta( $user_id, 'newspack_popups_ab_bucket_sticky-test', 'b' );
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
		return count(
			get_posts(
				[
					'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
				]
			)
		);
	}

	/**
	 * Assert the refusal came from the guard. Core's own meta refusals are also
	 * 403s with the same error code, so only the message tells them apart.
	 *
	 * @param WP_REST_Response $response Response to a refused save.
	 */
	private function assert_refused_by_guard( $response ) {
		self::assertStringStartsWith( "You don't have permission to change this prompt's A/B test settings.", $response->get_data()['message'] );
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

		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => 'editor-made-test' ] ] );
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'editor-made-test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );

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
					Newspack_Popups_AB_Tests::META_TEST_ID => 'editor-made-test',
					'trigger_delay'                        => 7,
				],
			]
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
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

		$response = $this->save_prompt_as( $prompt_id, 'editor', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => [ 'x' ] ] ] );

		self::assertSame( 403, $response->get_status() );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
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
	 * A restricted user's create that sets a test is refused before the prompt is
	 * created. Without the guard, core would insert the post and only then refuse
	 * the meta, leaving a prompt behind with the request reported as failed.
	 */
	public function test_restricted_editor_cannot_create_a_prompt_with_a_test() {
		$this->restrict_tests_to_admins();
		$before = $this->count_prompts();

		$response = $this->create_prompt_as( 'editor', [ Newspack_Popups_AB_Tests::META_TEST_ID => 'editor-made-test' ] );

		self::assertSame( 403, $response->get_status() );
		$this->assert_refused_by_guard( $response );
		self::assertSame( $before, $this->count_prompts() );
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

		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => 'admin-made-test' ] ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'admin-made-test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * Pins a decision rather than this change: the flag gates what reads A/B meta,
	 * never its registration, so REST writes keep their sanitizers and schema
	 * checks while the feature is off. A gate added where the class hooks in at
	 * load would not show here, since the suite runs with the flag on.
	 */
	public function test_flag_off_keeps_meta_registered() {
		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		unregister_post_meta( Newspack_Popups::NEWSPACK_POPUPS_CPT, Newspack_Popups_AB_Tests::META_VARIANT );

		Newspack_Popups_AB_Tests::register_meta();

		$registered = get_registered_meta_keys( 'post', Newspack_Popups::NEWSPACK_POPUPS_CPT );
		self::assertArrayHasKey( Newspack_Popups_AB_Tests::META_VARIANT, $registered );
	}
}
