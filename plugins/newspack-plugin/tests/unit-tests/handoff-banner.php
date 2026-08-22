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
	 * Clear handoff state, request context, and admin screen context.
	 */
	public function tear_down() {
		delete_option( NEWSPACK_HANDOFF );
		delete_option( NEWSPACK_HANDOFF_SHOW_ON_BLOCK_EDITOR );
		delete_option( NEWSPACK_HANDOFF_DESTINATION_URL );
		delete_option( NEWSPACK_HANDOFF_RETURN_URL );
		unset( $_SERVER['SCRIPT_NAME'], $_GET['post'], $_GET['action'], $_GET['page'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Put the current request on a given admin URL, the way the clearing logic
	 * reads it: script name plus query parameters.
	 *
	 * @param string $url Admin URL.
	 */
	private function set_current_request( $url ) {
		$parsed                  = wp_parse_url( $url );
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/' . basename( $parsed['path'] );
		unset( $_GET['post'], $_GET['action'], $_GET['page'] );
		if ( ! empty( $parsed['query'] ) ) {
			wp_parse_str( $parsed['query'], $query );
			foreach ( $query as $key => $value ) {
				$_GET[ $key ] = $value;
			}
		}
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

	/**
	 * A handoff into the block editor lives on that one screen: it survives
	 * there and ends anywhere else, instead of following the user around.
	 */
	public function test_editor_handoff_ends_when_leaving_the_editor() {
		update_option( NEWSPACK_HANDOFF_DESTINATION_URL, admin_url( 'post.php?post=232&action=edit' ) );
		update_option( NEWSPACK_HANDOFF_RETURN_URL, admin_url( 'admin.php?page=newspack-audience-campaigns' ) );
		$this->set_current_request( admin_url( 'post.php?post=232&action=edit' ) );
		set_current_screen( 'post' );
		$banner = new Handoff_Banner();
		$banner->clear_handoff_url( get_current_screen() );
		$this->assertNotEmpty( get_option( NEWSPACK_HANDOFF ), 'The handoff survives on its destination.' );

		$this->set_current_request( admin_url( 'post.php?post=14&action=edit' ) );
		$banner->clear_handoff_url( get_current_screen() );
		$this->assertEmpty( get_option( NEWSPACK_HANDOFF ), 'Leaving the destination editor ends the handoff.' );
	}

	/**
	 * A handoff to a plugin page stays sticky across other screens — setup
	 * flows navigate through several pages before returning.
	 */
	public function test_plugin_page_handoff_stays_sticky() {
		update_option( NEWSPACK_HANDOFF_DESTINATION_URL, admin_url( 'admin.php?page=mailchimp' ) );
		update_option( NEWSPACK_HANDOFF_RETURN_URL, admin_url( 'admin.php?page=newspack-audience' ) );
		$this->set_current_request( admin_url( 'options-general.php' ) );
		set_current_screen( 'options-general' );
		$banner = new Handoff_Banner();
		$banner->clear_handoff_url( get_current_screen() );
		$this->assertNotEmpty( get_option( NEWSPACK_HANDOFF ), 'A non-editor handoff keeps the banner across screens.' );

		$this->set_current_request( admin_url( 'admin.php?page=newspack-audience' ) );
		$banner->clear_handoff_url( get_current_screen() );
		$this->assertEmpty( get_option( NEWSPACK_HANDOFF ), 'Reaching the return URL ends the handoff.' );
	}
}
