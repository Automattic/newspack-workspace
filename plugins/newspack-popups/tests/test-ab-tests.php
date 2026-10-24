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
	 * An Editor can save a prompt's other settings whether or not it is in a test:
	 * A/B values sent back unchanged never need manage_options.
	 */
	public function test_editor_can_save_a_prompt_without_changing_its_test() {
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
	 * An Editor's request that changes a test is refused before anything saves,
	 * so the editor never reports a failure over a half-applied change.
	 */
	public function test_editor_cannot_change_a_test_and_nothing_saves() {
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
	 * On the autosave route the guard drops a non-admin's A/B keys instead of
	 * refusing: core's autosave controller ignores a refusal and would record an
	 * empty autosave in place of the editor's changes. Called directly, because
	 * the controller defines DOING_AUTOSAVE for the rest of the test run.
	 */
	public function test_guard_drops_ab_meta_on_autosaves_instead_of_refusing() {
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
	 * Administrators can create and change tests through REST.
	 */
	public function test_administrator_can_write_ab_meta() {
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
