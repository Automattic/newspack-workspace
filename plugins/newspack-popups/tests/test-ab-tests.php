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
			self::assertTrue( $registered[ $key ]['show_in_rest'], "Meta key $key should be exposed in REST." );
		}
	}

	/**
	 * The popup object carries A/B fields when the prompt is part of a test.
	 */
	public function test_popup_object_has_ab_fields() {
		$popup_id = $this->create_test_variant( 'donate-q3', 'b' );
		$popup    = Newspack_Popups_Model::create_popup_object( get_post( $popup_id ) );
		self::assertSame( 'donate-q3', $popup['ab_test_id'] );
		self::assertSame( 'b', $popup['ab_variant'] );

		$plain_popup = Newspack_Popups_Model::create_popup_object( get_post( self::$popup_id ) );
		self::assertArrayNotHasKey( 'ab_test_id', $plain_popup );
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
		self::assertSame( $assigned, get_user_meta( $user_id, 'np_ab_bucket_sticky-test', true ) );

		// A changed control share must not re-bucket an already-assigned reader.
		update_user_meta( $user_id, 'np_ab_bucket_sticky-test', 'b' );
		$buckets = Newspack_Popups_AB_Tests::get_logged_in_buckets( Newspack_Popups_AB_Tests::get_tests_config() );
		self::assertSame( 'b', $buckets['sticky-test'] );

		wp_set_current_user( 0 );
	}

	/**
	 * GA event metadata includes A/B params for test prompts.
	 */
	public function test_ga_metadata_includes_ab_params() {
		$popup_id = $this->create_test_variant( 'ga-test', 'b' );
		$metadata = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		self::assertSame( 'ga-test', $metadata['ab_test_id'] );
		self::assertSame( 'b', $metadata['ab_variant'] );

		$plain_metadata = Newspack_Popups_Data_Api::get_popup_metadata( self::$popup_id );
		self::assertArrayNotHasKey( 'ab_test_id', $plain_metadata );
	}
}
