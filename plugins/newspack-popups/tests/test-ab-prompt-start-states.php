<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Tests for the prompt list the tab's picker and the Campaigns menu start tests from.
 *
 * @package Newspack_Popups
 */

/**
 * Prompt start states.
 *
 * @group ab-tests
 */
class ABPromptStartStatesTest extends WP_UnitTestCase_PageWithPopups {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		Newspack_Popups_AB_Tests::reset_config_cache();
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * A prompt on a placement.
	 *
	 * @param string $placement    Placement.
	 * @param array  $post_options Post fields.
	 * @param string $content      Content.
	 * @return int Prompt ID.
	 */
	private function create_prompt( $placement = 'inline', $post_options = [], $content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ) {
		$prompt_id = $this->createPopup( $content, null, $post_options );
		update_post_meta( $prompt_id, 'placement', $placement );
		return $prompt_id;
	}

	/**
	 * The states, keyed by prompt ID.
	 *
	 * @return array[]
	 */
	private function states() {
		return array_column( Newspack_Popups_AB_Tests::get_prompt_start_states(), null, 'id' );
	}

	public function test_lists_prompts_in_a_test_with_their_test_id() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$free   = $this->create_prompt();
		$tested = $this->create_prompt();
		update_post_meta( $tested, Newspack_Popups_AB_Tests::META_TEST_ID, 'spring-drive-1a2b' );
		update_post_meta( $tested, Newspack_Popups_AB_Tests::META_VARIANT, 'a' );

		$states = $this->states();
		$this->assertSame( '', $states[ $free ]['test_id'] );
		$this->assertSame( 'spring-drive-1a2b', $states[ $tested ]['test_id'] );
	}

	public function test_lists_drafts_and_leaves_out_trashed_prompts() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$draft   = $this->create_prompt( 'inline', [ 'post_status' => 'draft' ] );
		$trashed = $this->create_prompt();
		wp_trash_post( $trashed );

		$states = $this->states();
		$this->assertSame( 'draft', $states[ $draft ]['status'] );
		$this->assertArrayNotHasKey( $trashed, $states );
	}

	public function test_gives_the_reason_an_ineligible_placement_cannot_start_a_test() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$inline = $this->create_prompt( 'inline' );
		$manual = $this->create_prompt( 'manual' );
		$hidden = $this->create_prompt( 'center' );
		update_post_meta( $hidden, 'manual_only', true );

		$states = $this->states();
		$this->assertSame( '', $states[ $inline ]['ineligible_reason'] );
		$this->assertSame( 'Prompts on manual or custom placements can’t be A/B tested.', $states[ $manual ]['ineligible_reason'] );
		$this->assertSame( 'Overlays set to Manual only can’t be A/B tested.', $states[ $hidden ]['ineligible_reason'] );
	}

	public function test_start_refuses_with_the_listed_reason() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$manual = $this->create_prompt( 'manual' );

		$result = Newspack_Popups_AB_Tests::start_test( $manual, 'Spring drive', 'donation', 50 );
		$this->assertWPError( $result );
		$this->assertSame( $this->states()[ $manual ]['ineligible_reason'], $result->get_error_message() );
	}

	/**
	 * Content, and the goal it should suggest.
	 *
	 * @return array[]
	 */
	public function suggested_goals() {
		return [
			'checkout button wins over a form'  => [ '<!-- wp:newspack/reader-registration /--><!-- wp:newspack-blocks/checkout-button /-->', 'subscription' ],
			'donate block'                      => [ '<!-- wp:newspack-blocks/donate /-->', 'donation' ],
			'newsletter form nested in a group' => [ '<!-- wp:group --><div class="wp-block-group"><!-- wp:newspack-newsletters/subscribe /--></div><!-- /wp:group -->', 'form_submission' ],
			'button block'                      => [ '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button /--></div><!-- /wp:buttons -->', 'link_click' ],
			'link in a paragraph'               => [ '<!-- wp:paragraph --><p><a href="https://example.test">Read</a></p><!-- /wp:paragraph -->', 'link_click' ],
			'nothing to measure'                => [ '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', '' ],
		];
	}

	/**
	 * The suggested goal follows the blocks in the prompt, nested ones included.
	 *
	 * @dataProvider suggested_goals
	 *
	 * @param string $content  Prompt content.
	 * @param string $expected Goal key.
	 */
	public function test_suggests_a_goal_from_the_prompt_blocks( $content, $expected ) {
		$prompt_id = $this->create_prompt( 'inline', [], $content );
		$this->assertSame( $expected, $this->states()[ $prompt_id ]['suggested_goal'] );
	}

	public function test_list_route_carries_the_prompts() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$prompt_id = $this->create_prompt();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-popups/v1/ab-tests' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $prompt_id, array_column( $response->get_data()['prompts'], 'id' ) );
	}
}
