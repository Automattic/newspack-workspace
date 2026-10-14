<?php
/**
 * Tests Blocks::version_block_styles().
 *
 * @package Newspack\Tests
 */

use Newspack\Blocks;
use Newspack\Newspack;

/**
 * Block.json stylesheets from the build get the build's content hash as their
 * block's `version`, so WordPress stops versioning them by the WordPress version.
 */
class Newspack_Test_Block_Style_Version extends WP_UnitTestCase {

	/**
	 * Per-test temp dir standing in for dist/.
	 *
	 * @var string
	 */
	private $fixture_dir;

	/**
	 * Build a fake dist/ with two entries and a block directory beside them.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture_dir = get_temp_dir() . 'newspack-block-style-version-' . wp_rand() . '/';
		wp_mkdir_p( $this->fixture_dir . 'block' );
		foreach ( [ 'entry-a', 'entry-b' ] as $entry ) {
			file_put_contents( $this->fixture_dir . $entry . '.css', '.x{}' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			file_put_contents( $this->fixture_dir . $entry . '.asset.php', '<?php return [ "version" => "hash-' . $entry . '" ];' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}
		add_filter( 'newspack_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
		Newspack::asset_version_reset_cache();
	}

	/**
	 * Remove the fixtures, the filter, and anything registered from them.
	 */
	public function tear_down() {
		remove_filter( 'newspack_asset_dist_dir', [ $this, 'filter_dist_dir' ] );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'newspack-test/style-version' ) ) {
			unregister_block_type( 'newspack-test/style-version' );
		}
		foreach ( glob( $this->fixture_dir . '{,block/}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
		rmdir( $this->fixture_dir . 'block' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		rmdir( $this->fixture_dir ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
		Newspack::asset_version_reset_cache();
		parent::tear_down();
	}

	/**
	 * Filter callback pointing the dist dir at the fixtures.
	 *
	 * @return string
	 */
	public function filter_dist_dir() {
		return $this->fixture_dir;
	}

	/**
	 * Metadata for a block whose block.json sits in the fixture block dir.
	 *
	 * @param array $fields Extra block.json fields.
	 * @return array
	 */
	private function metadata( $fields ) {
		return array_merge(
			[
				'name' => 'newspack-test/style-version',
				'file' => wp_normalize_path( $this->fixture_dir . 'block/block.json' ),
			],
			$fields
		);
	}

	/**
	 * A block whose stylesheets all come from one entry gets that entry's hash.
	 */
	public function test_sets_version_from_the_style_entry_hash() {
		$metadata = Blocks::version_block_styles(
			$this->metadata(
				[
					'style'       => 'file:../entry-a.css',
					'editorStyle' => 'file:../entry-a.css',
				]
			)
		);
		$this->assertSame( 'hash-entry-a', $metadata['version'] );
	}

	/**
	 * Stylesheets from two entries share one version that differs from either hash.
	 */
	public function test_combines_hashes_from_several_entries() {
		$metadata = Blocks::version_block_styles(
			$this->metadata(
				[
					'style'     => 'file:../entry-a.css',
					'viewStyle' => [ 'file:../entry-b.css' ],
				]
			)
		);
		$this->assertSame( substr( md5( 'hash-entry-a,hash-entry-b' ), 0, 20 ), $metadata['version'] );
	}

	/**
	 * A version set in block.json is left alone, and so is a block with no
	 * stylesheet from the build.
	 */
	public function test_leaves_other_blocks_alone() {
		$with_version = $this->metadata(
			[
				'version' => '1.2.3',
				'style'   => 'file:../entry-a.css',
			]
		);
		$this->assertSame( $with_version, Blocks::version_block_styles( $with_version ) );

		$handle_only = $this->metadata( [ 'style' => 'wp-block-paragraph' ] );
		$this->assertSame( $handle_only, Blocks::version_block_styles( $handle_only ) );
	}

	/**
	 * Registering a block.json runs the filter before WordPress builds the
	 * style handle, so the handle carries the hash rather than the WordPress
	 * version.
	 */
	public function test_registered_style_handle_carries_the_hash() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'Under SCRIPT_DEBUG WordPress versions block styles by mtime instead.' );
		}
		file_put_contents( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			$this->fixture_dir . 'block/block.json',
			wp_json_encode(
				[
					'apiVersion' => 3,
					'name'       => 'newspack-test/style-version',
					'title'      => 'Style version fixture',
					'style'      => 'file:../entry-a.css',
				]
			)
		);
		$block_type = register_block_type_from_metadata( $this->fixture_dir . 'block' );
		$handle     = $block_type->style_handles[0];
		$this->assertSame( 'hash-entry-a', wp_styles()->registered[ $handle ]->ver );
	}
}
