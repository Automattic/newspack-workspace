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
	 * Query var stashing the inflated feed length, so the trim step can tell
	 * whether the page it received is really the one this class inflated.
	 */
	const FEED_OVERFETCH_QUERY_VAR = 'newspack_feed_restriction_overfetch';

	/**
	 * Query var stashing the feed length the query asked for before the
	 * over-fetch inflated it.
	 *
	 * Distinct from FEED_TARGET_QUERY_VAR, which verify_feed_overfetch_limit()
	 * clears to abandon the trim: this one is never cleared, so the inflated
	 * length can always be restored on the query object (see
	 * restore_feed_query_length) even when the page itself is left alone.
	 */
	const FEED_REQUESTED_LENGTH_QUERY_VAR = 'newspack_feed_restriction_requested_length';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Whether a paginated feed page was emptied by exclude-mode filtering, so
	 * the 404 core would otherwise issue for it can be suppressed.
	 *
	 * Cleared as soon as prevent_excluded_feed_404() has read it: a request only
	 * runs `handle_404()` once, and leaving the flag raised would let one emptied
	 * feed page preempt the 404 for every later empty query in the same process.
	 *
	 * @var bool
	 */
	private static $emptied_paged_feed = false;

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
		// Also at PHP_INT_MAX, so it sees the LIMIT after every other post_limits
		// writer (see verify_feed_overfetch_limit).
		add_filter( 'post_limits', [ __CLASS__, 'verify_feed_overfetch_limit' ], PHP_INT_MAX, 2 );
		add_filter( 'the_posts', [ __CLASS__, 'exclude_restricted_posts_from_feed' ], 10, 2 );
		add_filter( 'pre_handle_404', [ __CLASS__, 'prevent_excluded_feed_404' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_defeat_feed_cache' ] );
	}

	/**
	 * Whether anything on this site can restrict a post at all.
	 *
	 * `newspack_is_post_restricted` — the contract every feed path below relies
	 * on — is only ever answered "yes" by Content_Restriction_Control (which
	 * needs at least one published gate) or by WooCommerce Memberships. Without
	 * either, feed restriction is guaranteed to be a no-op, and doing the work
	 * anyway would make every Newspack site pay for the over-fetch and a
	 * per-item restriction evaluation on every uncached feed request — including
	 * sites that have never opened Access Control and have no UI to turn it off
	 * (the wizard only registers behind the NEWSPACK_CONTENT_GATES constant).
	 *
	 * Not memoized: the gate lookup itself is cached by Content_Gate::get_gates(),
	 * so a second memo here would only add a value that can go stale against the
	 * cache it was derived from.
	 *
	 * @return bool
	 */
	private static function has_restriction_source(): bool {
		// Same arguments as Content_Restriction_Control::get_post_gates(), so
		// the two share one cached query.
		$has_restriction_source = Memberships::is_active()
			|| ! empty( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish', false ) );

		/**
		 * Filters whether anything on this site can restrict a post.
		 *
		 * The feed hooks short-circuit entirely when this is false, so code that
		 * answers `newspack_is_post_restricted` on its own — a publisher plugin
		 * restricting posts without publishing a gate or activating Memberships —
		 * must return true here, or its restricted posts ship in full in the feed.
		 *
		 * @param bool $has_restriction_source Whether a first-party restriction source was detected.
		 */
		return (bool) apply_filters( 'newspack_content_gate_has_restriction_source', $has_restriction_source );
	}

	/**
	 * Build the context passed to the feed restriction mode filter.
	 *
	 * Always the same shape regardless of which path resolved the mode, so a
	 * callback can inspect the query (the natural way to write a per-feed
	 * override) without having to know whether it was invoked from the query
	 * filter or while rendering an item.
	 *
	 * @param \WP_Query|null $wp_query The feed query in scope, if known.
	 * @param \WP_Post|null  $post     The post being rendered, if any.
	 *
	 * @return array{query:\WP_Query|null,post:\WP_Post|null}
	 */
	private static function get_feed_filter_context( $wp_query = null, $post = null ): array {
		if ( ! $wp_query instanceof \WP_Query && isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$wp_query = $GLOBALS['wp_query'];
		}
		return [
			'query' => $wp_query instanceof \WP_Query ? $wp_query : null,
			'post'  => $post instanceof \WP_Post ? $post : null,
		];
	}

	/**
	 * Whether this feed response varies by reader, i.e. carries a gate bypass
	 * grant that changes which items are in it.
	 *
	 * Covers every reader-varying access grant in this subsystem: both newsletter
	 * bypass cookies and the IP/institution grant. Cookie *presence* is enough —
	 * an invalid cookie is not a leak, but it costs nothing to treat it as one,
	 * and verifying would cost an HMAC.
	 *
	 * @return bool
	 */
	public static function feed_response_varies_by_reader(): bool {
		if ( ! is_feed() || ! self::has_restriction_source() ) {
			return false;
		}
		if ( self::FEED_MODE_OFF === self::get_feed_restriction_mode( self::get_feed_filter_context() ) ) {
			return false;
		}
		$bypass_cookies = [
			Newsletters_Access::COOKIE_NAME,
			Newsletters_Access::SINGLE_POST_COOKIE_NAME,
			Content_Gate\IP_Access_Rule::COOKIE_NAME,
		];
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		return ! empty( array_intersect( $bypass_cookies, array_keys( $_COOKIE ) ) );
	}

	/**
	 * Defeat page caching on feed requests carrying a gate bypass cookie.
	 *
	 * In exclude mode the *membership* of the feed varies per reader — a reader
	 * holding a bypass cookie sees premium items an anonymous reader does not —
	 * so a cached copy of that response would leak the whole premium headline
	 * set, not just one teaser. Caches are expected to skip requests carrying
	 * `wp_`-prefixed cookies anyway; this makes the guarantee independent of that
	 * naming convention.
	 */
	public static function maybe_defeat_feed_cache() {
		if ( ! self::feed_response_varies_by_reader() ) {
			return;
		}
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();
	}

	/**
	 * Get the advanced settings.
	 *
	 * @return array The advanced settings.
	 */
	public static function get_settings(): array {
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
		return in_array( $mode, self::get_feed_restriction_modes(), true ) ? $mode : self::FEED_MODE_EXCLUDE;
	}

	/**
	 * The storable feed restriction modes.
	 *
	 * Single source for the REST schema's enum, the storage sanitizer and the
	 * wizard's select control (see get_feed_restriction_mode_options).
	 *
	 * @return string[]
	 */
	public static function get_feed_restriction_modes(): array {
		return [ self::FEED_MODE_EXCLUDE, self::FEED_MODE_TRUNCATE ];
	}

	/**
	 * The storable feed restriction modes as select-control options.
	 *
	 * Each label is a self-contained sentence rather than a fragment completing
	 * the control's label, so translators get the whole statement and can order
	 * its parts as their language requires.
	 *
	 * @return array[] Array of [ 'value' => string, 'label' => string ].
	 */
	public static function get_feed_restriction_mode_options(): array {
		$labels = [
			self::FEED_MODE_EXCLUDE  => __( 'Remove restricted articles from the feed', 'newspack-plugin' ),
			self::FEED_MODE_TRUNCATE => __( 'Keep restricted articles in the feed, showing only the teaser', 'newspack-plugin' ),
		];
		return array_map(
			function( $mode ) use ( $labels ) {
				return [
					'value' => $mode,
					'label' => $labels[ $mode ],
				];
			},
			self::get_feed_restriction_modes()
		);
	}

	/**
	 * Update the advanced settings.
	 *
	 * @param array $settings The advanced settings.
	 *
	 * @return array The stored settings after the update.
	 */
	public static function update_settings( $settings ): array {
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
	 * @param array $context Context for the filter, as built by
	 *                       get_feed_filter_context(): always a
	 *                       `[ 'query' => \WP_Query|null, 'post' => \WP_Post|null ]`
	 *                       array, whichever path resolved the mode. 'post' is
	 *                       null outside item rendering.
	 *
	 * @return string One of FEED_MODE_OFF, FEED_MODE_TRUNCATE, FEED_MODE_EXCLUDE.
	 */
	public static function get_feed_restriction_mode( $context = [] ): string {
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
		 * The context shape is stable across every path that resolves the mode
		 * (query filtering and item rendering alike), so a callback that keys off
		 * the query applies consistently — including to the truncation backstop.
		 *
		 * @param string $mode    Effective mode ('off'|'truncate'|'exclude').
		 * @param array  $context [ 'query' => \WP_Query|null, 'post' => \WP_Post|null ].
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
		// The trim target is only ever set by an over-fetch this class performed,
		// so it is honoured before (and independently of) resolving the mode
		// again: if the two resolutions ever disagreed — a filter registered
		// between pre_get_posts and the_posts, or one keyed on request state that
		// settles in between — skipping the trim would ship an inflated feed of up
		// to FEED_OVERFETCH_MAX items.
		$target  = (int) $wp_query->get( self::FEED_TARGET_QUERY_VAR );
		$visible = $posts;
		if ( self::has_restriction_source() && self::FEED_MODE_EXCLUDE === self::get_feed_restriction_mode( self::get_feed_filter_context( $wp_query ) ) ) {
			$visible = array_values(
				array_filter(
					$posts,
					function ( $post ) {
						return ! Content_Gate::is_post_restricted( $post->ID );
					}
				)
			);
			if ( empty( $visible ) && $wp_query->is_main_query() && (int) $wp_query->get( 'paged' ) > 1 ) {
				// Core 404s a paged query with no posts; a feed page emptied by
				// restriction should stay a valid, empty feed page instead.
				self::$emptied_paged_feed = true;
			}
		}
		if ( $target > 0 ) {
			$visible = array_slice( $visible, 0, $target );
		}
		// Independent of the trim: the over-fetch inflated the query object even
		// on the branch where verify_feed_overfetch_limit() abandoned the trim, so
		// the length has to be restored either way.
		$requested = (int) $wp_query->get( self::FEED_REQUESTED_LENGTH_QUERY_VAR );
		if ( $requested > 0 ) {
			self::restore_feed_query_length( $wp_query, $requested );
		}
		return $visible;
	}

	/**
	 * Undo the over-fetch on the query object once its page has come back.
	 *
	 * `set_found_posts()` computed `max_num_pages` from the inflated page size
	 * before `the_posts` ran, and both `posts_per_rss` and the `posts_per_page`
	 * core copies it into for feed queries still hold the inflated value — all
	 * readable for the rest of the request (custom feed templates, SEO plugins
	 * emitting `<atom:link rel="next">`, third-party pagination code, which reads
	 * `posts_per_page`). Restoring the length the query asked for leaves the query
	 * object exactly as it would have been had this class never over-fetched.
	 *
	 * @param \WP_Query $wp_query  The feed query.
	 * @param int       $requested The feed length before the over-fetch.
	 */
	private static function restore_feed_query_length( \WP_Query $wp_query, int $requested ) {
		$wp_query->set( 'posts_per_rss', $requested );
		$wp_query->set( 'posts_per_page', $requested );
		if ( $wp_query->found_posts ) {
			$wp_query->max_num_pages = (int) ceil( $wp_query->found_posts / $requested );
		}
	}

	/**
	 * Drop the trim target if a third party replaced the LIMIT this class set.
	 *
	 * `post_limits` fires inside WP_Query::get_posts(), i.e. after the over-fetch
	 * ran on `pre_get_posts`, so a plugin that sets feed length there (an older
	 * but real idiom) has the last word on the page size. Trimming that page back
	 * to `posts_per_rss` would silently shorten their feed, so the trim only
	 * survives while the final LIMIT is still the inflated one. Abandoning the
	 * trim leaves the page alone but not the query object: the length is still
	 * restored from FEED_REQUESTED_LENGTH_QUERY_VAR, which this never clears.
	 *
	 * Core applies one more filter after this one, `post_limits_request`, so a
	 * rewrite there is not covered. It is documented as being for caching plugins
	 * and is not the idiom feed-length plugins use.
	 *
	 * @param string         $limits   The LIMIT clause.
	 * @param \WP_Query|null $wp_query The query being built.
	 *
	 * @return string
	 */
	public static function verify_feed_overfetch_limit( $limits, $wp_query = null ) {
		if ( ! $wp_query instanceof \WP_Query || (int) $wp_query->get( self::FEED_TARGET_QUERY_VAR ) <= 0 ) {
			return $limits;
		}
		$overfetch = (int) $wp_query->get( self::FEED_OVERFETCH_QUERY_VAR );
		if ( ! preg_match( '/LIMIT\s+(?:\d+\s*,\s*)?(\d+)/i', (string) $limits, $matches ) || (int) $matches[1] !== $overfetch ) {
			$wp_query->set( self::FEED_TARGET_QUERY_VAR, 0 );
		}
		return $limits;
	}

	/**
	 * Keep a paginated feed page that exclusion emptied out of a 404.
	 *
	 * `WP::handle_404()` only exempts feeds from the 404 in its `! is_paged()`
	 * branch, so a page 2+ whose entire window happened to be restricted would
	 * now 404 where it previously returned a short feed page. Back-fill is
	 * deliberately limited to page 1 (over-fetching would skew core's offset), so
	 * this is the counterpart that keeps later pages behaving as before.
	 *
	 * @param bool           $preempt  Whether to short-circuit core's 404 handling.
	 * @param \WP_Query|null $wp_query The main query.
	 *
	 * @return bool
	 */
	public static function prevent_excluded_feed_404( $preempt, $wp_query = null ) {
		if ( $preempt || ! self::$emptied_paged_feed ) {
			return $preempt;
		}
		// One-shot: the flag answers for the page that raised it and nothing else.
		self::$emptied_paged_feed = false;
		if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_feed() || ! empty( $wp_query->posts ) ) {
			return $preempt;
		}
		status_header( 200 );
		return true;
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
	 * request whenever exclude is active — but only on sites where something can
	 * actually restrict a post (see has_restriction_source), fetching up to
	 * min(posts_per_rss × multiplier, FEED_OVERFETCH_MAX) posts and evaluating
	 * is_post_restricted() on each in the_posts. Feed output is normally
	 * page-cached, so only the first uncached request after a purge (and
	 * aggregators hitting many category/author/tag variants) pays the multiplier;
	 * the cost is bounded by FEED_OVERFETCH_MAX and the multiplier is filterable.
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
		// Last of the guards: the only one that can cost a query.
		if ( ! self::has_restriction_source() ) {
			return;
		}
		if ( self::FEED_MODE_EXCLUDE !== self::get_feed_restriction_mode( self::get_feed_filter_context( $wp_query ) ) ) {
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
		$wp_query->set( self::FEED_REQUESTED_LENGTH_QUERY_VAR, $requested );
		$wp_query->set( self::FEED_OVERFETCH_QUERY_VAR, $overfetch );
	}

	/**
	 * Truncate post content in RSS feeds unless the feed mode is "off".
	 *
	 * @param string $content Feed item content.
	 *
	 * @return string
	 */
	public static function restrict_feed_content( $content ): string {
		// Cast at the boundary: this is a filter value, so a lower-priority
		// callback may have returned null (or anything else) before us, and
		// maybe_truncate_feed_string() takes a declared string.
		return self::maybe_truncate_feed_string( (string) $content );
	}

	/**
	 * Truncate post excerpt in RSS feeds unless the feed mode is "off".
	 *
	 * @param string $excerpt Feed item excerpt.
	 *
	 * @return string
	 */
	public static function restrict_feed_excerpt( $excerpt ): string {
		return self::maybe_truncate_feed_string( (string) $excerpt );
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
		if ( ! $post || ! self::has_restriction_source() ) {
			return $feed_string;
		}
		if ( self::FEED_MODE_OFF === self::get_feed_restriction_mode( self::get_feed_filter_context( null, $post ) ) ) {
			return $feed_string;
		}
		if ( ! Content_Gate::is_post_restricted( $post->ID ) ) {
			return $feed_string;
		}
		return Content_Gate::get_restricted_post_excerpt_for_gate( $post, Content_Gate::get_gate_layout_id( $post->ID ) );
	}
}
Content_Gate_Advanced_Settings::init();
