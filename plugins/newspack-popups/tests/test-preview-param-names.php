<?php
/**
 * Class Preview Param Names Test
 *
 * @package Newspack_Popups
 */

/**
 * Tests the gate on the preview param list handed to the previewed document.
 *
 * The list is what lets a prompt preview survive a click, so it must reach a
 * genuine prompt preview and nothing else: `pid` is a common campaign parameter,
 * and anyone can put one in a URL.
 */
class PreviewParamNamesTest extends WP_UnitTestCase {
	/**
	 * Log in as a user holding the prompt-management capability.
	 *
	 * Deliberately an editor, not an administrator, matching test-presets.php: the
	 * gate is `edit_others_pages` via a filterable capability, and an administrator
	 * would satisfy any capability this were later tightened to, hiding the
	 * regression from this suite.
	 */
	private function login_as_prompt_manager() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * A user who may manage prompts, previewing a prompt, gets the list.
	 */
	public function test_param_names_for_a_prompt_manager_previewing_a_prompt() {
		$this->login_as_prompt_manager();
		$prompt_id = self::factory()->post->create( [ 'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT ] );
		$_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] = $prompt_id;

		$names = Newspack_Popups_Inserter::preview_param_names();

		$this->assertContains(
			Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM,
			$names,
			'The list carries the preview param itself.'
		);
		foreach ( Newspack_Popups::PREVIEW_QUERY_KEYS as $param ) {
			$this->assertContains( $param, $names, 'The list carries every abbreviated meta param.' );
		}
	}

	/**
	 * A `pid` that is not a prompt is somebody else's parameter.
	 */
	public function test_no_param_names_when_pid_is_not_a_prompt() {
		$this->login_as_prompt_manager();
		$_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] = self::factory()->post->create();

		$this->assertSame(
			[],
			Newspack_Popups_Inserter::preview_param_names(),
			'An editor following a link that carries an unrelated `pid` gets ordinary links.'
		);
	}

	/**
	 * Readers never get preview params, however well-formed the URL.
	 */
	public function test_no_param_names_for_a_reader() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] = self::factory()->post->create(
			[ 'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT ]
		);

		$this->assertSame(
			[],
			Newspack_Popups_Inserter::preview_param_names(),
			'A reader arriving on a prompt preview URL gets ordinary links.'
		);
	}

	/**
	 * No `pid`, nothing to propagate — the ordinary front-end request.
	 */
	public function test_no_param_names_without_the_param() {
		$this->login_as_prompt_manager();

		$this->assertSame(
			[],
			Newspack_Popups_Inserter::preview_param_names(),
			'An admin browsing the site normally gets ordinary links.'
		);
	}

	/**
	 * An author holds edit_posts but not edit_others_pages, which pins the boundary
	 * from below — the subscriber case only pins it from far outside.
	 */
	public function test_no_param_names_for_an_author() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] = self::factory()->post->create(
			[ 'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT ]
		);

		$this->assertSame( [], Newspack_Popups_Inserter::preview_param_names() );
	}

	/**
	 * Pins the PHP-to-JS contract: the param names are useless unless they arrive
	 * under the key src/view/preview-links.js reads.
	 */
	public function test_param_names_are_localized_under_the_key_the_view_script_reads() {
		$this->login_as_prompt_manager();
		$_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] = self::factory()->post->create(
			[ 'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT ]
		);

		$data = Newspack_Popups_Inserter::get_view_script_preview_data();

		$this->assertArrayHasKey(
			'preview_param_names',
			$data,
			'The front end reads newspack_popups_view.preview_param_names; renaming either side alone breaks the preview silently.'
		);
		$this->assertContains( Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM, $data['preview_param_names'] );

		wp_set_current_user( 0 );
		$this->assertSame(
			[],
			Newspack_Popups_Inserter::get_view_script_preview_data(),
			'Nothing is merged into the localized data outside a preview.'
		);
	}
}
