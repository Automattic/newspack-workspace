<?php
/**
 * Tests Republication_Tracker_Tool_Republish_Button_Block::set_block_version().
 *
 * @package Republication_Tracker_Tool
 */

/**
 * The Republish Button block's stylesheet gets the build's content hash as the
 * block `version`, so WordPress stops versioning it by the WordPress version.
 */
class RepublishButtonBlockVersionTest extends WP_UnitTestCase {

	/**
	 * Unregister the block this class registers without a render callback.
	 * Other test classes register it only when it's missing, so leaving this
	 * registration behind would make their renders come back empty.
	 */
	public function tear_down() {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( 'republication-tracker-tool/republish-button' ) ) {
			$registry->unregister( 'republication-tracker-tool/republish-button' );
		}
		parent::tear_down();
	}

	/**
	 * The version the block should get: the build's hash, or the plugin
	 * version when the build is missing.
	 *
	 * @return string
	 */
	private function expected_version() {
		$asset_file = REPUBLICATION_TRACKER_TOOL_PATH . 'dist/republish-button.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : [];
		return ! empty( $asset['version'] ) ? $asset['version'] : REPUBLICATION_TRACKER_TOOL_VERSION;
	}

	/**
	 * It sets the version for the Republish Button block.
	 */
	public function test_sets_version_for_the_block() {
		$metadata = Republication_Tracker_Tool_Republish_Button_Block::set_block_version(
			[ 'name' => 'republication-tracker-tool/republish-button' ]
		);
		$this->assertSame( $this->expected_version(), $metadata['version'] );
	}

	/**
	 * It leaves other blocks, and a version block.json already sets, alone.
	 */
	public function test_leaves_other_metadata_alone() {
		$other = [ 'name' => 'core/paragraph' ];
		$this->assertSame( $other, Republication_Tracker_Tool_Republish_Button_Block::set_block_version( $other ) );

		$versioned = [
			'name'    => 'republication-tracker-tool/republish-button',
			'version' => '1.2.3',
		];
		$this->assertSame( $versioned, Republication_Tracker_Tool_Republish_Button_Block::set_block_version( $versioned ) );
	}

	/**
	 * The registered block's style handle carries that version.
	 */
	public function test_registered_style_handle_carries_the_version() {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$this->markTestSkipped( 'Under SCRIPT_DEBUG WordPress versions block styles by mtime instead.' );
		}
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( 'republication-tracker-tool/republish-button' ) ) {
			$registry->unregister( 'republication-tracker-tool/republish-button' );
		}
		// WordPress keeps the first registration of a handle, so drop the one
		// plugin load made; the assertion must see this registration's version.
		wp_deregister_style( generate_block_asset_handle( 'republication-tracker-tool/republish-button', 'style' ) );
		$block_type = register_block_type_from_metadata( REPUBLICATION_TRACKER_TOOL_PATH . 'src/blocks/republish-button' );
		$handle     = $block_type->style_handles[0];
		$this->assertSame( $this->expected_version(), wp_styles()->registered[ $handle ]->ver );
	}
}
