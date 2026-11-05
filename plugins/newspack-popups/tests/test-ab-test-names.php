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
	 * Renaming a test to a name another test holds, after both are named.
	 */
	public function test_rename_to_a_name_another_named_test_holds() {
		$this->sign_in();
		$this->create_test_variant( 'first', 'a' );
		$this->create_test_variant( 'second', 'a' );
		$this->rename_request( 'first', 'One' );
		$this->rename_request( 'second', 'Two' );
		$this->assertSame( 200, $this->rename_request( 'second', 'One' )->get_status() );
		$this->assertSame( 'One', $this->test_row( 'second' )['name'] );
		$this->assertSame( 'One', $this->test_row( 'first' )['name'] );
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
	 * An ID already held by a prompt, trashed or not, is never handed out again.
	 */
	public function test_create_test_skips_ids_in_use() {
		$prompt_id = $this->create_test_variant( 'taken-0000', 'a' );
		wp_trash_post( $prompt_id );
		$method = new ReflectionMethod( Newspack_Popups_AB_Tests::class, 'is_test_id_in_use' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( null, 'taken-0000' ) );
		$this->assertFalse( $method->invoke( null, 'free-0000' ) );
	}

	/**
	 * An empty name is refused.
	 */
	public function test_create_test_refuses_an_empty_name() {
		$this->assertInstanceOf( WP_Error::class, Newspack_Popups_AB_Tests::create_test( '' ) );
	}
}
