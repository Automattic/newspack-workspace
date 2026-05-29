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
}
