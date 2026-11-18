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
 * The choice is stored per post in `newspack_featured_image_position`, falling
 * back to the `featured_image_default` and `page_featured_image_default` theme
 * mods.
 *
 * Block themes render the image through `core/post-featured-image`, which only
 * returns empty when there is no thumbnail at all. It knows nothing about the
 * Newspack setting, so a post whose image the publisher hid shows it again.
 *
 * Three things have to go, because the templates render three things:
 *
 * 1. the image itself (`core/post-featured-image`);
 * 2. its caption and photo credit (`newspack/featured-image-caption`), which
 *    render from the attachment and so survive the image's removal;
 * 3. the container holding them, which carries vertical spacing of its own and
 *    would otherwise leave a gap where the image was.
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
	 * Theme mods carrying the site-wide defaults. Stored per theme, so they do
	 * not survive a switch on their own.
	 *
	 * @var string[]
	 */
	const THEME_MOD_KEYS = [ 'featured_image_default', 'page_featured_image_default' ];

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'after_switch_theme', [ __CLASS__, 'migrate_position_defaults' ], 10, 2 );
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
		add_filter( 'render_block_newspack/featured-image-caption', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
		add_filter( 'render_block_core/group', [ __CLASS__, 'filter_image_wrapper' ], 10, 2 );
		add_filter( 'render_block_core/column', [ __CLASS__, 'filter_image_wrapper' ], 10, 2 );
	}

	/**
	 * Carry the site-wide defaults across a theme switch.
	 *
	 * Theme mods live in `theme_mods_{stylesheet}`, one row per theme, and
	 * `get_theme_mod()` only ever reads the active theme's row. Without this, a
	 * publisher who set the default to `hidden` on the classic theme loses it
	 * the moment they switch, and every one of their posts shows its image
	 * again — which is the defect this class exists to prevent.
	 *
	 * Mirrors `Post_Date::migrate_date_settings()`.
	 *
	 * @param string    $old_name  Name of the previous theme.
	 * @param \WP_Theme $old_theme Previous theme object.
	 */
	public static function migrate_position_defaults( $old_name, $old_theme ) {
		if ( ! $old_theme instanceof \WP_Theme ) {
			return;
		}

		$old_mods = get_option( 'theme_mods_' . $old_theme->get_stylesheet(), [] );
		if ( ! is_array( $old_mods ) ) {
			return;
		}

		foreach ( self::THEME_MOD_KEYS as $key ) {
			// Never overwrite a value the new theme already carries.
			if ( isset( $old_mods[ $key ] ) && false === get_theme_mod( $key, false ) ) {
				set_theme_mod( $key, $old_mods[ $key ] );
			}
		}
	}

	/**
	 * Whether the featured image should be suppressed for a given post.
	 *
	 * Mirrors `newspack_featured_image_position()` in the classic theme:
	 *
	 * - it applies to single posts and pages only;
	 * - a post with no thumbnail has no position at all;
	 * - the per-post meta wins over the site default;
	 * - posts and pages read different defaults.
	 *
	 * The classic function also downgrades `large` to `small` for narrow
	 * images, which is irrelevant here — only `hidden` suppresses anything.
	 *
	 * @param int|null $post_id Post to check. Defaults to the current post.
	 * @return bool
	 */
	public static function is_hidden( $post_id = null ) {
		/*
		 * The same pair of conditionals the classic function uses. Not
		 * `is_singular()`, which is also true for attachment pages, where
		 * classic never resolves a position.
		 */
		if ( ! is_single() && ! is_page() ) {
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

		/*
		 * Classic returns an empty position before it reads anything when the
		 * post has no thumbnail, so a site-wide `hidden` default never applies
		 * to a post with no image. Without this guard the wrapper would be
		 * dropped from posts that have nothing to hide.
		 */
		if ( ! has_post_thumbnail( $post_id ) ) {
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
	 * Return nothing for a block whose post has its featured image hidden.
	 *
	 * Registered on both the image block and Newspack's caption block: the
	 * caption and photo credit read from the attachment, so removing the image
	 * alone leaves them captioning nothing.
	 *
	 * @param string         $block_content Rendered block.
	 * @param array          $block         Parsed block.
	 * @param \WP_Block|null $instance      Block instance.
	 * @return string
	 */
	public static function suppress_when_hidden( $block_content, $block, $instance = null ) {
		if ( ! wp_is_block_theme() ) {
			return $block_content;
		}

		// The block's own post, which is what core's render callback uses.
		$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : null;

		if ( self::is_hidden( $post_id ) ) {
			return '';
		}

		return $block_content;
	}

	/**
	 * Drop the block wrapping the featured image once nothing is left in it.
	 *
	 * The block theme's single templates wrap the image and its caption in a
	 * container carrying vertical spacing of its own, so suppressing only the
	 * contents would leave that spacing behind as a gap.
	 *
	 * Three conditions, cheapest first, and all three are needed:
	 *
	 * - the post's image is hidden, so there is something to clean up at all;
	 * - the block contains a featured image somewhere beneath it. Identified by
	 *   what it contains rather than by its name or class: the templates use a
	 *   group in six places and a column in the seventh, and a block's name is
	 *   editable in the Site Editor, so a publisher's own "Post Image" group
	 *   must never be dropped for sharing a label;
	 * - what it rendered is empty. This is the condition that keeps the
	 *   containment test safe. Every ancestor of the image contains it,
	 *   including the `<main>` group wrapping the whole article, and
	 *   `render_block` filters fire innermost-first — so by the time an
	 *   ancestor is filtered, the image and caption beneath it have already
	 *   been emptied, and only a container that held nothing else is now blank.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_image_wrapper( $block_content, $block ) {
		if ( ! wp_is_block_theme() ) {
			return $block_content;
		}

		if ( ! self::is_hidden() ) {
			return $block_content;
		}

		if ( ! self::contains_featured_image( $block ) ) {
			return $block_content;
		}

		if ( ! self::renders_nothing( $block_content ) ) {
			return $block_content;
		}

		return '';
	}

	/**
	 * Whether a parsed block contains a featured image block at any depth.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function contains_featured_image( $block ) {
		if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return false;
		}

		foreach ( $block['innerBlocks'] as $inner ) {
			if ( isset( $inner['blockName'] ) && 'core/post-featured-image' === $inner['blockName'] ) {
				return true;
			}

			if ( self::contains_featured_image( $inner ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether rendered markup puts nothing on the page.
	 *
	 * Text is not the only thing that counts: an element can be visible with no
	 * text at all, so anything that draws or accepts input keeps the block. A
	 * container left holding only its own markup is empty.
	 *
	 * @param string $block_content Rendered block.
	 * @return bool
	 */
	private static function renders_nothing( $block_content ) {
		if ( '' === trim( $block_content ) ) {
			return true;
		}

		if ( preg_match( '#<(img|picture|video|audio|iframe|svg|canvas|object|embed|input|button|select|textarea|table|hr)[\s>/]#i', $block_content ) ) {
			return false;
		}

		if ( false !== strpos( $block_content, 'background-image' ) ) {
			return false;
		}

		return '' === trim( wp_strip_all_tags( $block_content ) );
	}
}

Featured_Image_Position::init();
