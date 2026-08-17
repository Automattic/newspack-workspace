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
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_GET[ Newspack_Popups::NEWSPACK_POPUP_PREVIEW_QUERY_PARAM ] );
		parent::tear_down();
	}

	/**
	 * An admin previewing a prompt gets the list.
	 */
	public function test_param_names_for_an_admin_previewing_a_prompt() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
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
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
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
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame(
			[],
			Newspack_Popups_Inserter::preview_param_names(),
			'An admin browsing the site normally gets ordinary links.'
		);
	}
}
