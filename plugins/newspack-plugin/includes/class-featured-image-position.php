<?php
/**
 * Newspack's featured image position, honoured on block themes.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Honours the `hidden` featured image position on block themes.
 *
 * The classic theme lets a publisher choose where a post's featured image
 * appears, including `hidden`, which suppresses an image the post still has.
 * That choice is stored per post in `newspack_featured_image_position`, falling
 * back to the `featured_image_default` and `page_featured_image_default` theme
 * mods.
 *
 * Block themes render the image through `core/post-featured-image`, which only
 * returns empty when there is no thumbnail at all. It knows nothing about the
 * Newspack setting, so a post whose image the publisher hid shows it again.
 *
 * This class reproduces the classic resolution and suppresses the block.
 */
class Featured_Image_Position {

	/**
	 * The per-post meta key carrying the position.
	 *
	 * @var string
	 */
	const META_KEY = 'newspack_featured_image_position';

	/**
	 * The position that suppresses the image.
	 *
	 * @var string
	 */
	const HIDDEN = 'hidden';

	/**
	 * The name given to the group wrapping the image in the block theme's
	 * single templates. All six of them use it.
	 *
	 * @var string
	 */
	const WRAPPER_BLOCK_NAME = 'Post Image';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'filter_featured_image_block' ], 10, 2 );
		add_filter( 'render_block_core/group', [ __CLASS__, 'filter_image_wrapper' ], 10, 2 );
	}

	/**
	 * Whether the featured image should be suppressed for the current request.
	 *
	 * Mirrors `newspack_featured_image_position()` in the classic theme: the
	 * position applies to singular posts and pages only, the per-post meta wins
	 * over the site default, and the defaults differ between posts and pages.
	 *
	 * The classic function also downgrades `large` to `small` for narrow images,
	 * which is irrelevant here — only `hidden` suppresses anything.
	 *
	 * @param int|null $post_id Post to check. Defaults to the current post.
	 * @return bool
	 */
	public static function is_hidden( $post_id = null ) {
		if ( ! is_singular() ) {
			return false;
		}

		$post_id = $post_id ? $post_id : get_the_ID();
		if ( ! $post_id ) {
			return false;
		}

		/*
		 * The position governs how a post displays as itself, not how it looks
		 * in someone else's loop. A single post can render query loops of other
		 * posts — related content, a curated block — and those keep their
		 * thumbnails, exactly as they do on an archive.
		 */
		if ( (int) $post_id !== (int) get_queried_object_id() ) {
			return false;
		}

		$position = get_post_meta( $post_id, self::META_KEY, true );

		if ( '' === $position ) {
			$position = 'page' === get_post_type( $post_id )
				? get_theme_mod( 'page_featured_image_default', 'small' )
				: get_theme_mod( 'featured_image_default', 'large' );
		}

		return self::HIDDEN === $position;
	}

	/**
	 * Suppress the featured image block when the position is `hidden`.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_featured_image_block( $block_content, $block ) {
		unset( $block );

		if ( self::is_hidden() ) {
			return '';
		}

		return $block_content;
	}

	/**
	 * Drop the group that wraps the featured image when nothing will render
	 * inside it.
	 *
	 * The block theme's single templates wrap the image in a named group that
	 * carries vertical spacing of its own. Suppressing only the image would
	 * leave that spacing behind as a gap, so the wrapper goes too.
	 *
	 * This also covers posts that simply have no featured image, where the
	 * wrapper renders empty today.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_image_wrapper( $block_content, $block ) {
		if ( ! isset( $block['attrs']['metadata']['name'] ) || self::WRAPPER_BLOCK_NAME !== $block['attrs']['metadata']['name'] ) {
			return $block_content;
		}

		$post_id = get_queried_object_id();
		if ( ! is_singular() || ! $post_id ) {
			return $block_content;
		}

		if ( self::is_hidden( $post_id ) || ! has_post_thumbnail( $post_id ) ) {
			return '';
		}

		return $block_content;
	}
}

Featured_Image_Position::init();
