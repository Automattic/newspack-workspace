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
 * Two blocks therefore have to render nothing: the image, and Newspack's
 * caption block, which draws its caption and photo credit from the attachment
 * and so survives the image's removal.
 *
 * The container around them needs no help. Every single template wraps the
 * image in a block carrying `className: "post-image"`, and the theme already
 * collapses that container when it holds no image — `src/scss/_base.scss`:
 *
 *     .post-image {
 *         &:empty,
 *         &:not(:has(.wp-block-post-featured-image)) { display: none; }
 *     }
 *
 * That rule is what keeps a post with no thumbnail at all from showing an empty
 * box with vertical spacing, and a hidden image reaches exactly the same state:
 * once the image block renders nothing, `.wp-block-post-featured-image` is
 * absent and the container collapses, margins included. Removing the container
 * server-side as well was tried and dropped — it duplicated this rule, and the
 * structural test it needed could delete markup a publisher had put in the
 * container by hand, which is a worse failure than the gap it prevented.
 *
 * The one case the CSS does not cover is a custom template whose container does
 * not carry `post-image`. That container keeps its spacing, exactly as it
 * already does today for a post with no thumbnail.
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
	 * Every position the classic theme recognizes, plus the empty string for
	 * "follow the site default". Only `hidden` changes anything on a block
	 * theme, but the others have to stay storable: the same meta drives the
	 * classic theme, and a site can switch back.
	 *
	 * @var string[]
	 */
	const POSITIONS = [ '', 'large', 'small', 'behind', 'beside', 'above', self::HIDDEN ];

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'after_switch_theme', [ __CLASS__, 'migrate_position_defaults' ], 10, 2 );
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
		add_filter( 'render_block_newspack/featured-image-caption', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
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
	 * Modeled on `Post_Date::migrate_date_settings()`, diverging in two ways:
	 * the guard below reads the stored option rather than `get_theme_mod()`, and
	 * the old theme is optional here rather than required.
	 *
	 * WordPress passes a `WP_Theme` in both branches of `check_theme_switched()`
	 * — including when the old theme's files are gone, where the object exists
	 * but `exists()` is false and `get_stylesheet()` still yields the slug, so
	 * its stored mods are still readable. Core's "the parameters differ" note is
	 * about what `$old_name` holds in that case, not about how many arguments
	 * arrive. The second parameter is optional here purely as cheap
	 * defensiveness, alongside the `instanceof` guard.
	 *
	 * @param string         $old_name  Name of the previous theme.
	 * @param \WP_Theme|null $old_theme Previous theme object.
	 */
	public static function migrate_position_defaults( $old_name, $old_theme = null ) {
		if ( ! $old_theme instanceof \WP_Theme ) {
			return;
		}

		$old_mods = get_option( 'theme_mods_' . $old_theme->get_stylesheet(), [] );
		if ( ! is_array( $old_mods ) ) {
			return;
		}

		/*
		 * Read the new theme's stored row rather than asking `get_theme_mod()`,
		 * which runs the `theme_mod_{$name}` filters: a theme or plugin that
		 * hardcodes a value through one of those would make the guard believe a
		 * value is stored, skip the copy, and lose the publisher's default — the
		 * very thing this exists to prevent. `get_option( 'stylesheet' )` is the
		 * same key `set_theme_mod()` writes to, and by the time this action
		 * fires it is already the new theme.
		 */
		$new_mods = get_option( 'theme_mods_' . get_option( 'stylesheet' ), [] );
		if ( ! is_array( $new_mods ) ) {
			$new_mods = [];
		}

		foreach ( self::THEME_MOD_KEYS as $key ) {
			if ( ! isset( $old_mods[ $key ] ) ) {
				continue;
			}

			/*
			 * Never overwrite a choice the new theme already carries — but a
			 * value it cannot recognize is not a choice, so it does not block
			 * the migration. Otherwise junk in the destination row would keep a
			 * publisher's real setting out forever.
			 */
			if ( isset( $new_mods[ $key ] ) && '' !== self::sanitize_position( $new_mods[ $key ] ) ) {
				continue;
			}

			/*
			 * And never carry a value the themes would not recognize. The
			 * Customizer does sanitize these — both settings run through
			 * `newspack_sanitize_feature_image_position()` — but it is not the
			 * only writer: the setup wizard's `sanitize_theme_mods()` is a
			 * pass-through and `wp theme mod set` writes verbatim, so a junk
			 * value can be sitting in the old row.
			 */
			$position = self::sanitize_position( $old_mods[ $key ] );
			if ( '' === $position ) {
				continue;
			}

			set_theme_mod( $key, $position );
		}
	}

	/**
	 * Keep a position to one the themes understand.
	 *
	 * An unrecognized value becomes '', which means "follow the site default".
	 * So it fails toward whatever the site chose, not toward showing the image:
	 * on a site whose default is `hidden` — the state the migration below
	 * deliberately carries across — '' resolves to hidden. That is the right
	 * direction either way, because it never invents a per-post decision.
	 *
	 * @param mixed $position Submitted value.
	 * @return string
	 */
	public static function sanitize_position( $position ) {
		return in_array( $position, self::POSITIONS, true ) ? $position : '';
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
	 * The narrow-image half of that same clause cannot affect this, because it
	 * is guarded by `'large' === $position`: a hidden position never reaches it.
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
		 *
		 * The one case this does not separate is a loop on a post's own page
		 * that includes that same post, where the card is the queried post and
		 * so loses its thumbnail. No shipped template or pattern does that.
		 */
		if ( (int) $post_id !== (int) get_queried_object_id() ) {
			return false;
		}

		return self::resolve_hidden( $post_id );
	}

	/**
	 * Resolve the position for a post, past the context guards.
	 *
	 * Deliberately not memoized: every lookup below reads the object cache, and
	 * the guards above mean this is reached only for the post actually being
	 * viewed, at most twice per request.
	 *
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	private static function resolve_hidden( $post_id ) {
		/*
		 * The supported post types. Read through the theme's own filter, which
		 * newspack-newsletters and newspack-listings both add to when they
		 * load, not gated on the active theme, so the list is right whichever
		 * theme is running.
		 */
		$supported = apply_filters( 'newspack_theme_featured_image_post_types', [ 'post', 'page' ] );
		if ( ! in_array( get_post_type( $post_id ), (array) $supported, true ) ) {
			return false;
		}

		/*
		 * Classic returns an empty position before it reads anything when the
		 * post has no thumbnail, so a site-wide `hidden` default never applies
		 * to a post with no image.
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
	 * Registered on both the image block and Newspack's caption block. The
	 * caption matters even though the theme's CSS collapses the container around
	 * it: a photo credit for an image nobody can see should not sit in the
	 * markup, and a custom template whose container lacks `post-image` would
	 * show it.
	 *
	 * This discards markup core has already built, which means the featured
	 * image has already claimed the one-shot `fetchpriority="high"` slot and
	 * incremented the content media count in
	 * `wp_get_loading_optimization_attributes()`. So on a post whose image is
	 * hidden, the first visible in-content image does not get that hint.
	 * `pre_render_block` would avoid that, at the cost of a callback on every
	 * block on every page and of hiding these blocks from other plugins'
	 * `render_block` callbacks; it is worth revisiting if the lost hint shows up
	 * in a real measurement.
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

		/*
		 * The block's own post, which is what core's render callback uses. Both
		 * blocks declare `postId` in `usesContext`, so this is populated.
		 */
		$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : null;

		if ( self::is_hidden( $post_id ) ) {
			return '';
		}

		return $block_content;
	}
}

Featured_Image_Position::init();
