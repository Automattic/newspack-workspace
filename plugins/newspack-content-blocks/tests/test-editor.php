<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class EditorTest
 *
 * @package Newspack_Content_Blocks
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions -- the test places a stand-in bundle in dist/.

/**
 * Newspack_Content_Blocks\Editor test case.
 *
 * @group editor
 */
class EditorTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * Path of the editor bundle the class looks for.
	 *
	 * @var string
	 */
	private $editor_js;

	/**
	 * Whether this test created a stand-in for the built editor bundle.
	 *
	 * @var bool
	 */
	private $created_stub = false;

	/**
	 * Make sure a bundle exists to enqueue, and reset script state.
	 */
	public function set_up() {
		parent::set_up();
		$this->editor_js = NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . 'dist/editor.js';
		if ( ! file_exists( $this->editor_js ) ) {
			if ( ! is_dir( dirname( $this->editor_js ) ) ) {
				mkdir( dirname( $this->editor_js ) );
			}
			file_put_contents( $this->editor_js, '' );
			$this->created_stub = true;
		}
		wp_dequeue_script( 'newspack-content-blocks-editor' );
		wp_deregister_script( 'newspack-content-blocks-editor' );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Remove the stand-in bundle, if any.
	 */
	public function tear_down() {
		if ( $this->created_stub ) {
			unlink( $this->editor_js );
		}
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The editor script is enqueued in the admin, with the data the moved JS reads.
	 */
	public function test_enqueues_editor_script_and_localizes_data() {
		do_action( 'enqueue_block_assets' );

		$this->assertTrue( wp_script_is( 'newspack-content-blocks-editor', 'enqueued' ) );

		// Normalize JSON slash-escaping, which depends on the permalink structure.
		$data = str_replace( '\/', '/', wp_scripts()->get_data( 'newspack-content-blocks-editor', 'data' ) );
		$this->assertStringContainsString( 'var newspack_content_blocks_data', $data );
		foreach ( [ 'posts_rest_url', 'posts_batch_rest_url', 'posts_batch_max_queries', 'specific_posts_rest_url', 'authors_rest_url', 'assets_path', 'post_subtitle', 'custom_taxonomies' ] as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data, $key );
		}
		$this->assertStringNotContainsString( '"patterns"', $data );
		$this->assertStringContainsString( 'newspack-content-blocks/v1/authors', $data );
		// wp_localize_script() casts scalar values to strings.
		$this->assertStringContainsString( '"posts_batch_max_queries":"' . Newspack_Content_Blocks\API::POSTS_BATCH_MAX_QUERIES . '"', $data );
		$this->assertStringContainsString( 'newspack-content-blocks/src/assets', $data );
	}

	/**
	 * Nothing is enqueued outside the admin.
	 */
	public function test_does_nothing_on_frontend() {
		set_current_screen( 'front' );
		do_action( 'enqueue_block_assets' );
		$this->assertFalse( wp_script_is( 'newspack-content-blocks-editor', 'enqueued' ) );
	}

	/**
	 * The class is hooked up by the plugin loader.
	 */
	public function test_hooked_to_enqueue_block_assets() {
		$this->assertNotFalse( has_action( 'enqueue_block_assets', [ Newspack_Content_Blocks\Editor::class, 'enqueue_block_editor_assets' ] ) );
	}
}
