<?php
/**
 * Tests the Default_Templates class.
 *
 * @package Newspack\Tests
 */

use Newspack\Default_Templates;

/**
 * Test default template selection for new posts and pages.
 */
class Newspack_Test_Default_Templates extends WP_UnitTestCase {

	/**
	 * Classic (non-block) themes get the fixed legacy list for both post types.
	 */
	public function test_classic_options_returned_when_not_block_theme() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'Active theme is a block theme.' );
		}
		$options = Default_Templates::get_template_options();
		$this->assertArrayHasKey( 'post', $options );
		$this->assertArrayHasKey( 'page', $options );
		$values = wp_list_pluck( $options['post'], 'value' );
		$this->assertSame( [ 'default', 'single-feature.php', 'single-wide.php' ], $values );
		$this->assertSame( $options['post'], $options['page'] );
	}

	/**
	 * Block template options always begin with the "Default" entry.
	 */
	public function test_block_template_options_include_default_first() {
		$options = Default_Templates::get_block_template_options( 'post' );
		$this->assertNotEmpty( $options );
		$this->assertSame( 'default', $options[0]['value'] );
	}

	/**
	 * Edited hierarchy templates must NOT be offered as assignable options, while
	 * genuine custom templates must be.
	 *
	 * Regression test: editing a hierarchy template (e.g. "single" or
	 * "front-page") in the Site Editor creates a wp_template DB post with source
	 * "custom" but is_custom === false. These must not appear in the dropdown.
	 * get_block_templates() processes wp_template DB posts regardless of the
	 * active theme, so this is exercised even under the classic test theme.
	 */
	public function test_block_template_options_exclude_edited_hierarchy_templates() {
		$theme = get_stylesheet();

		// An edited hierarchy template: slug matches a default template type.
		$single_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'single',
				'post_title'   => 'Single Posts',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-content /-->',
			]
		);
		wp_set_object_terms( $single_id, $theme, 'wp_theme' );

		// A genuine custom template (slug is not a default template type).
		$custom_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'newspack-test-custom',
				'post_title'   => 'Newspack Test Custom',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-content /-->',
			]
		);
		wp_set_object_terms( $custom_id, $theme, 'wp_theme' );

		$values = wp_list_pluck( Default_Templates::get_block_template_options( 'post' ), 'value' );

		$this->assertNotContains( 'single', $values, 'Edited hierarchy templates must not be assignable.' );
		$this->assertContains( 'newspack-test-custom', $values, 'Custom templates must be assignable.' );
	}

	/**
	 * "default" / empty / invalid values resolve to no validation match.
	 */
	public function test_validate_template_rejects_unknown_slug() {
		$this->assertFalse( Default_Templates::validate_template( 'no-such-template', 'post' ) );
	}

	/**
	 * The "default" sentinel is always a valid option value.
	 */
	public function test_validate_template_accepts_default() {
		$this->assertTrue( Default_Templates::validate_template( 'default', 'post' ) );
	}

	/**
	 * The "default" sentinel survives the write sanitizer for both post types.
	 */
	public function test_sanitize_stored_template_keeps_default() {
		$this->assertSame( 'default', Default_Templates::sanitize_stored_template( 'default', 'post' ) );
		$this->assertSame( 'default', Default_Templates::sanitize_stored_template( 'default', 'page' ) );
	}

	/**
	 * An unknown slug is coerced to "default" on write.
	 */
	public function test_sanitize_stored_template_coerces_unknown() {
		$this->assertSame( 'default', Default_Templates::sanitize_stored_template( 'no-such-template', 'post' ) );
	}

	/**
	 * On a classic theme a valid legacy slug is kept, not coerced — the write
	 * sanitizer validates against the active theme's option list, not block
	 * templates alone.
	 */
	public function test_sanitize_stored_template_keeps_classic_slug() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'Active theme is a block theme.' );
		}
		$this->assertSame( 'single-wide.php', Default_Templates::sanitize_stored_template( 'single-wide.php', 'post' ) );
	}

	/**
	 * The pre_set_theme_mod filter coerces an invalid value when it is written.
	 */
	public function test_invalid_value_coerced_when_set_as_theme_mod() {
		Default_Templates::init();
		set_theme_mod( 'post_template_default', 'no-such-template' );
		$this->assertSame( 'default', get_theme_mod( 'post_template_default' ) );
		remove_theme_mod( 'post_template_default' );
	}

	/**
	 * Updating an existing post never sets the template meta.
	 */
	public function test_no_template_set_on_update() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		delete_post_meta( $post_id, '_wp_page_template' );
		set_theme_mod( 'post_template_default', 'single/large-image' );
		$post = get_post( $post_id );
		Default_Templates::maybe_set_default_template( $post_id, $post, true );
		$this->assertSame( '', get_post_meta( $post_id, '_wp_page_template', true ) );
		remove_theme_mod( 'post_template_default' );
	}

	/**
	 * On a non-block (classic) theme the plugin does not set the meta — the
	 * theme's own handler owns that path.
	 */
	public function test_no_template_set_on_classic_theme() {
		if ( wp_is_block_theme() ) {
			$this->markTestSkipped( 'Active theme is a block theme.' );
		}
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		delete_post_meta( $post_id, '_wp_page_template' );
		set_theme_mod( 'post_template_default', 'single-feature.php' );
		$post = get_post( $post_id );
		Default_Templates::maybe_set_default_template( $post_id, $post, false );
		$this->assertSame( '', get_post_meta( $post_id, '_wp_page_template', true ) );
		remove_theme_mod( 'post_template_default' );
	}

	/**
	 * The default-templates route is registered with the post/page response shape.
	 *
	 * The WP_UnitTestCase_Base test harness saves/restores $wp_filter around
	 * each test. If a prior test triggered autoloading of Default_Templates
	 * (running init() and registering the rest_api_init hook), tear_down() will
	 * have removed that hook before this test runs. We call init() here to
	 * re-register the hook, then fire rest_api_init on a fresh server.
	 */
	public function test_rest_endpoint_returns_post_and_page_options() {
		Default_Templates::init();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$request  = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-settings/default-templates' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'post', $data );
		$this->assertArrayHasKey( 'page', $data );
		wp_set_current_user( 0 );
	}
}
