<?php
/**
 * Template part reference tests.
 *
 * @package Newspack_Block_Theme
 */

/**
 * Tests the template part references in the theme's block markup.
 */
class Test_Template_Parts extends WP_UnitTestCase {
	/**
	 * List the theme's template and template part files.
	 *
	 * @return string[] File paths.
	 */
	private function get_markup_files() {
		$files = glob( dirname( __DIR__ ) . '/parts/*.html' );
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/templates', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( 'html' === $item->getExtension() ) {
				$files[] = $item->getPathname();
			}
		}
		return $files;
	}

	/**
	 * Collect the template part blocks in a list of blocks, at any depth.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Template part blocks.
	 */
	private function find_template_parts( $blocks ) {
		$found = [];
		foreach ( $blocks as $block ) {
			if ( 'core/template-part' === $block['blockName'] ) {
				$found[] = $block;
			}
			$found = array_merge( $found, $this->find_template_parts( $block['innerBlocks'] ) );
		}
		return $found;
	}

	/**
	 * WordPress only resolves a template part whose theme attribute matches the active
	 * theme, so naming this theme would leave the part unavailable under a child theme.
	 */
	public function test_template_part_references_do_not_name_a_theme() {
		$references = 0;
		foreach ( $this->get_markup_files() as $file ) {
			foreach ( $this->find_template_parts( parse_blocks( file_get_contents( $file ) ) ) as $part ) { // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				++$references;
				$this->assertArrayNotHasKey( 'theme', $part['attrs'], basename( $file ) . ': ' . $part['attrs']['slug'] );
			}
		}
		$this->assertGreaterThan( 0, $references );
	}
}
