<?php
/**
 * Content Distribution Custom Handling for Gutenberg Blocks
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Content_Distribution;

/**
 * Blocks class.
 */
class Blocks {
	/**
	 * Registered block processors
	 *
	 * @var array<string, Block_Processor[]> Array of block processors indexed by block name.
	 */
	private static $block_processors = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		Image_Block::init();

		// Register block processors.
		self::register_block_processor( 'jetpack/slideshow', [ __CLASS__, 'process_jetpack_galleries' ] );
		self::register_block_processor( 'jetpack/tiled-gallery', [ __CLASS__, 'process_jetpack_galleries' ] );
		self::register_block_processor( 'core/gallery', [ __CLASS__, 'process_dynamic_gallery' ] );
	}

	/**
	 * Register a block processor.
	 *
	 * @param string        $block_name        The name of the block to process.
	 * @param callable|null $outgoing_callback The callback to transform the outgoing block.
	 * @param callable|null $incoming_callback The callback to transform the incoming block.
	 *
	 * @return void
	 */
	public static function register_block_processor( $block_name, $outgoing_callback = null, $incoming_callback = null ) {
		$block_processor = new Block_Processor( $block_name, $outgoing_callback, $incoming_callback );
		if ( ! isset( self::$block_processors[ $block_name ] ) ) {
			self::$block_processors[ $block_name ] = [];
		}
		self::$block_processors[ $block_name ][] = $block_processor;
	}

	/**
	 * Reset the block processors for a block name.
	 *
	 * @param string $block_name The name of the block.
	 *
	 * @return void
	 */
	public static function reset_block_processors( $block_name ) {
		self::$block_processors[ $block_name ] = [];
	}

	/**
	 * Process an outgoing block.
	 *
	 * Recurses into inner blocks so a block nested inside a Group, Columns or
	 * similar container is processed too. Without this, a gallery only gets
	 * handled when it sits at the top level of the post.
	 *
	 * @param array $block   The block to process.
	 * @param int   $post_id The ID of the post being distributed.
	 *
	 * @return array The processed block.
	 */
	public static function process_outgoing_block( $block, $post_id = 0 ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = array_map(
				function ( $inner_block ) use ( $post_id ) {
					return self::process_outgoing_block( $inner_block, $post_id );
				},
				$block['innerBlocks']
			);
		}

		$block_name = $block['blockName'];

		$processors = self::get_block_processors( $block_name );
		if ( empty( $processors ) ) {
			return $block;
		}

		foreach ( $processors as $processor ) {
			$block = $processor->process_outgoing_block( $block, $post_id );
		}
		return $block;
	}

	/**
	 * Process an incoming block.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_incoming_block( $block ) {
		$block_name = $block['blockName'];

		$processors = self::get_block_processors( $block_name );
		if ( empty( $processors ) ) {
			return $block;
		}

		foreach ( $processors as $processor ) {
			$block = $processor->process_incoming_block( $block );
		}
		return $block;
	}

	/**
	 * Get the processors for a block.
	 *
	 * @param string $block_name The name of the block.
	 *
	 * @return Block_Processor[] The block processors.
	 */
	public static function get_block_processors( $block_name ) {
		return self::$block_processors[ $block_name ] ?? [];
	}

	/**
	 * Process Jetpack galleries blocks.
	 *
	 * @param array $block The block to process.
	 *
	 * @return array The processed block.
	 */
	public static function process_jetpack_galleries( $block ) {
		unset( $block['attrs']['ids'] );
		return $block;
	}

	/**
	 * Flatten a dynamic gallery into ordinary image blocks for distribution.
	 *
	 * A gallery in dynamic mode stores no image IDs. It stores the instruction
	 * "show the images attached to this post", which core resolves at render time
	 * against whichever post is being rendered. That instruction travels intact,
	 * but it means something different on a node, where the only attached image is
	 * the sideloaded featured image. The gallery then arrives showing a single
	 * unrelated image, or nothing at all, with no error either way.
	 *
	 * Resolving the images here, on the origin, leaves the block in the same shape
	 * an ordinary gallery is saved in, which already distributes correctly. The
	 * node's copy stops following new attachments, which matches how every other
	 * edit reaches a node: on the next sync.
	 *
	 * @param array $block   The block to process.
	 * @param int   $post_id The ID of the post being distributed.
	 *
	 * @return array The processed block.
	 */
	public static function process_dynamic_gallery( $block, $post_id = 0 ) {
		if ( empty( $block['attrs']['dynamicContent'] ) ) {
			return $block;
		}

		// Dynamic galleries arrived in WordPress 7.1. Leave the block alone on older versions.
		if ( ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
			return $block;
		}

		$post_id = $post_id ? $post_id : get_the_ID();
		if ( ! $post_id ) {
			return $block;
		}

		$attachment_ids = block_core_gallery_resolve_dynamic_source(
			$block['attrs']['dynamicContent'],
			new \WP_Block( $block, [ 'postId' => $post_id ] )
		);
		if ( empty( $attachment_ids ) ) {
			return $block;
		}

		$size_slug = $block['attrs']['sizeSlug'] ?? 'large';
		$link_to   = $block['attrs']['linkTo'] ?? 'none';

		$images = [];
		foreach ( $attachment_ids as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, $size_slug );
			if ( ! $url ) {
				continue;
			}
			$images[] = sprintf(
				"<!-- wp:image %1\$s -->\n<figure class=\"wp-block-image size-%2\$s\"><img src=\"%3\$s\" alt=\"%4\$s\" class=\"wp-image-%5\$d\"/></figure>\n<!-- /wp:image -->",
				wp_json_encode(
					[
						'id'              => (int) $attachment_id,
						'sizeSlug'        => $size_slug,
						'linkDestination' => $link_to,
					]
				),
				esc_attr( $size_slug ),
				esc_url( $url ),
				esc_attr( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
				(int) $attachment_id
			);
		}

		if ( empty( $images ) ) {
			return $block;
		}

		$attrs = $block['attrs'];
		unset( $attrs['dynamicContent'] );

		// Keep the gallery's own wrapper so its classes and layout survive.
		$figure_open = '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">';
		if ( ! empty( $block['innerHTML'] ) && preg_match( '/<figure[^>]*>/', $block['innerHTML'], $matches ) ) {
			$figure_open = $matches[0];
		}

		$markup = sprintf(
			"<!-- wp:gallery %1\$s -->\n%2\$s\n%3\$s\n</figure>\n<!-- /wp:gallery -->",
			wp_json_encode( (object) $attrs ),
			$figure_open,
			implode( "\n", $images )
		);

		$parsed = parse_blocks( $markup );

		return $parsed[0] ?? $block;
	}
}
