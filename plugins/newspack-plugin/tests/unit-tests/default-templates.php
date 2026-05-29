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
	 * Build a stub object that looks like a WP_Block_Template.
	 *
	 * @param string   $slug       Template slug.
	 * @param string   $title      Template title.
	 * @param string[] $post_types Post types declared for the template.
	 * @param string   $source     'theme' or 'custom'.
	 * @return object
	 */
	private function make_template( $slug, $title, $post_types, $source ) {
		return (object) [
			'slug'       => $slug,
			'title'      => $title,
			'post_types' => $post_types,
			'source'     => $source,
		];
	}

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
	 * A theme template whose post_types include the post type is offered.
	 */
	public function test_filter_includes_theme_template_matching_post_type() {
		$templates = [ $this->make_template( 'single/large-image', 'Large Image', [ 'post' ], 'theme' ) ];
		$options   = Default_Templates::filter_templates_for_post_type( $templates, 'post' );
		$this->assertSame(
			[ [ 'label' => 'Large Image', 'value' => 'single/large-image' ] ],
			$options
		);
	}

	/**
	 * A site-created (custom) template is offered regardless of post_types.
	 */
	public function test_filter_includes_custom_template() {
		$templates = [ $this->make_template( 'my-custom', 'My Custom', [], 'custom' ) ];
		$options   = Default_Templates::filter_templates_for_post_type( $templates, 'page' );
		$this->assertSame(
			[ [ 'label' => 'My Custom', 'value' => 'my-custom' ] ],
			$options
		);
	}

	/**
	 * Base hierarchy templates (theme source, no post_types) are not offered.
	 */
	public function test_filter_excludes_base_template() {
		$templates = [ $this->make_template( 'single', 'Single Posts', [], 'theme' ) ];
		$options   = Default_Templates::filter_templates_for_post_type( $templates, 'post' );
		$this->assertSame( [], $options );
	}

	/**
	 * A theme template declared for a different post type is not offered.
	 */
	public function test_filter_excludes_template_for_other_post_type() {
		$templates = [ $this->make_template( 'page/wide', 'Wide Page', [ 'page' ], 'theme' ) ];
		$options   = Default_Templates::filter_templates_for_post_type( $templates, 'post' );
		$this->assertSame( [], $options );
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
