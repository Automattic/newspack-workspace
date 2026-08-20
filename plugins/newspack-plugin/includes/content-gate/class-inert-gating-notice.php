<?php
/**
 * Newspack Inert Gating Notice.
 *
 * Warns an administrator when Access Control is configured but not applying,
 * which happens whenever Audience Management is switched off on a site that
 * has gates, premium newsletters or block-level access rules set up.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent admin notice for configured-but-inert Access Control.
 *
 * Switching Audience Management off makes every Access Control surface stand
 * down: gated posts, premium newsletter lists and member-only blocks all become
 * public ({@see Content_Gate::is_gating_active()}). That is intended, and the
 * confirmation dialog says so — but the dialog is a single moment, and after it
 * nothing on the site says the paywall is down. This is the standing reminder,
 * so an accidental toggle doesn't quietly leave paid content open.
 *
 * Shown only when there is something to be inert. A site that never configured
 * Access Control gets nothing.
 */
class Inert_Gating_Notice {

	/**
	 * Option holding the cached "has anything configured" answer.
	 *
	 * An option rather than a transient: it must not expire on a timer, since the
	 * answer only changes when the underlying objects do and every one of those
	 * writes invalidates it explicitly below. Holds only "is anything configured" —
	 * whether that configuration is currently applying is read live by is_inert().
	 * Stored un-autoloaded — see has_surfaces().
	 */
	const CACHE_OPTION = 'newspack_content_gate_has_surfaces';

	/**
	 * Substring identifying a block carrying access-control attributes.
	 *
	 * Matches the `newspackAccessControlMode` / `...Visibility` / `...GateIds` /
	 * `...Rules` attributes that Block_Visibility registers, all of which share
	 * this prefix in the serialized block comment.
	 */
	const BLOCK_ATTRIBUTE_PREFIX = 'newspackAccessControl';

	/**
	 * The attributes that mean something is actually configured.
	 *
	 * Narrower than the prefix above on purpose. `...Mode` and `...Visibility` also
	 * serialize, but only record a choice: a block left in `custom` mode having
	 * never had a rule added carries `...Mode` and nothing else, and matching the
	 * bare prefix would report that site as gated. These two only appear once rules
	 * or gates are set, so they answer the question the notice actually asks.
	 *
	 * The prefix stays the invalidation trigger — over-flushing is harmless, and a
	 * flush that recomputes the same answer costs nothing.
	 */
	const CONFIGURED_ATTRIBUTES = [
		'newspackAccessControlRules',
		'newspackAccessControlGateIds',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Core admin screens only. Wizard screens render this as a Notice component
		// below their header and tabs instead ({@see get_script_data()}), because a
		// core notice there lands above the wizard's own header. Wizards strip all
		// notices at priority -9999 anyway, so the default priority is what keeps this
		// from fighting them.
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );

		// Cache invalidation. The answer changes only when a queried object is
		// created, deleted or edited, so those clear it and nothing else does — no
		// TTL, no periodic recompute. Deliberately NOT hooked to Audience Management:
		// the cached value is only "is anything configured", which that setting cannot
		// change, and is_inert() reads the setting live. Flushing there would just
		// throw away a valid answer and force the recompute onto the very page load
		// that has to render this notice.
		add_action( 'save_post', [ __CLASS__, 'flush_cache_on_post' ], 10, 2 );
		add_action( 'deleted_post', [ __CLASS__, 'flush_cache_on_post' ], 10, 2 );
		add_action( 'post_updated', [ __CLASS__, 'flush_cache_on_update' ], 10, 3 );
	}

	/**
	 * Clear the cache when an edit removes the last block access-control attributes.
	 *
	 * `save_post` sees only the content as it now is, so an edit that strips the
	 * last `newspackAccessControl*` attribute looks like an ordinary post save and
	 * flushes nothing — leaving the cached answer stuck at "configured" with no
	 * later write to correct it. `post_updated` is the one hook that supplies the
	 * content as it was.
	 *
	 * Only the "before" side is checked here; `save_post` already covers the after.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post object after the update.
	 * @param \WP_Post $post_before Post object before the update.
	 */
	public static function flush_cache_on_update( $post_id, $post_after, $post_before ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post_before instanceof \WP_Post ) {
			return;
		}
		if ( false !== strpos( (string) $post_before->post_content, self::BLOCK_ATTRIBUTE_PREFIX ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Clear the cache when a queried object is written or removed.
	 *
	 * Gates always count. Any other post type only matters if it carries block
	 * access-control attributes, so the content is checked before invalidating —
	 * otherwise every post save on the site would clear a cache that could not
	 * have changed.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post Post object, when the hook supplies one.
	 */
	public static function flush_cache_on_post( $post_id, $post = null ) {
		// Revisions carry a verbatim copy of the parent's content, so they match the
		// prefix check below and would flush on every autosave — roughly once a minute
		// while anyone has a gated post open, on exactly the sites this cache exists
		// for. They can never change the answer: has_block_rules() counts only
		// published posts and revisions are 'inherit'. Autosaves are revisions too
		// (see _wp_post_revision_data()), so one guard covers both.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( Content_Gate::GATE_CPT === $post->post_type ) {
			self::flush_cache();
			return;
		}
		if ( false !== strpos( (string) $post->post_content, self::BLOCK_ATTRIBUTE_PREFIX ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Clear the cached answer.
	 */
	public static function flush_cache() {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * Whether the site has any Access Control surface configured.
	 *
	 * Widened past gates on purpose: a publisher who only ever used block-level
	 * visibility has just as much content quietly going public, and would get no
	 * warning from a gates-only check.
	 *
	 * Cached because the block-attribute half is a `LIKE` over post content, which
	 * is too expensive to run on every admin page load. The query is bounded to a
	 * single row — this only ever answers "any", never "how many".
	 *
	 * @return bool
	 */
	public static function has_surfaces(): bool {
		$cached = get_option( self::CACHE_OPTION, null );
		if ( null !== $cached && '' !== $cached ) {
			return (bool) $cached;
		}

		$has_surfaces = self::has_gates() || self::has_block_rules();

		// Stored as '1'/'0' rather than a bool: update_option() round-trips false to
		// an empty string, which is indistinguishable from "not cached yet" and would
		// make a negative answer recompute the LIKE query on every page load.
		//
		// Not autoloaded: this is read in wp-admin and nowhere else, so autoloading it
		// would put it in every front-end request's option payload for nothing.
		update_option( self::CACHE_OPTION, $has_surfaces ? '1' : '0', false );

		return $has_surfaces;
	}

	/**
	 * Whether any published gate exists, of either kind.
	 *
	 * Published only: a draft gate was not applying before Audience Management was
	 * switched off either, so it is not something the publisher has lost.
	 *
	 * @return bool
	 */
	private static function has_gates(): bool {
		$gates = get_posts(
			[
				'post_type'      => Content_Gate::GATE_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		return ! empty( $gates );
	}

	/**
	 * Whether any post carries block-level access-control attributes.
	 *
	 * A direct query rather than WP_Query: this is a content `LIKE`, which
	 * WP_Query can only express through `s` (which also searches titles and
	 * excerpts and applies relevance ordering). Bounded to one row and cached by
	 * the caller.
	 *
	 * Constrained by post type so the `type_status_date` index is usable: without
	 * it MySQL full-scans wp_posts (`type: ALL, key: NULL`), with it the plan drops
	 * to a range scan. The list is derived rather than hardcoded, because any post
	 * type could carry these blocks — `show_in_rest` is the prerequisite for the
	 * block editor, so it cannot miss one, and it excludes `revision`, which is
	 * usually the largest slice of the table. `wp_block` has to stay in: a post
	 * embedding a reusable block carries only a `wp:block` reference, so the
	 * attributes live on the `wp_block` post alone.
	 *
	 * One `LIKE` per entry in CONFIGURED_ATTRIBUTES rather than one on the shared
	 * prefix, so a block that only records a mode choice isn't counted. Measured on
	 * a seeded 9,000-row wp_posts, no match present: 0.179 ms for the single prefix
	 * match against 0.218 ms for the pair. A `REGEXP` alternation came in faster
	 * still here, but this runs on MariaDB and CI and production run MySQL, whose
	 * regex engine is a different implementation with a different profile — not
	 * worth the variance for four hundredths of a millisecond on a query that only
	 * runs when the cache misses.
	 *
	 * @return bool
	 */
	private static function has_block_rules(): bool {
		global $wpdb;

		$post_types = get_post_types( [ 'show_in_rest' => true ], 'names' );
		if ( empty( $post_types ) ) {
			return false;
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$content_clause    = implode( ' OR ', array_fill( 0, count( self::CONFIGURED_ATTRIBUTES ), 'post_content LIKE %s' ) );

		$values = array_values( $post_types );
		foreach ( self::CONFIGURED_ATTRIBUTES as $attribute ) {
			$values[] = '%' . $wpdb->esc_like( $attribute ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- both interpolations are generated %s lists, which is why the sniffs cannot see the placeholders; every value is passed through prepare().
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ( $type_placeholders )
				AND post_status = 'publish'
				AND ( $content_clause )
				LIMIT 1",
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return ! empty( $found );
	}

	/**
	 * Whether the site is configured for Access Control but not applying it.
	 *
	 * @return bool
	 */
	public static function is_inert(): bool {
		if ( ! Content_Gate::is_newspack_feature_enabled() || Content_Gate::is_gating_active() ) {
			return false;
		}
		return self::has_surfaces();
	}

	/**
	 * Notice payload for the wizard shell.
	 *
	 * Returns the strings rather than a flag so the two surfaces cannot drift into
	 * saying different things about the same state.
	 *
	 * `has_surfaces` answers a different question from `show`, which is why both are
	 * here: `show` is "is content public right now", and is false while Audience
	 * Management is still on. The confirmation dialog for switching it off needs to
	 * know what would happen, so it asks whether anything is configured at all.
	 *
	 * Unlike `show`, it is not gated on the feature flag. Block-level visibility is
	 * flag-independent ({@see Block_Visibility}), so a site without the constant can
	 * still have member-only blocks that go public.
	 *
	 * @return array{show: bool, has_surfaces: bool, message: string, urls: array<string, string>}
	 */
	public static function get_script_data(): array {
		$can_manage = current_user_can( 'manage_options' );
		return [
			'show'         => $can_manage && self::is_inert(),
			'has_surfaces' => $can_manage && self::has_surfaces(),
			'message'      => self::get_message(),
			'urls'         => self::get_urls(),
		];
	}

	/**
	 * The notice text, shared by both surfaces.
	 *
	 * Carries named tags rather than finished anchors so the core notice and the
	 * wizard's React notice compose the same sentence from the same translation —
	 * two strings would drift, and a translator would see the markup either way.
	 *
	 * @return string
	 */
	private static function get_message(): string {
		return __( '<accessControl>Access Control</accessControl> features are currently <strong>disabled</strong>: gated content, premium newsletters, and member-only blocks are public for all readers. <audience>Turn on Audience Management</audience> to enable Access Control.', 'newspack-plugin' );
	}

	/**
	 * Destinations for the message's named tags.
	 *
	 * @return array<string, string>
	 */
	private static function get_urls(): array {
		return [
			'accessControl' => admin_url( 'admin.php?page=newspack-audience-access-control' ),
			'audience'      => admin_url( 'admin.php?page=newspack-audience#/' ),
		];
	}

	/**
	 * Render the core admin notice.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_inert() ) {
			return;
		}
		$message = self::get_message();
		foreach ( self::get_urls() as $tag => $url ) {
			$message = str_replace(
				[ '<' . $tag . '>', '</' . $tag . '>' ],
				[ '<a href="' . esc_url( $url ) . '">', '</a>' ],
				$message
			);
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					$message,
					[
						'a'      => [ 'href' => [] ],
						'strong' => [],
					]
				);
				?>
			</p>
		</div>
		<?php
	}
}
Inert_Gating_Notice::init();
