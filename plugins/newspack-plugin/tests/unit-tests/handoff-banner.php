<?php
/**
 * Tests Handoff_Banner screen gating.
 *
 * Coverage:
 *   - The admin-header banner renders on regular admin screens during a handoff.
 *   - On block editor screens where the handoff requested the in-editor notice
 *     (show_on_block_editor), the admin-header banner is suppressed so the two
 *     don't render together.
 *   - Without the in-editor notice, block editor screens keep the banner (the
 *     site editor relies on it, with scoped offset CSS).
 *
 * @package Newspack\Tests
 */

use Newspack\Handoff_Banner;

/**
 * Handoff banner rendering tests.
 */
class Newspack_Test_Handoff_Banner extends WP_UnitTestCase {

	/**
	 * Set up a pending handoff.
	 */
	public function set_up() {
		parent::set_up();
		update_option( NEWSPACK_HANDOFF, 'url' );
	}

	/**
	 * Clear handoff state and admin screen context.
	 */
	public function tear_down() {
		delete_option( NEWSPACK_HANDOFF );
		delete_option( NEWSPACK_HANDOFF_SHOW_ON_BLOCK_EDITOR );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Render the banner container for the current screen.
	 *
	 * @return string The printed markup.
	 */
	private function get_banner_output() {
		ob_start();
		( new Handoff_Banner() )->insert_handoff_banner();
		return ob_get_clean();
	}

	/**
	 * Regular admin screens get the banner container.
	 */
	public function test_banner_renders_on_regular_admin_screen() {
		set_current_screen( 'options-general' );
		$this->assertStringContainsString( 'newspack-handoff-banner', $this->get_banner_output() );
	}

	/**
	 * Block editor screens showing the in-editor notice must not also get the
	 * admin-header banner.
	 */
	public function test_banner_suppressed_on_block_editor_with_editor_notice() {
		update_option( NEWSPACK_HANDOFF_SHOW_ON_BLOCK_EDITOR, true );
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( true );
		$this->assertSame( '', $this->get_banner_output() );
	}

	/**
	 * Without the in-editor notice the banner is the only return UI, so block
	 * editor screens keep it.
	 */
	public function test_banner_kept_on_block_editor_without_editor_notice() {
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( true );
		$this->assertStringContainsString( 'newspack-handoff-banner', $this->get_banner_output() );
	}
}
