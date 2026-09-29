<?php
/**
 * Class RepublishPatternTest
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Test Republish block pattern registration.
 */
class RepublishPatternTest extends WP_UnitTestCase {

	/**
	 * The republish-section pattern is registered with the expected name and category.
	 */
	public function test_pattern_is_registered() {
		$registry = WP_Block_Patterns_Registry::get_instance();

		$this->assertTrue(
			$registry->is_registered( 'republication-tracker-tool/republish-section' ),
			'Republish section pattern should be registered.'
		);

		$pattern = $registry->get_registered( 'republication-tracker-tool/republish-section' );

		$this->assertContains( 'republication-tracker-tool', $pattern['categories'] );
	}

	/**
	 * The pattern category is registered with a translatable label.
	 */
	public function test_pattern_category_is_registered() {
		$registry   = WP_Block_Pattern_Categories_Registry::get_instance();
		$categories = $registry->get_all_registered();

		$found = false;
		foreach ( $categories as $category ) {
			if ( 'republication-tracker-tool' === $category['name'] ) {
				$found = true;
				$this->assertNotEmpty( $category['label'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Republication pattern category should be registered.' );
	}

	/**
	 * The pattern content references the expected child blocks.
	 */
	public function test_pattern_content_includes_expected_blocks() {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( 'republication-tracker-tool/republish-section' );

		$content = $pattern['content'];

		$this->assertStringContainsString( '<!-- wp:group', $content );
		$this->assertStringContainsString( '<!-- wp:paragraph', $content );
		$this->assertStringContainsString( '<!-- wp:republication-tracker-tool/republish-button', $content );
		$this->assertStringNotContainsString( 'republish-license', $content );
	}
}
