<?php
/**
 * Content Distribution Custom Handling for Gutenberg Blocks
 *
 * @package Newspack_Network
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Debugger;

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
	 * Processors must return one block per block. Returning a different count would
	 * desynchronise the parent's `innerContent` null placeholders.
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
	 * an ordinary gallery is saved in, which already distributes correctly.
	 *
	 * The image and wrapper construction mirrors core's own dynamic render
	 * (the `dynamicContent` branch of `block_core_gallery_render()`), reusing core's
	 * link helper so links, the lightbox and captions match what the origin shows.
	 * The markup itself is the shape the blocks *save* in, not the shape core
	 * renders, since a node stores it and its editor validates it against `save()`.
	 * Blocks are built as arrays and left for `serialize_blocks()` to encode, so
	 * attribute values never pass through a hand-written block-comment delimiter.
	 *
	 * Two caveats worth knowing:
	 *
	 * - The node's copy is static. Attaching a new image on the origin does not by
	 *   itself resync the post, so the node updates on the next save of the post,
	 *   not on the upload.
	 * - When nothing resolves, the block is flattened to an empty gallery rather
	 *   than shipped as-is. Passing the dynamic instruction through would let the
	 *   node resolve it against its own attachments, which is the bug this exists
	 *   to prevent.
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

		// Dynamic galleries arrived with the WordPress 7.1 gallery block. Reaching
		// here without those functions means 7.1-authored content on an older core:
		// a downgrade, an import or a migration. Nothing can resolve the images, but
		// shipping the instruction would let the node resolve it against its own
		// attachments, so strip it and let the gallery arrive empty.
		if (
			! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ||
			! function_exists( 'block_core_gallery_dynamic_image_link_attributes' )
		) {
			Debugger::log( 'Dynamic gallery found on a WordPress older than 7.1, flattening to an empty gallery.' );
			return self::flatten_gallery( $block, [] );
		}

		if ( ! $post_id ) {
			Debugger::log( 'Dynamic gallery: no post ID given, flattening to an empty gallery.' );
			return self::flatten_gallery( $block, [] );
		}

		$attachment_ids = block_core_gallery_resolve_dynamic_source(
			$block['attrs']['dynamicContent'],
			new \WP_Block( $block, [ 'postId' => $post_id ] )
		);

		if ( empty( $attachment_ids ) ) {
			Debugger::log( sprintf( 'Dynamic gallery on post %d resolved no images.', $post_id ) );
			return self::flatten_gallery( $block, [] );
		}

		// The source query only fetched IDs, which skips WP_Query's cache priming,
		// and each image below reads the attachment post and its meta. Warm both in
		// one pair of queries rather than paying two per attachment.
		if ( count( $attachment_ids ) > 1 ) {
			_prime_post_caches( $attachment_ids, false, true );
		}

		// Distribute origin URLs rather than the origin's image CDN, matching every
		// other media URL this plugin puts on the wire.
		add_filter( 'jetpack_photon_override_image_downsize', '__return_true' );

		$image_blocks = [];
		foreach ( $attachment_ids as $attachment_id ) {
			$image_block = self::build_gallery_image_block( $attachment_id, $block['attrs'] );
			if ( $image_block ) {
				$image_blocks[] = $image_block;
			}
		}

		remove_filter( 'jetpack_photon_override_image_downsize', '__return_true' );

		if ( empty( $image_blocks ) ) {
			Debugger::log( sprintf( 'Dynamic gallery on post %d resolved %d images, none of which produced markup.', $post_id, count( $attachment_ids ) ) );
		}

		return self::flatten_gallery( $block, $image_blocks );
	}

	/**
	 * Build the flattened gallery block around a list of image blocks.
	 *
	 * Mirrors the wrapper core builds for a dynamic gallery: the gallery-specific
	 * classes from `save.js`, plus the block-support classes and styles (align,
	 * colors, border, spacing, anchor) that a static gallery carries in its saved
	 * markup. Layout classes are deliberately left out, since the layout render
	 * filter adds those on the node exactly as it does on the origin.
	 *
	 * @param array $block        The original dynamic gallery block.
	 * @param array $image_blocks The image blocks to nest, possibly empty.
	 *
	 * @return array The flattened gallery block.
	 */
	private static function flatten_gallery( $block, $image_blocks ) {
		$attrs = $block['attrs'];
		unset( $attrs['dynamicContent'] );

		$classes  = 'wp-block-gallery has-nested-images';
		$classes .= isset( $attrs['columns'] ) ? ' columns-' . (int) $attrs['columns'] : ' columns-default';
		if ( $attrs['imageCrop'] ?? true ) {
			$classes .= ' is-cropped';
		}

		$wrapper = self::get_block_wrapper_attributes( $block, $classes );

		// In dynamic mode `save.js` persists at most the gallery caption, so the
		// block's own inner HTML is that `<figcaption>` or nothing. Core appends it
		// after the images, and drops it when there are no images to caption.
		$caption = $image_blocks ? trim( $block['innerHTML'] ?? '' ) : '';

		$opening       = sprintf( '<figure %s>', $wrapper );
		$closing       = $caption . '</figure>';
		$inner_content = array_merge(
			[ $opening ],
			array_fill( 0, count( $image_blocks ), null ),
			[ $closing ]
		);

		return [
			'blockName'    => 'core/gallery',
			'attrs'        => $attrs,
			'innerBlocks'  => $image_blocks,
			'innerHTML'    => $opening . $closing,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a single `core/image` block for a resolved gallery image.
	 *
	 * Deliberately mirrors `block_core_gallery_render_dynamic_image()`, stopping
	 * short of rendering so the block can be nested instead.
	 *
	 * @param int   $attachment_id The image attachment ID.
	 * @param array $attributes    The gallery block attributes.
	 *
	 * @return array|null The image block, or null when no markup could be built.
	 */
	private static function build_gallery_image_block( $attachment_id, $attributes ) {
		$size_slug    = $attributes['sizeSlug'] ?? 'large';
		$aspect_ratio = $attributes['aspectRatio'] ?? 'auto';
		$has_ratio    = $aspect_ratio && 'auto' !== $aspect_ratio;

		$url = wp_get_attachment_image_url( $attachment_id, $size_slug );
		if ( ! $url ) {
			return null;
		}

		$image_attributes = array_merge(
			[
				'id'       => (int) $attachment_id,
				'sizeSlug' => $size_slug,
			],
			block_core_gallery_dynamic_image_link_attributes( $attachment_id, $attributes )
		);

		// The image block's save() derives the inline style from these two attributes,
		// so they have to travel together. Attributes without the style, or the style
		// without the attributes, puts save() out of step with the stored markup and
		// the block fails validation on the node.
		if ( $has_ratio ) {
			$image_attributes['aspectRatio'] = $aspect_ratio;
			$image_attributes['scale']       = 'cover';
		}

		// Build the `<img>` the way the image block *saves* it, not the way core
		// renders it. `wp_get_attachment_image()` adds width, height, srcset, sizes,
		// loading and decoding, which the block's `save()` never emits, so persisting
		// them makes the block fail validation the moment an editor opens the post.
		// Those attributes are added at render time on the node, as they are for any
		// other distributed image.
		$image_markup = sprintf(
			'<img src="%1$s" alt="%2$s" class="wp-image-%3$d"%4$s/>',
			esc_url( $url ),
			esc_attr( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			(int) $attachment_id,
			$has_ratio
				? ' style="' . esc_attr( safecss_filter_attr( sprintf( 'aspect-ratio:%s;object-fit:cover;', $aspect_ratio ) ) ) . '"'
				: ''
		);

		if ( ! empty( $image_attributes['href'] ) ) {
			$image_markup = sprintf(
				'<a href="%1$s"%2$s%3$s>%4$s</a>',
				esc_url( $image_attributes['href'] ),
				isset( $image_attributes['linkTarget'] ) ? ' target="' . esc_attr( $image_attributes['linkTarget'] ) . '"' : '',
				isset( $image_attributes['rel'] ) ? ' rel="' . esc_attr( $image_attributes['rel'] ) . '"' : '',
				$image_markup
			);
		}

		$attachment = get_post( $attachment_id );
		$caption    = $attachment ? $attachment->post_excerpt : '';
		if ( '' !== $caption ) {
			$image_markup .= sprintf( '<figcaption class="wp-element-caption">%s</figcaption>', wp_kses_post( $caption ) );
		}

		$figure = sprintf(
			'<figure class="wp-block-image size-%1$s">%2$s</figure>',
			esc_attr( $size_slug ),
			$image_markup
		);

		return [
			'blockName'    => 'core/image',
			'attrs'        => $image_attributes,
			'innerBlocks'  => [],
			'innerHTML'    => $figure,
			'innerContent' => [ $figure ],
		];
	}

	/**
	 * Get the block-support wrapper attributes for a block outside of a render.
	 *
	 * `get_block_wrapper_attributes()` reads the block being rendered from
	 * `WP_Block_Supports`, which is only set during a render. Distribution runs
	 * outside one, so point it at this block and put back whatever was there.
	 *
	 * @param array  $block   The block whose supports should be applied.
	 * @param string $classes Additional classes for the wrapper.
	 *
	 * @return string The wrapper attributes.
	 */
	private static function get_block_wrapper_attributes( $block, $classes ) {
		if ( ! class_exists( '\WP_Block_Supports' ) || ! function_exists( 'get_block_wrapper_attributes' ) ) {
			return sprintf( 'class="%s"', esc_attr( $classes ) );
		}

		$previous                            = \WP_Block_Supports::$block_to_render;
		\WP_Block_Supports::$block_to_render = $block;

		try {
			$attributes = get_block_wrapper_attributes( [ 'class' => $classes ] );
		} finally {
			// Block supports call third-party callbacks, so restore the static even if
			// one of them throws. Distribution runs a queue with no catch of its own,
			// and leaving this set would outlive the block being processed.
			\WP_Block_Supports::$block_to_render = $previous;
		}

		return $attributes ? $attributes : sprintf( 'class="%s"', esc_attr( $classes ) );
	}
}
