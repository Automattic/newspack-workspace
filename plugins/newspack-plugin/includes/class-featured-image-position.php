<?php
/**
 * Newspack's featured image position, honored on block themes.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Honors the `hidden` featured image position on block themes.
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
	 * The only blocks allowed beneath a container before it counts as the
	 * image's own box. Anything else means the container is holding something
	 * besides the image, so it stays.
	 *
	 * @var string[]
	 */
	const IMAGE_BOX_BLOCKS = [
		'core/group',
		'core/column',
		'core/post-featured-image',
		'newspack/featured-image-caption',
	];

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'after_switch_theme', [ __CLASS__, 'migrate_position_defaults' ], 10, 2 );
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
		add_filter( 'render_block_newspack/featured-image-caption', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );

		/*
		 * core/columns is deliberately absent. In `single/50-50-image.html` the
		 * image's sibling column holds the post header, so dropping the image
		 * column alone would let the remaining width-less one expand. Do not
		 * "complete" this list.
		 */
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
	 * - posts and pages read different defaults;
	 * - a post type outside `newspack_theme_featured_image_post_types` never
	 *   resolves to `hidden`. In classic that is a side effect of the clause
	 *   that downgrades `large` to `small`, which is an `||` and overwrites any
	 *   position, `hidden` included. Miss it and a site-wide `hidden` default
	 *   strips images from custom post types that classic still shows.
	 *
	 * The narrow-image half of that same clause is genuinely irrelevant here,
	 * because only `hidden` suppresses anything.
	 *
	 * One deliberate divergence: classic returns before the downgrade when
	 * `wp_get_attachment_metadata()` is false, so an attachment with no
	 * metadata does hide on an unsupported post type. That is a quirk of the
	 * early return, not intent, and is not reproduced.
	 *
	 * Note this answers "is the position hidden", not "is anything being
	 * suppressed" — it does not test for a block theme, so on a classic theme
	 * it can return true while nothing is filtered.
	 *
	 * @param int|null $post_id Post to check. Defaults to the current post.
	 * @return bool
	 */
	public static function is_hidden( $post_id = null ) {
		/*
		 * The same pair of conditionals the classic function uses. Equivalent
		 * to `is_singular()` — `WP_Query` sets `is_single` for attachment pages
		 * too — and written this way only to read against the original.
		 * Attachments are excluded by the post-type gate below, as in classic.
		 */
		if ( ! is_single() && ! is_page() ) {
			return false;
		}

		$post_id = null === $post_id ? get_the_ID() : (int) $post_id;
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

		return self::resolve_hidden( $post_id );
	}

	/**
	 * Resolve the position for a post, past the context guards.
	 *
	 * Deliberately not memoized. Every lookup below reads the object cache, and
	 * a per-request memo would go stale for anything that changes the position
	 * mid-request — the block editor's own render endpoint among them.
	 *
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	private static function resolve_hidden( $post_id ) {
		/*
		 * The supported post types. Read through the theme's own filter, which
		 * newspack-newsletters and newspack-listings both add to from their own
		 * init, so the list is right whichever theme is active.
		 */
		$supported = apply_filters( 'newspack_theme_featured_image_post_types', [ 'post', 'page' ] );
		if ( ! in_array( get_post_type( $post_id ), (array) $supported, true ) ) {
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
	 * This discards markup core has already built, which means the featured
	 * image has already claimed the one-shot `fetchpriority="high"` slot and
	 * incremented the content media count in
	 * `wp_get_loading_optimization_attributes()`. So on a post whose image is
	 * hidden, the first visible in-content image does not get that hint.
	 * `pre_render_block` would avoid the work but has no per-block-name
	 * variant, so it would cost a callback on every block on every page.
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
	 * Drop the container around a suppressed featured image.
	 *
	 * The block theme's single templates wrap the image and its caption in a
	 * container carrying vertical spacing of its own, so suppressing only the
	 * contents would leave that spacing behind as a gap.
	 *
	 * The container is recognized structurally, from the parsed block, not from
	 * its name, its class or what it rendered:
	 *
	 * - not by name, because the templates use a group in five places and a
	 *   column in the sixth, and a block's name is editable in the Site Editor,
	 *   so a publisher's own "Post Image" group must never be dropped for
	 *   sharing a label;
	 * - not by what it rendered, because output can be invisible and still
	 *   matter. An ad slot renders a bare `<div id="div-gpt-ad-…">`, an embed
	 *   loader renders only a script, and both would read as empty. Dropping
	 *   the container would take them with it.
	 *
	 * So a container qualifies only when every block beneath it is one of
	 * `IMAGE_BOX_BLOCKS` and one of them is the featured image. That is why the
	 * `<main>` group wrapping the whole article is safe: it holds
	 * `core/post-content`, so it never qualifies, whatever it rendered.
	 *
	 * Known limitation: `core/pattern`, `core/block` and `core/template-part`
	 * are resolved inside their own render callbacks, not in the parse tree
	 * (`resolve_pattern_blocks()` runs only for the REST controllers), so an
	 * image moved inside one of those is still suppressed but its container is
	 * not recognized and its gap remains. That fails in the safe direction:
	 * nothing of the publisher's is ever removed.
	 *
	 * @param string $block_content Rendered block.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function filter_image_wrapper( $block_content, $block ) {
		if ( ! wp_is_block_theme() ) {
			return $block_content;
		}

		// Structural and database-free, so it runs before the position lookup.
		if ( ! self::is_image_box( $block ) ) {
			return $block_content;
		}

		if ( ! self::is_hidden() ) {
			return $block_content;
		}

		return '';
	}

	/**
	 * Whether a parsed block holds the featured image and nothing else.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function is_image_box( $block ) {
		if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return false;
		}

		$holds_the_image = false;

		foreach ( $block['innerBlocks'] as $inner ) {
			$name = isset( $inner['blockName'] ) ? $inner['blockName'] : null;

			/*
			 * `parse_blocks()` emits nameless nodes for the markup between
			 * blocks. Whitespace is just the template's indentation; anything
			 * else is content, and content means the container stays.
			 */
			if ( null === $name ) {
				if ( '' !== trim( isset( $inner['innerHTML'] ) ? $inner['innerHTML'] : '' ) ) {
					return false;
				}
				continue;
			}

			if ( ! in_array( $name, self::IMAGE_BOX_BLOCKS, true ) ) {
				return false;
			}

			if ( 'core/post-featured-image' === $name ) {
				$holds_the_image = true;
				continue;
			}

			if ( 'core/group' === $name || 'core/column' === $name ) {
				if ( ! self::is_image_box( $inner ) ) {
					return false;
				}
				$holds_the_image = true;
			}
		}

		return $holds_the_image;
	}
}

Featured_Image_Position::init();
