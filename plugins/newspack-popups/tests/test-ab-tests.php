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
	 * The server-side djb2 hash is bit-for-bit identical to the client-side hash.
	 * The vector is pinned in src/view/utils/ab.test.js too — if either side
	 * changes, both parity tests fail together.
	 */
	public function test_hash_parity_with_client() {
		self::assertSame( 809016026, Newspack_Popups_AB_Tests::hash_djb2( 'abc|test-x' ) );
		$parity_config = [
			'variants'      => [ 'a', 'b' ],
			'control_share' => 50,
		];
		self::assertSame( 'a', Newspack_Popups_AB_Tests::compute_bucket( 'abc', 'test-x', $parity_config ) );
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
		// Meta registered at boot does not survive into later tests in this suite,
		// and REST silently ignores an unregistered key.
		Newspack_Popups::register_meta();
		Newspack_Popups_AB_Tests::register_meta();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );

		$route   = '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $prompt_id;
		$stored  = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data();
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( array_merge( $changes, [ 'meta' => array_merge( $stored['meta'], $changes['meta'] ?? [] ) ] ) );
		return rest_do_request( $request );
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

		// The placement rule is skipped on autosaves too: a draft mid-edit is not
		// a save, and refusing it would make the editor flash an error while typing.
		$tested_id = $this->create_test_variant( 'autosave-placement', 'a', [ 'placement' => 'inline' ] );
		$request   = new WP_REST_Request( 'POST', '/wp/v2/' . Newspack_Popups::NEWSPACK_POPUPS_CPT . '/' . $tested_id . '/autosaves' );
		$request->set_body_params( [ 'meta' => [ 'placement' => 'manual' ] ] );
		$prepared_post = (object) [ 'ID' => $tested_id ];
		self::assertSame( $prepared_post, Newspack_Popups_AB_Tests::guard_rest_ab_meta( $prepared_post, $request ) );
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
	 * bucketing has nothing to split. A Test ID on one is refused, for every user.
	 */
	public function test_a_test_id_is_refused_on_manual_and_custom_placements() {
		foreach ( [ 'manual', 'custom1' ] as $placement ) {
			$prompt_id = $this->createPopup( null, [ 'placement' => $placement ] );
			$response  = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => 'placement-test' ] ] );
			self::assertSame( 400, $response->get_status(), $placement );
			self::assertSame( 'newspack_popups_ab_ineligible_placement', $response->get_data()['code'] );
			self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		}
		self::assertFalse( Newspack_Popups_AB_Tests::is_eligible_placement( 'custom2' ) );
		self::assertTrue( Newspack_Popups_AB_Tests::is_eligible_placement( 'inline' ) );
		self::assertTrue( Newspack_Popups_AB_Tests::is_eligible_placement( 'center' ) );
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
		// Clearing the test in the same save is the way out.
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
		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( 'manual', get_post_meta( $prompt_id, 'placement', true ) );
	}

	/**
	 * The rule refuses the save that creates a violation, not every later edit of
	 * a prompt already in one. A tested prompt can reach a manual placement past
	 * the guard (the Campaigns wizard writes placement directly; a save while the
	 * flag is off is not checked), and the editor sends the whole meta object on
	 * every save, so without this a frequency edit on such a prompt would fail
	 * with an error about a field the editor may have disabled for that user.
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

		// Changing the Test ID is a new violation and is still refused.
		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => 'other-test' ] ] );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( 'stuck-test', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
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
		self::assertSame( [ 'a', 'b' ], array_keys( $data['variants'] ) );

		$this->restrict_tests_to_admins();
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['can_manage_tests'] );
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( 0 )['can_manage_tests'], 'A new prompt asks with post ID 0.' );

		add_filter( 'newspack_popups_ab_testing_enabled', '__return_false' );
		self::assertFalse( Newspack_Popups_AB_Tests::get_editor_data( $prompt_id )['enabled'] );
	}

	/**
	 * The editor's "other prompts in this test" list reads this route: every
	 * prompt carrying the Test ID except trashed ones, in ID order, for anyone
	 * who can edit prompts.
	 */
	public function test_test_prompts_route_lists_every_prompt_in_the_test() {
		$control    = $this->create_test_variant( 'lookup-test', 'a' );
		$challenger = $this->create_test_variant( 'lookup-test', 'b', null, [ 'post_status' => 'draft' ] );
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

		self::assertSame( [], rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/no-such-test' ) )->get_data() );
		self::assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/Not%20A%20Slug' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		self::assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests/lookup-test' ) )->get_status() );
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
	 * either could be stored through REST and never looked up.
	 */
	public function test_test_id_is_normalized_to_the_editor_charset() {
		$prompt_id = $this->createPopup();

		$response = $this->save_prompt_as( $prompt_id, 'administrator', [ 'meta' => [ Newspack_Popups_AB_Tests::META_TEST_ID => '  Footer_Test v1.2  ' ] ] );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'footer-test-v1-2', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::sanitize_test_id( 'тест' ) );
		self::assertSame( [ $prompt_id ], wp_list_pluck( Newspack_Popups_AB_Tests::get_test_prompts( 'footer_test v1.2' ), 'id' ) );
	}

	/**
	 * Clearing a Test ID through REST leaves an empty string, not a phantom test:
	 * an empty value must never fall back to the meta key's name. WordPress calls
	 * a meta sanitize callback with the meta key as its second argument, which is
	 * the fallback a sanitizer built on sanitize_title() would otherwise store.
	 */
	public function test_clearing_the_test_id_stores_an_empty_string() {
		$prompt_id = $this->create_test_variant( 'clear-me', 'a' );
		update_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );

		$response = $this->save_prompt_as(
			$prompt_id,
			'administrator',
			[
				'meta' => [
					Newspack_Popups_AB_Tests::META_TEST_ID => '',
					Newspack_Popups_AB_Tests::META_VARIANT => '',
					Newspack_Popups_AB_Tests::META_GOAL    => '',
				],
			]
		);

		self::assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
		self::assertSame( '', get_post_meta( $prompt_id, Newspack_Popups_AB_Tests::META_GOAL, true ) );
		self::assertSame( '', Newspack_Popups_AB_Tests::sanitize_test_id( [ 'x' ] ) );
		self::assertSame( 'spring-drive-2026', Newspack_Popups_AB_Tests::sanitize_test_id( 'Spring Drive 2026' ) );
	}

	/**
	 * With the flag off, stored tests have no effect, the placement rule included.
	 *
	 * A site that ran tests and turned the flag off may still hold a tested prompt
	 * on a manual placement, and the panel that clears the Test ID is gone with
	 * the flag, so a refusal here would leave that prompt unsaveable.
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
