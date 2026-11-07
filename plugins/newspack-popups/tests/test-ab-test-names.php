<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for A/B test IDs and display names.
 *
 * @package Newspack_Popups
 */

/**
 * Test names.
 *
 * @group ab-tests
 */
class ABTestNamesTest extends WP_UnitTestCase_PageWithPopups {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		$GLOBALS['wp_rest_server'] = null;
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
	 * The row get_tests() returns for one test.
	 *
	 * @param string $test_id Test ID.
	 * @return array|null
	 */
	private function test_row( $test_id ) {
		foreach ( Newspack_Popups_AB_Tests::get_tests() as $test ) {
			if ( $test_id === $test['test_id'] ) {
				return $test;
			}
		}
		return null;
	}

	/**
	 * Send a rename through the route.
	 *
	 * @param string $test_id Test ID.
	 * @param mixed  $name    Name.
	 * @return WP_REST_Response
	 */
	private function rename_request( $test_id, $name ) {
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/ab-tests/' . $test_id . '/name' );
		$request->set_param( 'name', $name );
		return rest_do_request( $request );
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
	 * A test made before names existed shows its ID as its name.
	 */
	public function test_unnamed_test_shows_its_id() {
		$this->create_test_variant( 'registration-wall-copy', 'a' );
		$this->assertSame( 'registration-wall-copy', $this->test_row( 'registration-wall-copy' )['name'] );
		$this->assertSame( 'registration-wall-copy', Newspack_Popups_AB_Tests::get_test_name( 'registration-wall-copy' ) );
	}

	/**
	 * Renaming an unnamed test creates its record under the existing ID.
	 */
	public function test_rename_names_an_existing_test_without_changing_its_id() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->create_test_variant( 'spring-drive', 'b' );

		$response = $this->rename_request( 'spring-drive', 'Spring membership drive' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'test_id' => 'spring-drive',
				'name'    => 'Spring membership drive',
			],
			$response->get_data()
		);
		$row = $this->test_row( 'spring-drive' );
		$this->assertSame( 'Spring membership drive', $row['name'] );
		$this->assertSame( 'spring-drive', $row['test_id'] );
	}

	/**
	 * Renaming a named test updates the name and keeps the slug.
	 */
	public function test_second_rename_keeps_the_slug() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->rename_request( 'spring-drive', 'First name' );
		$this->rename_request( 'spring-drive', 'Second name' );

		$term = get_term_by( 'slug', 'spring-drive', Newspack_Popups_AB_Tests::TAXONOMY );
		$this->assertSame( 'Second name', $term->name );
		$this->assertSame( 1, (int) wp_count_terms( [ 'taxonomy' => Newspack_Popups_AB_Tests::TAXONOMY ] ) );
	}

	/**
	 * Two tests can carry the same name, by rename or at creation.
	 */
	public function test_two_tests_can_share_a_name() {
		$this->sign_in();
		$this->create_test_variant( 'first', 'a' );
		$this->create_test_variant( 'second', 'a' );
		$this->assertSame( 200, $this->rename_request( 'first', 'Donate banner' )->get_status() );
		$this->assertSame( 200, $this->rename_request( 'second', 'Donate banner' )->get_status() );
		$this->assertSame( 'Donate banner', $this->test_row( 'first' )['name'] );
		$this->assertSame( 'Donate banner', $this->test_row( 'second' )['name'] );

		$created = Newspack_Popups_AB_Tests::create_test( 'Donate banner' );
		$this->assertIsString( $created );
		$this->assertSame( 'Donate banner', Newspack_Popups_AB_Tests::get_test_name( $created ) );
	}

	/**
	 * A name with `&`, a backslash and quotes comes back as entered, though core
	 * stores `&` encoded and strips backslashes from unslashed input.
	 */
	public function test_name_round_trips_as_entered() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$name = 'News & Culture \\ "Tips"';

		$first = $this->rename_request( 'spring-drive', $name );
		$this->assertSame( $name, $first->get_data()['name'] );
		$this->assertSame( $name, $this->test_row( 'spring-drive' )['name'] );

		$this->rename_request( 'spring-drive', 'Members & donors \\ 2026' );
		$this->assertSame( 'Members & donors \\ 2026', Newspack_Popups_AB_Tests::get_test_name( 'spring-drive' ) );

		$created = Newspack_Popups_AB_Tests::create_test( $name );
		$this->assertSame( $name, Newspack_Popups_AB_Tests::get_test_name( $created ) );
	}

	/**
	 * A rename that races another one to create the same test's record still
	 * lands on that record, whether or not the two names match.
	 *
	 * @dataProvider data_racing_names
	 * @param string $earlier Name the other request stored.
	 */
	public function test_rename_that_loses_the_create_race_updates_the_record( $earlier ) {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		wp_insert_term( $earlier, Newspack_Popups_AB_Tests::TAXONOMY, [ 'slug' => 'spring-drive' ] );
		// The lookup misses once, as it would for a request that ran before the
		// other one stored its term.
		$miss_once = function ( $terms, $query ) use ( &$miss_once ) {
			if ( 1 === $query->query_vars['number'] && in_array( 'spring-drive', (array) $query->query_vars['slug'], true ) ) {
				remove_filter( 'terms_pre_query', $miss_once, 10 );
				return [];
			}
			return $terms;
		};
		add_filter( 'terms_pre_query', $miss_once, 10, 2 );

		$response = $this->rename_request( 'spring-drive', 'Spring drive' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Spring drive', $this->test_row( 'spring-drive' )['name'] );
		$this->assertSame( 1, (int) wp_count_terms( [ 'taxonomy' => Newspack_Popups_AB_Tests::TAXONOMY ] ) );
	}

	/**
	 * A rename that loses the create race leaves no name on a test whose ID is
	 * the suffixed slug core gave the losing term.
	 */
	public function test_rename_that_loses_the_create_race_leaves_other_tests_unnamed() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->create_test_variant( 'spring-drive-2', 'a' );
		wp_insert_term( 'Earlier name', Newspack_Popups_AB_Tests::TAXONOMY, [ 'slug' => 'spring-drive' ] );
		$miss_once = function ( $terms, $query ) use ( &$miss_once ) {
			if ( 1 === $query->query_vars['number'] && in_array( 'spring-drive', (array) $query->query_vars['slug'], true ) ) {
				remove_filter( 'terms_pre_query', $miss_once, 10 );
				return [];
			}
			return $terms;
		};
		add_filter( 'terms_pre_query', $miss_once, 10, 2 );

		$this->assertSame( 200, $this->rename_request( 'spring-drive', 'Spring drive' )->get_status() );
		$this->assertSame( 'Spring drive', $this->test_row( 'spring-drive' )['name'] );
		$this->assertSame( 'spring-drive-2', $this->test_row( 'spring-drive-2' )['name'] );
	}

	/**
	 * When core hands a racing insert another test's term instead of the new
	 * one, that term is left alone.
	 */
	public function test_rename_leaves_a_term_core_hands_back() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->create_test_variant( 'autumn-drive', 'a' );
		$other     = wp_insert_term( 'Autumn drive', Newspack_Popups_AB_Tests::TAXONOMY, [ 'slug' => 'autumn-drive' ] );
		$hand_back = function () use ( $other, &$hand_back ) {
			remove_filter( 'wp_insert_term_duplicate_term_check', $hand_back );
			return (object) [
				'term_id'          => $other['term_id'],
				'term_taxonomy_id' => $other['term_taxonomy_id'],
			];
		};
		add_filter( 'wp_insert_term_duplicate_term_check', $hand_back );

		$this->rename_request( 'spring-drive', 'Spring drive' );

		$this->assertSame( 'Autumn drive', $this->test_row( 'autumn-drive' )['name'] );
	}

	/**
	 * Names the losing request may race against.
	 *
	 * @return array
	 */
	public function data_racing_names() {
		return [
			'same name'      => [ 'Spring drive' ],
			'different name' => [ 'Earlier name' ],
		];
	}

	/**
	 * A digits-only ID stays a string and finds its name.
	 */
	public function test_numeric_test_id_keeps_its_name() {
		$this->sign_in();
		$this->create_test_variant( '2024', 'a' );
		$this->assertSame( 200, $this->rename_request( '2024', 'Year-end appeal' )->get_status() );
		$row = $this->test_row( '2024' );
		$this->assertSame( '2024', $row['test_id'] );
		$this->assertSame( 'Year-end appeal', $row['name'] );
	}

	/**
	 * A legacy ID with underscores is its own test, apart from its hyphenated
	 * spelling, so each rename lands on the test it names.
	 */
	public function test_legacy_underscore_id_renames_its_own_test() {
		global $wpdb;
		$this->sign_in();
		$legacy_id = $this->create_test_variant( 'footer-test', 'a' );
		$this->create_test_variant( 'footer-test', 'a' );
		// A value the earlier sanitizer stored; the current one can't write it.
		$wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->postmeta,
			[ 'meta_value' => 'footer_test' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[
				'post_id'  => $legacy_id,
				'meta_key' => Newspack_Popups_AB_Tests::META_TEST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		wp_cache_delete( $legacy_id, 'post_meta' );

		$response = $this->rename_request( 'footer_test', 'Legacy footer' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'footer_test', $response->get_data()['test_id'] );
		$this->assertSame( 'Legacy footer', $this->test_row( 'footer_test' )['name'] );
		$this->assertSame( 'footer-test', $this->test_row( 'footer-test' )['name'] );
		$this->assertSame( 'Legacy footer', Newspack_Popups_AB_Tests::get_test_name( 'footer_test' ) );
	}

	/**
	 * The route refuses an empty name and leaves the test as it was.
	 */
	public function test_rename_refuses_an_empty_name() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->assertSame( 400, $this->rename_request( 'spring-drive', '   ' )->get_status() );
		$this->assertFalse( get_term_by( 'slug', 'spring-drive', Newspack_Popups_AB_Tests::TAXONOMY ) );
	}

	/**
	 * A test with no prompts can't be named, so the route can't mint records.
	 */
	public function test_rename_refuses_a_test_with_no_prompts() {
		$this->sign_in();
		$this->assertSame( 403, $this->rename_request( 'no-such-test', 'Name' )->get_status() );
		$this->assertInstanceOf( WP_Error::class, Newspack_Popups_AB_Tests::rename_test( 'no-such-test', 'Name' ) );
		$this->assertFalse( get_term_by( 'slug', 'no-such-test', Newspack_Popups_AB_Tests::TAXONOMY ) );
	}

	/**
	 * Renaming needs the right to change every prompt in the test.
	 */
	public function test_rename_needs_every_prompt() {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->create_test_variant( 'mixed', 'a', [ 'post_author' => $author_id ] );
		$this->create_test_variant( 'mixed', 'b' );
		wp_set_current_user( $author_id );
		$this->assertSame( 403, $this->rename_request( 'mixed', 'Mine' )->get_status() );

		$this->sign_in( 'editor' );
		$this->assertSame( 200, $this->rename_request( 'mixed', 'Ours' )->get_status() );
	}

	/**
	 * A new ID is the name's slug plus a suffix, unique across calls.
	 */
	public function test_create_test_makes_a_readable_unique_id() {
		$first  = Newspack_Popups_AB_Tests::create_test( 'Spring Drive!' );
		$second = Newspack_Popups_AB_Tests::create_test( 'Spring Drive!' );
		$this->assertMatchesRegularExpression( '/^spring-drive-[0-9a-f]{4}$/', $first );
		$this->assertNotSame( $first, $second );
		$this->assertSame( $first, Newspack_Popups_AB_Tests::sanitize_test_id( $first ), 'The ID survives the meta sanitizer unchanged.' );
		$this->assertSame( $first, get_term_by( 'slug', $first, Newspack_Popups_AB_Tests::TAXONOMY )->slug );
	}

	/**
	 * A name with no Latin letters still gets an ID, and keeps its name.
	 */
	public function test_create_test_handles_a_name_with_no_slug() {
		$test_id = Newspack_Popups_AB_Tests::create_test( 'Тест подписки' );
		$this->assertMatchesRegularExpression( '/^test-[0-9a-f]{4}$/', $test_id );
		$this->assertSame( 'Тест подписки', Newspack_Popups_AB_Tests::get_test_name( $test_id ) );
	}

	/**
	 * A long name is cut so the ID fits GA4's parameter limit.
	 */
	public function test_create_test_caps_the_id_length() {
		$test_id = Newspack_Popups_AB_Tests::create_test( str_repeat( 'very long name ', 10 ) );
		$this->assertIsString( $test_id );
		$this->assertLessThanOrEqual( 45, strlen( $test_id ) );
		$this->assertStringNotContainsString( '--', $test_id );
	}

	/**
	 * A name longer than a term name can hold is refused with a 400, not stored cut short.
	 */
	public function test_rename_refuses_a_name_over_200_characters() {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->assertSame( 400, $this->rename_request( 'spring-drive', str_repeat( 'x', 201 ) )->get_status() );
		$this->assertSame( 200, $this->rename_request( 'spring-drive', str_repeat( 'x', 200 ) )->get_status() );
	}

	/**
	 * Names one character over and exactly at the stored limit.
	 *
	 * @return array
	 */
	public function data_names_at_the_limit() {
		return [
			'ampersand, stored as five characters' => [ str_repeat( 'x', 196 ) . '&', str_repeat( 'x', 195 ) . '&' ],
			'backslash'                            => [ str_repeat( 'x', 200 ) . '\\', str_repeat( 'x', 199 ) . '\\' ],
			'apostrophe'                           => [ str_repeat( 'x', 200 ) . "'", str_repeat( 'x', 199 ) . "'" ],
		];
	}

	/**
	 * The limit applies to the name as stored, so a rename never reports success
	 * for a name that wasn't saved, nor refuses one that fits.
	 *
	 * @dataProvider data_names_at_the_limit
	 * @param string $too_long A name one character over the limit as stored.
	 * @param string $fits     A name exactly at the limit as stored.
	 */
	public function test_rename_measures_the_name_as_stored( $too_long, $fits ) {
		$this->sign_in();
		$this->create_test_variant( 'spring-drive', 'a' );
		$this->rename_request( 'spring-drive', 'Kept' );
		$this->assertSame( 400, $this->rename_request( 'spring-drive', $too_long )->get_status() );
		$this->assertSame( 'Kept', $this->test_row( 'spring-drive' )['name'] );
		$this->assertSame( 200, $this->rename_request( 'spring-drive', $fits )->get_status() );
		$this->assertSame( $fits, $this->test_row( 'spring-drive' )['name'] );
	}

	/**
	 * An ID held by a prompt, trashed or not, counts as in use.
	 */
	public function test_trashed_prompt_keeps_its_id_in_use() {
		$prompt_id = $this->create_test_variant( 'taken-0000', 'a' );
		wp_trash_post( $prompt_id );
		$is_test_id_in_use = new ReflectionMethod( Newspack_Popups_AB_Tests::class, 'is_test_id_in_use' );
		$this->assertTrue( $is_test_id_in_use->invoke( null, 'taken-0000' ) );
		$this->assertFalse( $is_test_id_in_use->invoke( null, 'free-0000' ) );
	}

	/**
	 * An empty name is refused.
	 */
	public function test_create_test_refuses_an_empty_name() {
		$this->assertInstanceOf( WP_Error::class, Newspack_Popups_AB_Tests::create_test( '' ) );
	}
}
