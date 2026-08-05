<?php
/**
 * Tests for the Author Profile Social block's theme.json defaults.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Blocks\Author_Profile_Social\Author_Profile_Social_Block
 */

use Newspack\Blocks\Author_Profile_Social\Author_Profile_Social_Block;

require_once __DIR__ . '/../mocks/theme-json-data-gutenberg.php';

/**
 * Test class for the block's wp_theme_json_data_blocks callback.
 *
 * @group author-profile-social
 */
class Newspack_Test_Author_Profile_Social_Block extends WP_UnitTestCase {

	/**
	 * Setup. The block file is only required by Blocks::init() under a block theme,
	 * so load it directly.
	 *
	 * The block must also be registered: WP_Theme_JSON drops styles for unregistered
	 * blocks, and in production wp_theme_json_data_blocks resolves after init, by which
	 * point the block exists. Registering here mirrors that.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once NEWSPACK_ABSPATH . 'src/blocks/author-profile-social/class-author-profile-social-block.php';

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/author-profile-social' ) ) {
			Author_Profile_Social_Block::register_block();
		}
		wp_clean_theme_json_cache();
	}

	/**
	 * Tear down: drop the block registration and the theme.json cache.
	 */
	public function tear_down(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'newspack/author-profile-social' ) ) {
			unregister_block_type( 'newspack/author-profile-social' );
		}
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Pull the block's blockGap out of theme.json data.
	 *
	 * @param object $data Theme.json data object.
	 * @return mixed Block gap value, or null if absent.
	 */
	private function get_block_gap( $data ) {
		$raw = $data->get_data();
		return $raw['styles']['blocks']['newspack/author-profile-social']['spacing']['blockGap'] ?? null;
	}

	/**
	 * Baseline: the callback injects a blockGap through core's data class.
	 */
	public function test_injects_block_gap_with_core_data_class(): void {
		$data = new \WP_Theme_JSON_Data( [ 'version' => 3 ], 'blocks' );

		$result = Author_Profile_Social_Block::set_default_block_gap( $data );

		$this->assertNotNull(
			$this->get_block_gap( $result ),
			'set_default_block_gap() must inject a blockGap through the core data class.'
		);
	}

	/**
	 * With Gutenberg active, wp_theme_json_data_blocks carries WP_Theme_JSON_Data_Gutenberg,
	 * which does NOT extend WP_Theme_JSON_Data. A core-only type declaration fatals on every
	 * request — the same failure mode that took down newspack-newsletters 3.38.0 on the frontend.
	 * This block only loads under a block theme, which is the only reason it has not fataled yet.
	 */
	public function test_accepts_gutenberg_data_object(): void {
		$data = new \WP_Theme_JSON_Data_Gutenberg( [ 'version' => 3 ], 'blocks' );

		$result = Author_Profile_Social_Block::set_default_block_gap( $data );

		$this->assertNotNull(
			$this->get_block_gap( $result ),
			'set_default_block_gap() must inject through a Gutenberg data object instead of throwing a TypeError.'
		);
	}
}
