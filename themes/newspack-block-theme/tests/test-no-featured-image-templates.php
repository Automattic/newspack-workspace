<?php
/**
 * No featured image template tests.
 *
 * @package Newspack_Block_Theme
 */

/**
 * Tests that every template showing a featured image has a no featured image version.
 */
class Test_No_Featured_Image_Templates extends WP_UnitTestCase {
	/**
	 * Name of the block that holds the featured image and its caption.
	 */
	const IMAGE_BLOCK = 'Post Image';

	/**
	 * Twins whose slug does not follow the `<parent>--no-featured-image` pattern.
	 *
	 * A hierarchy slug such as `single-newspack_nl_cpt-…` would capture a newsletter whose
	 * slug matched the suffix, so these twins live under `single/` instead.
	 */
	const TWIN_SLUGS = [
		'single'                 => 'single/no-featured-image',
		'single-newspack_nl_cpt' => 'single/newsletter--no-featured-image',
	];

	/**
	 * Post types of the hierarchy templates, which have no customTemplates entry.
	 */
	const HIERARCHY_POST_TYPES = [
		'single'                 => [ 'post' ],
		'single-newspack_nl_cpt' => [ 'newspack_nl_cpt' ],
	];

	/**
	 * Read a template's markup.
	 *
	 * @param string $slug Template slug.
	 * @return string|false Markup, or false when the file does not exist.
	 */
	private function read_template( $slug ) {
		$file = dirname( __DIR__ ) . '/templates/' . $slug . '.html';
		return file_exists( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
	}

	/**
	 * Remove the image block from a list of parsed blocks, at any depth.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $found  Number of image blocks removed, incremented by reference.
	 * @return array Blocks without the image block.
	 */
	private function remove_image_block( $blocks, &$found ) {
		$kept = [];
		foreach ( $blocks as $block ) {
			if ( self::IMAGE_BLOCK === ( $block['attrs']['metadata']['name'] ?? '' ) ) {
				++$found;
				continue;
			}
			$inner_blocks  = [];
			$inner_content = [];
			$index         = 0;
			foreach ( $block['innerContent'] as $chunk ) {
				if ( null !== $chunk ) {
					$inner_content[] = $chunk;
					continue;
				}
				$child = $this->remove_image_block( [ $block['innerBlocks'][ $index++ ] ], $found );
				if ( $child ) {
					$inner_blocks[]  = $child[0];
					$inner_content[] = null;
				}
			}
			$block['innerBlocks']  = $inner_blocks;
			$block['innerContent'] = $inner_content;
			$kept[]                = $block;
		}
		return $kept;
	}

	/**
	 * Serialize blocks with whitespace collapsed, so indentation differences do not count.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string Markup.
	 */
	private function normalize( $blocks ) {
		return preg_replace( '/\s+/', ' ', preg_replace( '/>\s+</', '><', serialize_blocks( $blocks ) ) );
	}

	/**
	 * Every template with the image block has a twin that is the same template without it,
	 * registered for the same post types.
	 */
	public function test_each_image_template_has_a_matching_twin() {
		$theme_json = wp_json_file_decode( dirname( __DIR__ ) . '/theme.json', [ 'associative' => true ] );
		$registered = array_column( $theme_json['customTemplates'], null, 'name' );
		$twins      = array_merge( array_values( self::TWIN_SLUGS ), preg_grep( '/--no-featured-image$/', array_keys( $registered ) ) );
		$checked    = 0;

		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/templates', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$slug = substr( $item->getPathname(), strlen( dirname( __DIR__ ) . '/templates/' ), -5 );
			if ( 'html' !== $item->getExtension() || in_array( $slug, $twins, true ) ) {
				continue;
			}

			$found  = 0;
			$parent = $this->remove_image_block( parse_blocks( $this->read_template( $slug ) ), $found );
			if ( ! $found ) {
				continue;
			}

			$twin_slug = self::TWIN_SLUGS[ $slug ] ?? $slug . '--no-featured-image';
			$twin      = $this->read_template( $twin_slug );
			$this->assertNotFalse( $twin, "$slug has no $twin_slug template" );
			$this->assertSame( $this->normalize( $parent ), $this->normalize( parse_blocks( $twin ) ), "$twin_slug differs from $slug beyond the image block" );

			$this->assertArrayHasKey( $twin_slug, $registered, "$twin_slug is not registered in theme.json" );
			$post_types = self::HIERARCHY_POST_TYPES[ $slug ] ?? $registered[ $slug ]['postTypes'];
			$this->assertSame( $post_types, $registered[ $twin_slug ]['postTypes'], "$twin_slug is registered for different post types than $slug" );
			++$checked;
		}

		$this->assertGreaterThan( 0, $checked );
	}
}
