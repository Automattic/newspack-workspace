<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for starting an A/B test and creating its version B.
 *
 * @package Newspack_Popups
 */

/**
 * Start test.
 *
 * @group ab-tests
 */
class ABStartTestTest extends WP_UnitTestCase_PageWithPopups {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		$GLOBALS['wp_rest_server'] = null;
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
	 * A published prompt on a placement.
	 *
	 * @param string $placement    Placement.
	 * @param array  $post_options Post fields.
	 * @return int Prompt ID.
	 */
	private function create_prompt( $placement = 'inline', $post_options = [] ) {
		$prompt_id = $this->createPopup( null, null, array_merge( [ 'post_title' => 'Spring drive' ], $post_options ) );
		update_post_meta( $prompt_id, 'placement', $placement );
		return $prompt_id;
	}

	/**
	 * Send a start through the route.
	 *
	 * @param array $params Request params.
	 * @return WP_REST_Response
	 */
	private function start_request( $params ) {
		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/ab-tests' );
		foreach ( array_merge(
			[
				'name'          => 'Spring drive',
				'goal'          => 'subscription',
				'control_share' => 60,
			],
			$params
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * How many name terms exist.
	 *
	 * @return int
	 */
	private function term_count() {
		return (int) wp_count_terms(
			[
				'taxonomy'   => Newspack_Popups_AB_Tests::TAXONOMY,
				'hide_empty' => false,
			]
		);
	}

	/**
	 * How many prompts exist, in any status.
	 *
	 * @return int
	 */
	private function prompt_count() {
		return count(
			get_posts(
				[
					'post_type'   => Newspack_Popups::NEWSPACK_POPUPS_CPT,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * A start makes A version A and a draft copy version B, in one named test.
	 */
	public function test_start_creates_version_b() {
		$this->sign_in();
		$a = $this->create_prompt( 'inline' );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 201, $response->get_status() );
		$data    = $response->get_data();
		$test_id = $data['test_id'];
		$b       = $data['version_b']['id'];
		$this->assertSame( 'Spring drive', $data['name'] );
		$this->assertNotEmpty( $data['version_b']['edit_link'] );

		$this->assertSame( $test_id, get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 'a', get_post_meta( $a, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
		$this->assertSame( 'subscription', get_post_meta( $a, Newspack_Popups_AB_Tests::META_GOAL, true ) );
		$this->assertSame( 60, (int) get_post_meta( $a, Newspack_Popups_AB_Tests::META_CONTROL_SHARE, true ) );

		$this->assertSame( 'draft', get_post_status( $b ) );
		$this->assertSame( 'Spring drive (B)', get_post( $b )->post_title );
		$this->assertSame( 'inline', get_post_meta( $b, 'placement', true ) );
		$this->assertSame( $test_id, get_post_meta( $b, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 'b', get_post_meta( $b, Newspack_Popups_AB_Tests::META_VARIANT, true ) );
	}

	/**
	 * B stores no goal or split of its own: A's are the test's.
	 */
	public function test_goal_and_split_are_stored_on_a_only() {
		$this->sign_in();
		$a = $this->create_prompt();

		$b = $this->start_request( [ 'prompt_id' => $a ] )->get_data()['version_b']['id'];

		$this->assertFalse( metadata_exists( 'post', $b, Newspack_Popups_AB_Tests::META_GOAL ) );
		$this->assertFalse( metadata_exists( 'post', $b, Newspack_Popups_AB_Tests::META_CONTROL_SHARE ) );
	}

	/**
	 * A prompt left with an empty Test ID by a cleared test can start a new one.
	 */
	public function test_start_on_a_prompt_with_a_cleared_test() {
		$this->sign_in();
		$a = $this->create_prompt();
		add_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, '' );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( [ $response->get_data()['test_id'] ], get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, false ) );
	}

	/**
	 * A prompt already in a test can't start another, and nothing is left behind.
	 */
	public function test_start_refuses_a_prompt_already_in_a_test() {
		$this->sign_in();
		$a = $this->create_prompt();
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive' );
		$terms   = $this->term_count();
		$prompts = $this->prompt_count();

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'spring-drive', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( $terms, $this->term_count() );
		$this->assertSame( $prompts, $this->prompt_count() );
	}

	/**
	 * Version A of a test with no other version resumes that test: same ID, the
	 * new name, goal and split, and a new B.
	 *
	 * @dataProvider data_resumable_tests
	 * @param string|null $old_b_status Status of an earlier B, or null for none.
	 */
	public function test_start_resumes_a_test_whose_only_version_is_a( $old_b_status ) {
		$this->sign_in();
		$a = $this->create_prompt();
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive' );
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_VARIANT, 'a' );
		update_post_meta( $a, Newspack_Popups_AB_Tests::META_GOAL, 'donation' );
		if ( $old_b_status ) {
			$old_b = $this->create_prompt( 'inline', [ 'post_status' => $old_b_status ] );
			update_post_meta( $old_b, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive' );
			update_post_meta( $old_b, Newspack_Popups_AB_Tests::META_VARIANT, 'b' );
		}

		$response = $this->start_request(
			[
				'prompt_id' => $a,
				'name'      => 'Spring drive, take two',
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'spring-drive', $data['test_id'] );
		$this->assertSame( 'Spring drive, take two', $data['name'] );
		$this->assertSame( 'spring-drive', get_post_meta( $data['version_b']['id'], Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 'subscription', get_post_meta( $a, Newspack_Popups_AB_Tests::META_GOAL, true ) );
	}

	/**
	 * Tests a start can resume.
	 *
	 * @return array
	 */
	public function data_resumable_tests() {
		return [
			'no B yet'       => [ null ],
			'B in the trash' => [ 'trash' ],
		];
	}

	/**
	 * A prompt that is B of a test, or A of one that already has a B, can't start.
	 *
	 * @dataProvider data_unresumable_tests
	 * @param string $variant      The prompt's version.
	 * @param string $other_status Status of the test's other version.
	 */
	public function test_start_refuses_to_resume_a_test_with_another_version( $variant, $other_status ) {
		$this->sign_in();
		$prompt = $this->create_prompt();
		update_post_meta( $prompt, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive' );
		update_post_meta( $prompt, Newspack_Popups_AB_Tests::META_VARIANT, $variant );
		$other = $this->create_prompt( 'inline', [ 'post_status' => $other_status ] );
		update_post_meta( $other, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive' );
		update_post_meta( $other, Newspack_Popups_AB_Tests::META_VARIANT, 'a' === $variant ? 'b' : 'a' );
		$prompts = $this->prompt_count();

		$response = $this->start_request( [ 'prompt_id' => $prompt ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $prompts, $this->prompt_count() );
	}

	/**
	 * Tests a start must not resume.
	 *
	 * @return array
	 */
	public function data_unresumable_tests() {
		return [
			'A with a draft B'      => [ 'a', 'draft' ],
			'A with a live B'       => [ 'a', 'publish' ],
			'B of a test with an A' => [ 'b', 'publish' ],
			'B of a test with no A' => [ 'b', 'trash' ],
		];
	}

	/**
	 * A manual placement can't start a test.
	 */
	public function test_start_refuses_an_ineligible_placement() {
		$this->sign_in();
		$a       = $this->create_prompt( 'manual' );
		$prompts = $this->prompt_count();

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_popups_ab_ineligible_placement', $response->get_data()['code'] );
		$this->assertSame( '', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 0, $this->term_count() );
		$this->assertSame( $prompts, $this->prompt_count() );
	}

	/**
	 * Requests with a bad goal or split are refused.
	 *
	 * @dataProvider data_bad_params
	 * @param array $params Params to send.
	 */
	public function test_start_refuses_bad_params( $params ) {
		$this->sign_in();
		$a = $this->create_prompt();

		$response = $this->start_request( array_merge( [ 'prompt_id' => $a ], $params ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * Bad params.
	 *
	 * @return array
	 */
	public function data_bad_params() {
		return [
			'unknown goal'   => [ [ 'goal' => 'pageview' ] ],
			'empty goal'     => [ [ 'goal' => '' ] ],
			'split too high' => [ [ 'control_share' => 90 ] ],
			'split too low'  => [ [ 'control_share' => 10 ] ],
			'empty name'     => [ [ 'name' => '   ' ] ],
		];
	}

	/**
	 * Someone who can't edit the prompt can't start a test on it.
	 */
	public function test_start_needs_permission_to_edit_the_prompt() {
		$owner = self::factory()->user->create( [ 'role' => 'editor' ] );
		$a     = $this->create_prompt( 'inline', [ 'post_author' => $owner ] );
		$this->sign_in( 'author' );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
	}

	/**
	 * A start that can't create version B leaves A out of any test and no name behind.
	 */
	public function test_start_rolls_back_when_version_b_fails() {
		$this->sign_in();
		$a       = $this->create_prompt();
		$prompts = $this->prompt_count();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( '', get_post_meta( $a, Newspack_Popups_AB_Tests::META_TEST_ID, true ) );
		$this->assertSame( 0, $this->term_count() );
		$this->assertSame( $prompts, $this->prompt_count() );
	}

	/**
	 * A title with regex delimiters copies without a warning.
	 */
	public function test_start_copies_a_title_with_a_slash() {
		$this->sign_in();
		$a = $this->create_prompt( 'inline', [ 'post_title' => 'Donate / subscribe (2026)' ] );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'Donate / subscribe (2026) (B)', get_post( $response->get_data()['version_b']['id'] )->post_title );
	}

	/**
	 * Version B keeps A's backslashes, including the escapes block attributes use.
	 */
	public function test_start_copies_content_with_backslashes() {
		$this->sign_in();
		$content = '<!-- wp:button {"text":""Subscribe""} --><p>Back\slash</p><!-- /wp:button -->';
		$a       = $this->create_prompt( 'inline', [ 'post_content' => wp_slash( $content ) ] );
		$this->assertSame( $content, get_post( $a )->post_content );

		$response = $this->start_request( [ 'prompt_id' => $a ] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $content, get_post( $response->get_data()['version_b']['id'] )->post_content );
	}

	/**
	 * When core hands back a term another create just made under the same ID,
	 * create_test() draws a new ID instead of sharing that test's.
	 */
	public function test_create_test_skips_a_term_core_hands_back() {
		$other     = wp_insert_term( 'Autumn drive', Newspack_Popups_AB_Tests::TAXONOMY, [ 'slug' => 'autumn-drive' ] );
		$hand_back = function ( $duplicate, $term, $taxonomy, $args ) use ( $other, &$hand_back ) {
			remove_filter( 'wp_insert_term_duplicate_term_check', $hand_back );
			// The other create drew this call's ID first.
			wp_update_term( $other['term_id'], Newspack_Popups_AB_Tests::TAXONOMY, [ 'slug' => $args['slug'] ] );
			return (object) [
				'term_id'          => $other['term_id'],
				'term_taxonomy_id' => $other['term_taxonomy_id'],
			];
		};
		add_filter( 'wp_insert_term_duplicate_term_check', $hand_back, 10, 4 );

		$test_id = Newspack_Popups_AB_Tests::create_test( 'Spring drive' );

		$this->assertIsString( $test_id );
		$this->assertNotSame( get_term( $other['term_id'] )->slug, $test_id );
		$this->assertSame( 'Spring drive', Newspack_Popups_AB_Tests::get_test_name( $test_id ) );
		$this->assertSame( 'Autumn drive', get_term( $other['term_id'] )->name );
	}
}
