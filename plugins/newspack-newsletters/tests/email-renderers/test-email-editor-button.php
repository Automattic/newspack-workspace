<?php
/**
 * Class Email Editor Button Test
 *
 * @package Newspack_Newsletters
 */

/**
 * Verifies the canonical button reaches the editor theme.json, flag-gated.
 */
class Test_Email_Editor_Button extends WP_UnitTestCase {
	/**
	 * Original $pagenow, restored in tear_down to avoid leaking into later tests.
	 *
	 * @var string|null
	 */
	private $original_pagenow;

	/**
	 * Simulate an email-editor request for a newsletter post.
	 *
	 * @param int $post_id Newsletter post id.
	 */
	private function fake_email_editor_request( $post_id ) {
		$this->original_pagenow = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['pagenow']     = 'post.php';
		$_GET['post']           = $post_id;
		$GLOBALS['post']        = get_post( $post_id );
	}

	/**
	 * Run the editor override filter and return the resulting theme.json array.
	 */
	private function run_override() {
		$data = new WP_Theme_JSON_Data( [ 'version' => 3 ], 'theme' );
		return Newspack_Newsletters_Editor::override_theme_json_for_email_editor( $data )->get_data();
	}

	/**
	 * Flag ON: the canonical button is present in the editor theme.json.
	 */
	public function test_button_present_when_flag_on() {
		$post_id = self::factory()->post->create( [ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$this->fake_email_editor_request( $post_id );
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );

		$theme  = $this->run_override();
		$button = $theme['styles']['elements']['button'];

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_true' );
		$this->assertSame( '5px', $button['border']['radius'] );
		// The background must be a concrete color, never the recursion-guard sentinel
		// `currentcolor` (the block-theme editor↔render parity bug this guards against).
		$this->assertNotSame( 'currentcolor', strtolower( (string) $button['color']['background'] ) );
		$this->assertNotSame( '', (string) $button['color']['background'] );
	}

	/**
	 * Flag OFF on a classic theme: no canonical button (legacy behavior).
	 */
	public function test_button_absent_when_flag_off_classic_theme() {
		// The flag-off branch only styles the button on block themes; this test pins
		// the classic-theme assumption so it fails loudly if the test theme changes.
		$this->assertFalse( wp_is_block_theme(), 'Test assumes a classic active theme.' );

		$post_id = self::factory()->post->create( [ 'post_type' => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ] );
		$this->fake_email_editor_request( $post_id );
		add_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );

		$theme = $this->run_override();

		remove_filter( 'newspack_newsletters_use_woo_renderer', '__return_false' );
		$this->assertArrayNotHasKey( 'button', $theme['styles']['elements'] ?? [] );
	}

	/**
	 * Clean up request globals so state never leaks into later tests.
	 */
	public function tear_down() {
		unset( $_GET['post'], $GLOBALS['post'] );
		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow;
		}
		parent::tear_down();
	}
}
