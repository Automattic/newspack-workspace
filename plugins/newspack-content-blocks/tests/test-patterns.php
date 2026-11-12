<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class PatternsTest
 *
 * @package Newspack_Content_Blocks
 */

/**
 * Tests for the Content Loop block patterns.
 */
class PatternsTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * Remove any Content Loop patterns registered by earlier tests.
	 */
	private function unregister_patterns() {
		$registry = WP_Block_Patterns_Registry::get_instance();
		for ( $i = 1; $i <= 31; $i++ ) {
			if ( $registry->is_registered( 'newspack/homepage-posts-' . $i ) ) {
				$registry->unregister( 'newspack/homepage-posts-' . $i );
			}
		}
	}

	/**
	 * Registration is hooked to admin_init only, as in newspack-blocks: nothing is
	 * registered on frontend, REST or cron requests.
	 */
	public function test_patterns_hooked_to_admin_init_not_init() {
		$callback = [ Newspack_Content_Blocks\Patterns::class, 'register_block_patterns_and_categories' ];
		$this->assertNotFalse( has_action( 'admin_init', $callback ) );
		$this->assertFalse( has_action( 'init', $callback ) );

		$this->unregister_patterns();
		$registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertFalse( $registry->is_registered( 'newspack/homepage-posts-1' ), 'Not registered by the time init has run.' );
		Newspack_Content_Blocks\Patterns::register_block_patterns_and_categories();
		$this->assertTrue( $registry->is_registered( 'newspack/homepage-posts-1' ) );
	}

	/**
	 * The 31 Content Loop patterns and their category are registered on admin_init.
	 */
	public function test_homepage_posts_patterns_registered() {
		$this->unregister_patterns();
		Newspack_Content_Blocks\Patterns::register_block_patterns_and_categories();
		$registry = WP_Block_Patterns_Registry::get_instance();
		for ( $i = 1; $i <= 31; $i++ ) {
			$name = 'newspack/homepage-posts-' . $i;
			$this->assertTrue( $registry->is_registered( $name ), "$name should be registered." );
			$pattern = $registry->get_registered( $name );
			$this->assertContains( 'newspack-homepage-posts', $pattern['categories'], "$name should be in the Content Loop category." );
		}
		$this->assertFalse( $registry->is_registered( 'newspack/homepage-posts-32' ) );
		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'newspack-homepage-posts' ) );
	}

	/**
	 * Donation and subscribe patterns stay with newspack-blocks.
	 */
	public function test_only_homepage_posts_patterns_registered() {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertFalse( $registry->is_registered( 'newspack/donations-1' ) );
		$this->assertFalse( $registry->is_registered( 'newspack/subscribe-1' ) );
	}

	/**
	 * Patterns use this plugin's text domain, not newspack-blocks.
	 */
	public function test_patterns_use_own_text_domain() {
		foreach ( glob( NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . 'src/block-patterns/homepage-posts-*.php' ) as $file ) {
			$this->assertStringNotContainsString( "'newspack-blocks')", file_get_contents( $file ), basename( $file ) ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		}
	}
}
