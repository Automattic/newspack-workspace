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
	 * The post types this class registered the meta for, or null when it has
	 * not run or stood down.
	 *
	 * The editor gate reads this rather than re-deriving the decision, so the
	 * control can only ever appear where REST will accept what it writes. They
	 * used to be worked out independently at two different times, and could
	 * disagree: a post type added to the filter after `init` 20 would load the
	 * control for a key that was never registered. The publisher would then
	 * pick a position, save, get a 200, and lose it silently — `WP_REST_Meta_Fields::update_value()`
	 * iterates only registered fields, and the meta schema sets no
	 * `additionalProperties`, so an unknown key is dropped without an error.
	 *
	 * @var string[]|null
	 */
	private static $registered_for = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Priority 20, so a post type added to the filter from another plugin's own init is still seen.
		add_action( 'init', [ __CLASS__, 'register_meta' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
		add_action( 'after_switch_theme', [ __CLASS__, 'migrate_position_defaults' ], 10, 2 );
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
		add_filter( 'render_block_newspack/featured-image-caption', [ __CLASS__, 'suppress_when_hidden' ], 10, 3 );
	}

	/**
	 * The post types whose featured image can be positioned.
	 *
	 * Read through the classic theme's own filter, which newspack-newsletters
	 * and newspack-listings both add to when they load — before `init` — so the
	 * list is right whichever theme is active.
	 *
	 * @return string[]
	 */
	public static function get_supported_post_types() {
		/**
		 * Filters the post types whose featured image can be positioned.
		 *
		 * Declared by the classic theme, in
		 * `newspack_get_featured_image_post_types()`, which is not loaded on a
		 * block theme — so this is usually the only place the hook fires in the
		 * whole request, hence the full contract here. The exception is a block
		 * child of the classic parent, where both fire and this class stands
		 * down anyway.
		 *
		 * `array_values()` because a filter that unsets an entry leaves
		 * non-sequential keys, which `wp_json_encode()` then emits as an object
		 * rather than an array, and `postTypes.includes()` in the editor script
		 * would throw and take the whole Featured image panel with it.
		 *
		 * @param string[] $post_types Post type slugs. Default `[ 'post', 'page' ]`.
		 */
		return array_values( (array) apply_filters( 'newspack_theme_featured_image_post_types', [ 'post', 'page' ] ) );
	}

	/**
	 * Whether the plugin, rather than the classic theme, owns this setting.
	 *
	 * @return bool
	 */
	private static function owns_the_setting() {
		/*
		 * A hook probe rather than `function_exists`, because the question is
		 * not "is the classic theme loaded" but "is it registering this key".
		 * Those come apart in the direction that matters: a plugin doing
		 * `remove_action( 'init', 'newspack_register_meta' )` on a block child
		 * of the classic parent — precisely so something else can own the
		 * setting — leaves the function defined, so `function_exists` would
		 * make this stand down with nobody registering the key at all: no
		 * control, and stored `hidden` values still honored but uneditable.
		 * (`newspack_featured_image_position()` is a worse probe again, since
		 * the theme wraps it in its own `function_exists` guard and it is
		 * therefore designed to be replaced.)
		 *
		 * One window this cannot see: a removal between the theme's `init` 10
		 * and this class's `init` 20, on that same child-theme shape. The key
		 * is already registered by then, so the result is two controls rather
		 * than none. Contrived enough to document rather than code around.
		 *
		 * `false ===` is load-bearing. `has_action()` returns the priority, so
		 * a callback hooked at priority 0 yields int 0, and a loose `!` would
		 * read that as "nobody is registering it" and claim ownership while
		 * the classic theme was actively registering the key.
		 */
		return wp_is_block_theme() && false === has_action( 'init', 'newspack_register_meta' );
	}

	/**
	 * Expose the position to the block editor.
	 *
	 * Without this the meta is invisible to REST on a block theme and the editor
	 * can neither read nor write it.
	 *
	 * Gated so the classic theme keeps ownership where it has it. Core does not
	 * object to registering a key twice — `register_meta()` has no duplicate
	 * check and simply lets the last call's arguments win. Since the classic
	 * theme registers at `init` 10 and this runs at 20, the winner would be the
	 * plugin: its enum and default would start governing REST on a classic-theme
	 * site, refusing writes and nulling out reads for a theme this class is not
	 * meant to govern. The publisher would also get two controls stacked in the
	 * same panel.
	 *
	 * `wp_is_block_theme()` alone is not that test. A block child theme whose
	 * parent is the classic theme reports true, because `is_block_theme()` looks
	 * for `templates/index.html` in the stylesheet directory first and the child
	 * ships one — while the parent's `functions.php` still loads and still
	 * registers this key. `owns_the_setting()` therefore asks whether the
	 * classic theme is registering it, not merely which kind of theme is on.
	 */
	public static function register_meta() {
		if ( ! self::owns_the_setting() ) {
			// Record the stand-down too, so the readers below never see a
			// stale answer from an earlier call that did register.
			self::$registered_for = null;
			return;
		}

		$post_types = self::get_supported_post_types();

		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				[
					// A closed set, declared as one. With a bare `true` the schema is
					// any string, so an invalid value passes validation, is coerced to
					// '' by the sanitize callback and returns 200 — silently clearing a
					// stored `hidden` and putting the image back. The enum makes REST
					// answer 400 and leave the stored value alone.
					'show_in_rest'      => [
						'schema' => [
							'type' => 'string',
							'enum' => self::POSITIONS,
						],
					],
					'single'            => true,
					'type'              => 'string',
					'default'           => '',

					// Still the backstop for writes that do not come through REST.
					'sanitize_callback' => [ __CLASS__, 'sanitize_position' ],
					'auth_callback'     => [ __CLASS__, 'auth_callback' ],
				]
			);
		}

		self::$registered_for = $post_types;
	}

	/**
	 * Who may change a post's position.
	 *
	 * Core attaches this with six accepted arguments and asks about a specific
	 * user, so answer about that user rather than the current one. REST and the
	 * editor always ask about the current user, but `user_can()` does not.
	 *
	 * @param bool   $allowed  Whether the user can add the post meta.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id  Post ID.
	 * @param int    $user_id  User being asked about.
	 * @return bool
	 */
	public static function auth_callback( $allowed, $meta_key, $post_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Load the per-post control into the block editor.
	 *
	 * Only where the plugin owns the setting: the classic theme ships its own
	 * control, from `js/src/extend-featured-image-editor.js`, and two would
	 * stack up in the same panel.
	 */
	public static function enqueue_editor_assets() {
		// What was registered, not what would be registered if asked again now.
		if ( null === self::$registered_for ) {
			return;
		}

		$post_types = self::$registered_for;

		/*
		 * `get_current_screen()` lives in wp-admin and is not defined anywhere
		 * else, so a front-end block editor firing this action would fatal on
		 * the call rather than fall through the null check below. Guard the
		 * call, then treat a missing screen as "not a post editor" and bail,
		 * rather than guessing and loading the control with a post's defaults
		 * on something that may be a page.
		 */
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		$path = NEWSPACK_ABSPATH . 'dist/other-scripts/featured-image-position.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$handle = 'newspack-featured-image-position';
		$asset  = include NEWSPACK_ABSPATH . 'dist/other-scripts/featured-image-position.asset.php';

		wp_enqueue_script(
			$handle,
			plugins_url( 'dist/other-scripts/featured-image-position.js', NEWSPACK_PLUGIN_FILE ),
			$asset['dependencies'] ?? [],
			$asset['version'] ?? false,
			true
		);

		wp_localize_script( $handle, 'newspackFeaturedImagePosition', self::get_editor_config( $screen->post_type ) );
	}

	/**
	 * What the editor control needs to know, for one post type.
	 *
	 * Separate from the enqueue so it can be tested: the enqueue returns early
	 * when the built script is missing, which is how CI runs, so a test driving
	 * the enqueue would assert nothing.
	 *
	 * Strings only, deliberately. `wp_localize_script()` casts scalars, so a
	 * boolean false arrives as "" and a 0 arrives as "0" — which is truthy in
	 * JavaScript. The control compares `siteDefault` against `hiddenValue`
	 * rather than being handed a flag that could lie.
	 *
	 * `fallbackValue` is a last resort for the "Shown" option, used only when
	 * both the post and the site default are hidden. The control prefers a
	 * position the post already holds, then the site default, so it never pins a
	 * layout the publisher did not choose.
	 *
	 * @param string $post_type Post type being edited.
	 * @return array<string,mixed>
	 */
	public static function get_editor_config( $post_type ) {
		$is_page  = 'page' === $post_type;
		$fallback = $is_page ? 'small' : 'large';

		/*
		 * Clamped. The Customizer does sanitize these mods, through
		 * `newspack_sanitize_feature_image_position()`, but it is not the only
		 * writer: the setup wizard's `sanitize_theme_mods()` is a pass-through
		 * and `wp theme mod set` writes verbatim. An unrecognized value would
		 * otherwise become what the "Shown" option writes, which the REST enum
		 * then refuses — failing the whole post save, not just that field.
		 */
		$site_default = self::sanitize_position(
			$is_page
				? get_theme_mod( 'page_featured_image_default', 'small' )
				: get_theme_mod( 'featured_image_default', 'large' )
		);

		return [

			/*
			 * Only what was actually registered. An empty list where nothing
			 * was is the honest answer — handing back the supported types
			 * instead would name post types with no REST registration behind
			 * them, which is the silent-save-then-lose this record exists to
			 * prevent, reintroduced at the one place that could still do it.
			 */
			'postTypes'     => null === self::$registered_for ? [] : self::$registered_for,
			'siteDefault'   => '' === $site_default ? $fallback : $site_default,
			'fallbackValue' => $fallback,
			'hiddenValue'   => self::HIDDEN,

			// So the control can refuse to write a position REST would reject.
			'positions'     => self::POSITIONS,
		];
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
	 * Modeled on `Post_Date::migrate_date_settings()`, diverging in three ways:
	 * the guard below reads the stored option rather than `get_theme_mod()`, the
	 * old theme is optional here rather than required, and an unrecognized value
	 * is not treated as a choice. `Post_Date` needs none of that — its mods are
	 * booleans and integers, with no vocabulary to check against.
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
			 *
			 * `sanitize_position()` checks against `POSITIONS`, which is the
			 * per-post vocabulary and carries `''` where the Customizer's does
			 * not. Safe to borrow here because `''` reads as "no choice" under
			 * both, which is exactly how this guard treats it.
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
		if ( ! in_array( get_post_type( $post_id ), self::get_supported_post_types(), true ) ) {
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
