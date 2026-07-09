<?php
/**
 * Class Editor Assets Test
 *
 * @package Newspack_Popups
 */

/**
 * Editor assets test case.
 */
class EditorAssetsTest extends WP_UnitTestCase {
	/**
	 * Asset fixture files created during a test.
	 *
	 * @var array
	 */
	private $created_asset_files = [];

	/**
	 * Asset fixture directories created during a test.
	 *
	 * @var array
	 */
	private $created_asset_dirs = [];

	/**
	 * Original Prompt metabox state.
	 *
	 * @var array|null
	 */
	private $original_prompt_meta_boxes = null;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_prompt_meta_boxes = $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ] ?? null;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		wp_dequeue_script( 'newspack-popups' );
		wp_deregister_script( 'newspack-popups' );
		wp_dequeue_script( 'newspack-popups-blocks' );
		wp_deregister_script( 'newspack-popups-blocks' );
		wp_dequeue_style( 'newspack-popups-editor' );
		wp_deregister_style( 'newspack-popups-editor' );
		wp_dequeue_style( 'newspack-popups-blocks' );
		wp_deregister_style( 'newspack-popups-blocks' );
		foreach ( array_reverse( $this->created_asset_files ) as $file ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Removes only per-test fixture assets created by this test.
				unlink( $file );
			}
		}
		foreach ( array_reverse( $this->created_asset_dirs ) as $dir ) {
			if ( is_dir( $dir ) && 2 === count( scandir( $dir ) ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Removes only the empty per-test fixture directory created by this test.
				rmdir( $dir );
			}
		}
		$this->created_asset_files = [];
		$this->created_asset_dirs  = [];
		wp_reset_postdata();
		unset( $GLOBALS['post'] );
		if ( null === $this->original_prompt_meta_boxes ) {
			unset( $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ] );
		} else {
			$GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ] = $this->original_prompt_meta_boxes;
		}
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Ensure required build asset files exist.
	 */
	private function ensure_dist_asset_files() {
		$dist_dir = dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist';
		if ( ! is_dir( $dist_dir ) ) {
			wp_mkdir_p( $dist_dir );
			$this->created_asset_dirs[] = $dist_dir;
		}

		$asset_fixtures = [
			'blocks.asset.php'           => "<?php return [ 'dependencies' => [ 'wp-blocks' ], 'version' => 'blocks-test' ];\n",
			'blocks.js'                  => '',
			'blocks.css'                 => '',
			'editor.asset.php'           => "<?php return [ 'dependencies' => [ 'wp-components', 'wp-plugins' ], 'version' => 'editor-test' ];\n",
			'editor.js'                  => '',
			'editor.css'                 => '',
			'documentSettings.asset.php' => "<?php return [ 'dependencies' => [ 'wp-components', 'wp-plugins' ], 'version' => 'document-settings-test' ];\n",
			'documentSettings.js'        => '',
		];

		foreach ( $asset_fixtures as $filename => $contents ) {
			$path = trailingslashit( $dist_dir ) . $filename;
			if ( file_exists( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Per-test fixture asset under the generated dist directory.
			file_put_contents( $path, $contents );
			$this->created_asset_files[] = $path;
		}
	}

	/**
	 * Get generated asset metadata.
	 *
	 * @param string $filename Asset metadata filename.
	 *
	 * @return array Asset metadata.
	 */
	private function get_asset_metadata( $filename ) {
		return require trailingslashit( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist' ) . $filename;
	}

	/**
	 * Set the current screen to the prompt editor.
	 *
	 * @param string $post_type Post type.
	 */
	private function set_editor_screen( $post_type = Newspack_Popups::NEWSPACK_POPUPS_CPT ) {
		$this->ensure_dist_asset_files();

		$post_id = self::factory()->post->create(
			[
				'post_type'    => $post_type,
				'post_title'   => 'Prompt editor assets',
				'post_content' => 'Prompt content.',
			]
		);

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );

		set_current_screen( 'post' );
		get_current_screen()->post_type = $post_type;
	}

	/**
	 * Test editor UI assets are not enqueued with block assets.
	 */
	public function test_prompt_editor_ui_assets_are_not_enqueued_as_block_assets() {
		$this->set_editor_screen();

		self::assertSame( 10, has_action( 'enqueue_block_assets', [ Newspack_Popups::class, 'enqueue_block_assets' ] ) );

		do_action( 'enqueue_block_assets' );

		self::assertTrue( wp_script_is( 'newspack-popups-blocks', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'newspack-popups-blocks', 'enqueued' ) );
		self::assertSame( $this->get_asset_metadata( 'blocks.asset.php' )['version'], wp_scripts()->registered['newspack-popups-blocks']->ver );
		self::assertSame( $this->get_asset_metadata( 'blocks.asset.php' )['version'], wp_styles()->registered['newspack-popups-blocks']->ver );
		self::assertFalse( wp_script_is( 'newspack-popups', 'enqueued' ) );
	}

	/**
	 * Test editor UI assets are enqueued with editor assets.
	 */
	public function test_prompt_editor_ui_assets_are_enqueued_as_editor_assets() {
		$this->set_editor_screen();

		self::assertSame( 10, has_action( 'enqueue_block_editor_assets', [ Newspack_Popups::class, 'enqueue_block_editor_assets' ] ) );

		do_action( 'enqueue_block_editor_assets' );

		self::assertTrue( wp_script_is( 'newspack-popups', 'enqueued' ) );
		self::assertTrue( wp_style_is( 'newspack-popups-editor', 'enqueued' ) );
		self::assertContains( 'wp-plugins', wp_scripts()->registered['newspack-popups']->deps );
		self::assertSame( $this->get_asset_metadata( 'editor.asset.php' )['version'], wp_scripts()->registered['newspack-popups']->ver );
		self::assertSame( $this->get_asset_metadata( 'editor.asset.php' )['version'], wp_styles()->registered['newspack-popups-editor']->ver );
	}

	/**
	 * Test supported post type document settings are enqueued with editor assets.
	 */
	public function test_supported_post_type_document_settings_are_enqueued_as_editor_assets() {
		$this->set_editor_screen( 'post' );

		do_action( 'enqueue_block_editor_assets' );

		self::assertTrue( wp_script_is( 'newspack-popups', 'enqueued' ) );
		self::assertStringContainsString( 'documentSettings.js', wp_scripts()->registered['newspack-popups']->src );
		self::assertContains( 'wp-plugins', wp_scripts()->registered['newspack-popups']->deps );
		self::assertSame( $this->get_asset_metadata( 'documentSettings.asset.php' )['version'], wp_scripts()->registered['newspack-popups']->ver );
		self::assertFalse( wp_style_is( 'newspack-popups-editor', 'enqueued' ) );
	}

	/**
	 * Test prompt meta is not editable through the stale core Custom Fields metabox.
	 */
	public function test_prompt_editor_removes_core_custom_fields_meta_box() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Newspack_Popups::NEWSPACK_POPUPS_CPT,
			]
		);
		$post    = get_post( $post_id );

		self::assertTrue( post_type_supports( Newspack_Popups::NEWSPACK_POPUPS_CPT, 'custom-fields' ) );

		add_meta_box(
			'postcustom',
			'Custom Fields',
			'__return_empty_string',
			Newspack_Popups::NEWSPACK_POPUPS_CPT,
			'normal',
			'core'
		);

		self::assertSame( 99, has_action( 'add_meta_boxes_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, [ Newspack_Popups::class, 'remove_custom_fields_meta_box' ] ) );
		self::assertIsArray( $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ]['normal']['core']['postcustom'] );

		do_action( 'add_meta_boxes_' . Newspack_Popups::NEWSPACK_POPUPS_CPT, $post );

		self::assertFalse( $GLOBALS['wp_meta_boxes'][ Newspack_Popups::NEWSPACK_POPUPS_CPT ]['normal']['core']['postcustom'] );
	}
}
