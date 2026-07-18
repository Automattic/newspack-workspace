<?php
/**
 * Newspack Content Gate.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Content_Gate_Advanced_Settings {
	/**
	 * Option prefix for content gate options.
	 */
	const OPTION_PREFIX = 'newspack_content_gate_';

	/**
	 * Feed restriction mode: truncate the body to the gate's teaser length.
	 */
	const FEED_MODE_TRUNCATE = 'truncate';

	/**
	 * Feed restriction mode: remove the restricted item from the feed entirely.
	 */
	const FEED_MODE_EXCLUDE = 'exclude';

	/**
	 * Feed restriction mode: leave the feed untouched (no restriction).
	 *
	 * Not a stored value — it is the resolved mode when restrict_feeds is off,
	 * and a value the `newspack_content_gate_feed_restriction_mode` filter may
	 * return to exempt a specific feed.
	 */
	const FEED_MODE_OFF = 'off';

	/**
	 * Default over-fetch multiplier for exclude-mode feeds: how many times the
	 * requested feed length to fetch so dropped restricted items can be
	 * back-filled with older unrestricted posts.
	 */
	const FEED_OVERFETCH_MULTIPLIER = 5;

	/**
	 * Absolute cap on the exclude-mode over-fetch, to bound feed query cost on
	 * sites with a large `posts_per_rss` or a large multiplier.
	 */
	const FEED_OVERFETCH_MAX = 100;

	/**
	 * Query var stashing the originally requested feed length across the
	 * over-fetch (pre_get_posts) → trim (the_posts) round trip.
	 */
	const FEED_TARGET_QUERY_VAR = 'newspack_feed_restriction_target';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'the_content_feed', [ __CLASS__, 'restrict_feed_content' ], PHP_INT_MAX );
		add_filter( 'the_excerpt_rss', [ __CLASS__, 'restrict_feed_excerpt' ], PHP_INT_MAX );
		// Runs at PHP_INT_MAX so the over-fetch reads posts_per_rss *after* every
		// other feed-query modifier has set it. Other pre_get_posts writers (e.g.
		// the RSS-Enhancements module's modify_feed_query) run at the default
		// priority 10 and overwrite posts_per_rss with the publisher's configured
		// item count; capturing the trim target before them would trim partner
		// feeds back to the stale default length even when nothing was restricted.
		add_action( 'pre_get_posts', [ __CLASS__, 'overfetch_restricted_feed' ], PHP_INT_MAX );
		add_filter( 'the_posts', [ __CLASS__, 'exclude_restricted_posts_from_feed' ], 10, 2 );
	}

	/**
	 * Get the advanced settings.
	 *
	 * @return array The advanced settings.
	 */
	public static function get_settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		// Cast each boolean option to int so consumers (including the React UI,
		// whose TS types declare these as boolean) don't misinterpret a stringy
		// '0' returned by get_option() as truthy.
		$settings = [
			'restrict_feeds'                 => (int) get_option( self::OPTION_PREFIX . 'restrict_feeds', 1 ),
			'feed_restriction_mode'          => self::sanitize_feed_mode( get_option( self::OPTION_PREFIX . 'feed_restriction_mode', self::FEED_MODE_EXCLUDE ) ),
			'newsletter_link_bypass_enabled' => (int) get_option( self::OPTION_PREFIX . 'newsletter_link_bypass_enabled', 0 ),
		];

		self::$settings = $settings;
		return self::$settings;
	}

	/**
	 * Normalize a stored feed restriction mode to a known value.
	 *
	 * Only truncate/exclude are storable; anything else (including a legacy or
	 * corrupt value) falls back to the exclude default, matching WC Memberships'
	 * out-of-the-box behaviour of dropping restricted items from feeds.
	 *
	 * @param mixed $mode Raw mode value.
	 *
	 * @return string
	 */
	private static function sanitize_feed_mode( mixed $mode ): string {
		return in_array( $mode, [ self::FEED_MODE_TRUNCATE, self::FEED_MODE_EXCLUDE ], true ) ? $mode : self::FEED_MODE_EXCLUDE;
	}

	/**
	 * Update the advanced settings.
	 *
	 * @param array $settings The advanced settings.
	 */
	public static function update_settings( $settings ) {
		if ( isset( $settings['restrict_feeds'] ) ) {
			update_option( self::OPTION_PREFIX . 'restrict_feeds', boolval( $settings['restrict_feeds'] ) ? 1 : 0, false );
		}
		if ( isset( $settings['feed_restriction_mode'] ) ) {
			update_option( self::OPTION_PREFIX . 'feed_restriction_mode', self::sanitize_feed_mode( $settings['feed_restriction_mode'] ), false );
		}
		if ( isset( $settings['newsletter_link_bypass_enabled'] ) ) {
			update_option( self::OPTION_PREFIX . 'newsletter_link_bypass_enabled', boolval( $settings['newsletter_link_bypass_enabled'] ) ? 1 : 0, false );
		}
		self::reset_cache();
		return self::get_settings();
	}

	/**
	 * Reset the settings cache.
	 */
	public static function reset_cache() {
		self::$settings = null;
	}

	/**
	 * Resolve the effective feed restriction mode for the current request.
	 *
	 * Collapses the master `restrict_feeds` toggle and the stored mode into a
	 * single effective mode, then exposes it to a filter so a feed can be made
	 * more (or less) restrictive than the front-end teaser without code changes
	 * to the gate — the parity equivalent of WC Memberships'
	 * `wc_memberships_is_feed_restricted` filter. The filter overrides the master
	 * toggle in both directions: it can return FEED_MODE_OFF to exempt a feed
	 * even when `restrict_feeds` is on, or a restricting mode to gate a feed even
	 * when `restrict_feeds` is off.
	 *
	 * @param mixed $context Optional context passed to the filter. Its type
	 *                       depends on the caller: the exclude path passes the
	 *                       WP_Query being filtered, while the truncate paths pass
	 *                       the WP_Post being rendered. Filter callbacks that read
	 *                       $context must type-check it (e.g.
	 *                       `$context instanceof \WP_Post`) before using it.
	 *
	 * @return string One of FEED_MODE_OFF, FEED_MODE_TRUNCATE, FEED_MODE_EXCLUDE.
	 */
	public static function get_feed_restriction_mode( $context = null ) {
		$settings = self::get_settings();
		$mode     = empty( $settings['restrict_feeds'] ) ? self::FEED_MODE_OFF : $settings['feed_restriction_mode'];

		/**
		 * Filters the effective feed restriction mode.
		 *
		 * Return FEED_MODE_OFF to leave a feed untouched, FEED_MODE_TRUNCATE to
		 * truncate restricted bodies to the gate teaser, or FEED_MODE_EXCLUDE to
		 * drop restricted items from the feed entirely. Overrides the
		 * `restrict_feeds` toggle in both directions.
		 *
		 * @param string $mode    Effective mode ('off'|'truncate'|'exclude').
		 * @param mixed  $context The WP_Query (exclude path) or WP_Post (truncate
		 *                        paths) in scope; type-check before use.
		 */
		$filtered = apply_filters( 'newspack_content_gate_feed_restriction_mode', $mode, $context );

		// An unrecognized filter return is ignored in favour of the resolved mode
		// rather than disabling restriction — failing open here would leak full
		// premium content to the feed on a developer typo. $mode is always valid
		// (FEED_MODE_OFF, or the value sanitized by get_settings()).
		return in_array( $filtered, [ self::FEED_MODE_OFF, self::FEED_MODE_TRUNCATE, self::FEED_MODE_EXCLUDE ], true ) ? $filtered : $mode;
	}

	/**
	 * Remove restricted posts from RSS feed queries when the feed mode is
	 * "exclude", matching WC Memberships' default of keeping restricted content
	 * out of feeds entirely (not just blanking the body).
	 *
	 * Runs on the `the_posts` filter rather than a `post__not_in` on
	 * `pre_get_posts` because gate restriction is rule-based and per-reader:
	 * there is no precomputed list of restricted IDs to exclude at the SQL level,
	 * but every fetched post can be cheaply evaluated with `is_post_restricted()`.
	 *
	 * To match WC Memberships' behaviour of back-filling the feed up to
	 * `posts_per_rss` with older unrestricted posts (WCM excludes at the SQL
	 * level, before the LIMIT), `overfetch_restricted_feed()` inflates the feed
	 * query so this filter has surplus posts to draw from; here we drop the
	 * restricted ones and trim back to the originally requested length. The
	 * over-fetch is bounded (see FEED_OVERFETCH_MAX), so a feed whose recent
	 * posts are overwhelmingly restricted can still come back short.
	 *
	 * @param \WP_Post[]     $posts    Posts for the current query.
	 * @param \WP_Query|null $wp_query The query being filtered.
	 *
	 * @return \WP_Post[]
	 */
	public static function exclude_restricted_posts_from_feed( $posts, $wp_query = null ) {
		if ( empty( $posts ) || ! $wp_query instanceof \WP_Query || ! $wp_query->is_feed() ) {
			return $posts;
		}
		// Only post feeds are handled. On a comment feed the comments are already
		// queried from $posts[0] before this filter runs, so dropping the post
		// would not restrict anything and would only blank the feed's title/link.
		if ( $wp_query->is_comment_feed() ) {
			return $posts;
		}
		if ( self::FEED_MODE_EXCLUDE !== self::get_feed_restriction_mode( $wp_query ) ) {
			return $posts;
		}
		$visible = array_values(
			array_filter(
				$posts,
				function ( $post ) {
					return ! Content_Gate::is_post_restricted( $post->ID );
				}
			)
		);
		// Trim the over-fetched page (see overfetch_restricted_feed) back to the
		// originally requested feed length. Absent (0) when nothing over-fetched.
		$target = (int) $wp_query->get( self::FEED_TARGET_QUERY_VAR );
		if ( $target > 0 ) {
			$visible = array_slice( $visible, 0, $target );
		}
		return $visible;
	}

	/**
	 * Over-fetch exclude-mode feed queries so restricted items can be back-filled
	 * with older unrestricted posts (see exclude_restricted_posts_from_feed).
	 *
	 * A feed's `posts_per_page` is derived from `posts_per_rss` in the
	 * `is_feed` branch of WP_Query::get_posts(), which runs after `pre_get_posts`
	 * — so inflating `posts_per_rss` here makes WP fetch a larger page, and the
	 * original length is stashed for the trim step. Hooked at PHP_INT_MAX (see
	 * init) so it reads the length *after* other feed-query modifiers have set it,
	 * capturing the publisher's real feed length rather than a stale default.
	 *
	 * Exclude is the site-wide default, so this over-fetch runs on every main feed
	 * request whenever exclude is active — including on sites that restrict
	 * nothing — fetching up to min(posts_per_rss × multiplier, FEED_OVERFETCH_MAX)
	 * posts and evaluating is_post_restricted() on each in the_posts. Feed output
	 * is normally page-cached, so only the first uncached request after a purge
	 * (and aggregators hitting many category/author/tag variants) pays the
	 * multiplier; the cost is bounded by FEED_OVERFETCH_MAX and the multiplier is
	 * filterable.
	 *
	 * @param \WP_Query $wp_query The query about to run.
	 */
	public static function overfetch_restricted_feed( $wp_query ) {
		// Only the main post feed is over-fetched. Secondary feed queries still
		// have restricted items dropped by the_posts, just without back-fill.
		if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_feed() || ! $wp_query->is_main_query() ) {
			return;
		}
		// Comment feeds derive their LIMIT from the posts_per_rss option directly,
		// so inflating the query var would not affect them — skip the wasted work.
		if ( $wp_query->is_comment_feed() ) {
			return;
		}
		// Over-fetching inflates core's offset (paged - 1) * posts_per_page, which
		// would make paginated feeds skip unrestricted posts. Back-fill only the
		// first page; later pages fall back to plain drop-without-back-fill.
		if ( (int) $wp_query->get( 'paged' ) > 1 ) {
			return;
		}
		if ( self::FEED_MODE_EXCLUDE !== self::get_feed_restriction_mode( $wp_query ) ) {
			return;
		}
		$requested = (int) $wp_query->get( 'posts_per_rss' );
		if ( $requested <= 0 ) {
			$requested = (int) get_option( 'posts_per_rss', 10 );
		}
		if ( $requested <= 0 ) {
			return;
		}

		/**
		 * Filters the exclude-mode feed over-fetch multiplier — how many times
		 * `posts_per_rss` to fetch so restricted items dropped from the feed can
		 * be back-filled with older unrestricted posts. The realized over-fetch
		 * is still capped at FEED_OVERFETCH_MAX.
		 *
		 * @param int       $multiplier Over-fetch multiplier (>= 1).
		 * @param \WP_Query  $wp_query  The feed query being adjusted.
		 */
		$multiplier = (int) apply_filters( 'newspack_content_gate_feed_overfetch_multiplier', self::FEED_OVERFETCH_MULTIPLIER, $wp_query );
		$multiplier = max( 1, $multiplier );
		$overfetch  = min( $requested * $multiplier, self::FEED_OVERFETCH_MAX );
		if ( $overfetch <= $requested ) {
			return;
		}

		$wp_query->set( 'posts_per_rss', $overfetch );
		$wp_query->set( self::FEED_TARGET_QUERY_VAR, $requested );
	}

	/**
	 * Truncate post content in RSS feeds unless the feed mode is "off".
	 *
	 * @param string $content Feed item content.
	 *
	 * @return string
	 */
	public static function restrict_feed_content( $content ) {
		return self::maybe_truncate_feed_string( $content );
	}

	/**
	 * Truncate post excerpt in RSS feeds unless the feed mode is "off".
	 *
	 * @param string $excerpt Feed item excerpt.
	 *
	 * @return string
	 */
	public static function restrict_feed_excerpt( $excerpt ) {
		return self::maybe_truncate_feed_string( $excerpt );
	}

	/**
	 * Replace a feed string (content or excerpt) with the gate teaser when the
	 * current post is restricted and the feed mode is not "off".
	 *
	 * Uses the gate's excerpt settings (<!--more--> tag or paragraph count) to
	 * match what logged-out visitors see on the front-end. The inline gate HTML
	 * is intentionally omitted — feeds should not contain login prompts. In
	 * "exclude" mode restricted posts are already gone from the loop; truncation
	 * remains a backstop so a restricted body can never leak in full.
	 *
	 * @param string $feed_string Feed item content or excerpt.
	 *
	 * @return string
	 */
	private static function maybe_truncate_feed_string( string $feed_string ): string {
		$post = get_post();
		if ( ! $post || self::FEED_MODE_OFF === self::get_feed_restriction_mode( $post ) ) {
			return $feed_string;
		}
		if ( ! Content_Gate::is_post_restricted( $post->ID ) ) {
			return $feed_string;
		}
		return Content_Gate::get_restricted_post_excerpt_for_gate( $post, Content_Gate::get_gate_layout_id( $post->ID ) );
	}
}
Content_Gate_Advanced_Settings::init();
