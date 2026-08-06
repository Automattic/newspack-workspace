<?php
/**
 * Newspack Content Gate.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Metering;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Content_Gate {

	use Content_Gate_Layout;

	const GATE_CPT = 'np_content_gate';

	const GATE_LAYOUT_CPT = 'np_gate_layout';

	/**
	 * Whether the gate has been rendered in this execution.
	 *
	 * @var boolean
	 */
	private static bool $gate_rendered = false;

	/**
	 * Whether the gate is being rendered.
	 *
	 * @var boolean
	 */
	private static bool $is_gated = false;

	/**
	 * Whether the queried post's content is locked for the current reader, i.e.
	 * fully gated with no access (the content has been replaced by a gate).
	 *
	 * Distinct from $is_gated, which only signals that gate markup is being
	 * rendered: that flag is also raised while building the metering excerpt
	 * and while rendering an overlay gate for a *metered* (still-readable) post.
	 * Comment gating must key off the access decision instead so it stays
	 * correct regardless of when gate markup happens to render. Set once, on the
	 * `the_post` action, before any content or comments are rendered.
	 *
	 * @var boolean
	 */
	private static bool $is_content_locked = false;

	/**
	 * Request-scoped cache of get_gates() results, keyed by its arguments.
	 *
	 * Each miss costs a get_posts() with a meta_query plus a get_gate() per gate,
	 * and callers hit it once per evaluated post (e.g. every item of an RSS feed),
	 * so the uncached cost scales with the number of posts on the page. Flushed
	 * whenever a post or post meta is written (see flush_gates_cache), which
	 * covers both wp_update_post and the bare update_post_meta() calls that gate
	 * settings are stored with.
	 *
	 * @var array<string,array>
	 */
	private static $gates_cache = [];

	/**
	 * Whether $gates_cache may be read from and written to.
	 *
	 * Null means "not resolved yet"; see is_gates_cache_enabled() for the default
	 * and set_gates_cache_enabled() for why it is overridable.
	 *
	 * @var bool|null
	 */
	private static $gates_cache_enabled = null;

	/**
	 * Valid gate post statuses.
	 *
	 * @var array
	 */
	public static array $valid_gate_post_statuses = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];

	/**
	 * Rendered pieces of each restricted post, keyed by post ID: the teaser and the
	 * gate HTML. Held separately so the teaser can be handed to the remaining
	 * 'the_content' filters without exposing the gate HTML to them.
	 *
	 * @var array<int, array{teaser: string, gate: string}>
	 */
	private static array $restricted_content = [];

	/**
	 * Post ID whose teaser has been substituted into an in-flight 'the_content'
	 * pass and whose gate is still to be appended, keyed by that pass's nesting
	 * depth.
	 *
	 * Keyed per pass rather than held as a single flag because 'the_content' nests:
	 * a callback registered after self::RESTRICTION_PRIORITY may run
	 * apply_filters( 'the_content', … ) itself, and core runs the whole callback
	 * list again for that inner pass. Sharing one slot, the inner pass would consume
	 * the outer pass's state, and the outer pass would then fall back to unfiltered
	 * markup, silently discarding the third-party filtering this substitution exists
	 * to preserve.
	 *
	 * Depth is also what keeps the bookkeeping self-cleaning. Neither filter is
	 * exception-safe — a callback throwing in between leaves the entry behind — but
	 * an entry can only ever be read by another pass at that exact depth, and
	 * {@see self::replace_restricted_content()} claims the slot on the way in, so a
	 * leftover is overwritten rather than mistaken for the pass now running.
	 *
	 * What depth cannot establish on its own is that the pass holding the entry is
	 * the one that substituted, so {@see self::handle_restricted_content()} pairs it
	 * with the substitution filter still being registered. That stands in for the
	 * substitution having run in every ordinary execution, since priorities run
	 * ascending and core does not revisit one it has passed. Defeating it takes four
	 * coincident manipulations of this class's own filters: the substitution filter
	 * removed before self::RESTRICTION_PRIORITY, then re-added by a callback above
	 * it, over a leftover entry at this same depth, on a restricted post. Short of
	 * all four the mismatch falls through to the stored teaser and gate, so the
	 * failure mode this guards against — publishing a restricted body — needs a
	 * plugin manipulating these filters deliberately rather than an integration
	 * merely filtering content.
	 *
	 * @var array<int, int>
	 */
	private static array $pending_gates = [];

	/**
	 * Priority at which a restricted post's content is swapped for its teaser.
	 *
	 * Woo Memberships restricted content at 999. Matching it keeps integrations
	 * built against Memberships working once a site moves to Access Control, which
	 * is the reason for substituting here rather than at the end of the chain.
	 *
	 * Note the boundary this draws: callbacks at or below this priority still
	 * receive the full restricted post and their output is still replaced. That is
	 * the behavior Memberships had, but it means an integration gating its own
	 * embeds at the default priority of 10 is not covered by this.
	 */
	const RESTRICTION_PRIORITY = 999;

	/**
	 * Whether the overlay gate markup has been output in this execution.
	 *
	 * @var boolean
	 */
	private static bool $overlay_gate_output = false;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'admin_init', [ __CLASS__, 'redirect_cpt' ] );
		add_filter( 'get_edit_post_link', [ __CLASS__, 'filter_edit_post_link' ], 10, 2 );
		add_action( 'admin_init', [ __CLASS__, 'handle_edit_gate_layout' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
		add_action( 'after_setup_theme', [ __CLASS__, 'register_overlay_gate_hooks' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_gate_layouts' ], 10, 2 );

		// Keep the get_gates() cache honest across writes (see $gates_cache).
		add_action( 'save_post', [ __CLASS__, 'flush_gates_cache' ] );
		add_action( 'deleted_post', [ __CLASS__, 'flush_gates_cache' ] );
		add_action( 'added_post_meta', [ __CLASS__, 'flush_gates_cache' ] );
		add_action( 'updated_post_meta', [ __CLASS__, 'flush_gates_cache' ] );
		add_action( 'deleted_post_meta', [ __CLASS__, 'flush_gates_cache' ] );
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_popups' ] );
		add_filter( 'newspack_reader_activity_article_view', [ __CLASS__, 'suppress_article_view_activity' ], 100 );

		add_action( 'the_post', [ __CLASS__, 'restrict_post' ], 10, 2 );
		add_filter( 'the_content', [ __CLASS__, 'replace_restricted_content' ], self::RESTRICTION_PRIORITY );
		add_filter( 'the_content', [ __CLASS__, 'handle_restricted_content' ], PHP_INT_MAX );
		add_filter( 'comments_open', [ __CLASS__, 'filter_comments_open' ], 10, 2 );
		add_filter( 'comments_array', [ __CLASS__, 'filter_comments_array' ], 10, 2 );
		add_filter( 'get_comments_number', [ __CLASS__, 'filter_comments_number' ], 10, 2 );

		/** Add gate content filters to mimic 'the_content'. See 'wp-includes/default-filters.php' for reference. */
		add_filter( 'newspack_gate_content', 'capital_P_dangit', 11 );
		add_filter( 'newspack_gate_content', [ __CLASS__, 'do_blocks' ], 9 ); // Custom implementation of do_blocks().
		add_filter( 'newspack_gate_content', 'wptexturize' );
		add_filter( 'newspack_gate_content', 'convert_smilies', 20 );
		add_filter( 'newspack_gate_content', 'wpautop' );
		add_filter( 'newspack_gate_content', 'shortcode_unautop' );
		add_filter( 'newspack_gate_content', 'prepend_attachment' );
		add_filter( 'newspack_gate_content', 'wp_filter_content_tags' );
		add_filter( 'newspack_gate_content', 'wp_replace_insecure_home_url' );
		add_filter( 'newspack_gate_content', 'do_shortcode', 11 ); // AFTER wpautop().

		include __DIR__ . '/class-content-gate-api.php';
		include __DIR__ . '/class-content-gate-advanced-settings.php';
		include __DIR__ . '/class-access-rules.php';
		include __DIR__ . '/class-content-rules.php';
		include __DIR__ . '/class-content-restriction-control.php';
		include __DIR__ . '/class-block-patterns.php';
		include __DIR__ . '/class-metering.php';
		include __DIR__ . '/class-metering-countdown.php';
		include __DIR__ . '/content-gifting/class-content-gifting.php';
		include __DIR__ . '/class-ip-access-rule.php';
		include __DIR__ . '/class-institution.php';
		include __DIR__ . '/class-newsletters-access.php';
		include __DIR__ . '/class-user-gate-access.php';
		include __DIR__ . '/class-premium-newsletters.php';
		include __DIR__ . '/class-block-visibility.php';
		include __DIR__ . '/class-gate-preview.php';

		Content_Gate\Gate_Preview::init();
	}

	/**
	 * Whether the first-party Newspack feature is enabled.
	 *
	 * Memoized per request — the underlying constant is immutable for the
	 * lifetime of a request, and call sites (admin menu, REST registration,
	 * wizard data, gated callbacks across Group_Subscription_*) consult this
	 * many times per page. The cache keeps that footprint flat if the check
	 * grows beyond a constant lookup in the future (license, remote call,
	 * etc.).
	 *
	 * Tests under PHPUnit boot the plugin once and `define()` the constant
	 * later in per-suite `setUp()` calls. To keep those defines effective,
	 * skip the cache when `IS_TEST_ENV` is on.
	 *
	 * @return bool
	 */
	public static function is_newspack_feature_enabled() {
		/**
		 * Enables the content gating feature which allows restricting
		 * content access based on membership, donations, or other criteria.
		 *
		 * @constant NEWSPACK_CONTENT_GATES
		 * @type     bool
		 * @default  Content gates disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CONTENT_GATES', true );
		 */
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			return defined( 'NEWSPACK_CONTENT_GATES' ) && NEWSPACK_CONTENT_GATES;
		}
		static $enabled = null;
		if ( null === $enabled ) {
			$enabled = defined( 'NEWSPACK_CONTENT_GATES' ) && NEWSPACK_CONTENT_GATES;
		}
		return $enabled;
	}

	/**
	 * Restrict the post.
	 *
	 * @param \WP_Post  $post Post object.
	 * @param \WP_Query $query Query object.
	 */
	public static function restrict_post( $post, $query ) {
		if ( self::has_rendered() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		if ( get_queried_object_id() !== $post->ID ) {
			return;
		}
		// Don't apply our restriction strategy if Woo Memberships is active.
		if ( Memberships::is_active() ) {
			return;
		}
		// Never restrict posts in the admin.
		if ( is_admin() ) {
			return;
		}
		// Never in Privacy Policy page.
		if ( is_privacy_policy() ) {
			return;
		}
		// Never in My Account pages.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}
		// Never in Terms and Conditions page.
		if ( function_exists( 'wc_terms_and_conditions_page_id' ) && $post->ID === wc_terms_and_conditions_page_id() ) {
			return;
		}
		// Never in WooCommerce cart page.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return;
		}
		// Never in WooCommerce checkout page.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}
		// Never on Accessibility Statement page.
		if ( $post->ID === get_theme_mod( 'accessibility_statement_page_id' ) ) {
			return;
		}

		// If no other restrictions apply.
		if ( ! self::is_post_restricted( $post->ID ) ) {
			return;
		}
		if (
			/**
			 * Filters whether to restrict the post.
			 *
			 * @param bool $restrict Whether to restrict the post.
			 * @param int $post_id Post ID.
			 */
			! apply_filters( 'newspack_content_gate_restrict_post', true, $post->ID )
		) {
			// Content is accessible (e.g. via metering); leave commenting governed
			// by the site's Discussion Settings rather than gating it.
			return;
		}

		self::$is_gated          = true;
		self::$is_content_locked = true;

		$content   = self::get_restricted_post_excerpt( $post );
		$gate_html = self::get_inline_gate_html();

		// Note that this does not feed the 'the_content' chain: core generates the
		// post's page data before firing 'the_post', so the chain is handed the
		// original body regardless. The assignment is for the other readers of the
		// global post object, and is why the filters below have to substitute the
		// teaser themselves.
		$post->post_content   = $content . $gate_html;
		$post->post_excerpt   = $content;
		$post->comment_status = 'closed';
		$post->comment_count  = 0;

		self::$restricted_content[ $post->ID ] = [
			'teaser' => $content,
			'gate'   => $gate_html,
		];

		self::mark_gate_as_rendered();
	}

	/**
	 * Substitute a restricted post's content for its teaser, early enough that the
	 * remaining 'the_content' filters still run over it.
	 *
	 * Third-party integrations gate their own embeds on 'the_content' – the Everlit
	 * audio player, which registers at priority 999999, is the known case. Handing
	 * them the teaser lets their gating compose with the paywall the way it did
	 * under Woo Memberships, whose restriction filter ran at 999. Returning the
	 * full post here and discarding the filtered result instead would leave those
	 * embeds ungated on restricted posts.
	 *
	 * The gate HTML is deliberately excluded; {@see self::handle_restricted_content()}
	 * appends it once every other filter has run, so neither a callback at a lower
	 * priority nor one registered at PHP_INT_MAX before this class hooks in can
	 * rewrite the gate markup itself. A PHP_INT_MAX callback registered afterwards
	 * does run last within that priority and does see the gate; nothing but
	 * registration order separates the two, so this is a boundary against ordinary
	 * integrations rather than against a plugin that means to reach the markup.
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	public static function replace_restricted_content( $content ) {
		$post_id = get_the_ID();
		$depth   = self::get_content_filter_depth();

		// Claim this pass's slot before anything else, so an entry a previous pass
		// at this depth left behind – a callback between the two filters throwing,
		// with the exception caught upstream – cannot be read as if it belonged to
		// this pass.
		unset( self::$pending_gates[ $depth ] );

		if ( ! isset( self::$restricted_content[ $post_id ] ) ) {
			return $content;
		}

		self::$pending_gates[ $depth ] = $post_id;
		return self::$restricted_content[ $post_id ]['teaser'];
	}

	/**
	 * Append the gate to a restricted post after all other content filters have run.
	 *
	 * @param string $content Content, expected to be the teaser as returned by
	 *                        {@see self::replace_restricted_content()} and processed
	 *                        by any later 'the_content' filters.
	 *
	 * @return string
	 */
	public static function handle_restricted_content( $content ) {
		$post_id = get_the_ID();
		$depth   = self::get_content_filter_depth();

		$substituted_id = self::$pending_gates[ $depth ] ?? null;
		unset( self::$pending_gates[ $depth ] );

		// Close only a substitution this same pass opened, which the nesting depth
		// is what establishes. A later filter may run 'the_content' again – related
		// posts, summaries – and that inner pass gets a depth of its own, so it can
		// neither consume this pass's pending gate nor be handed it.
		//
		// The post is taken from the entry rather than from get_the_ID(), which a
		// callback may have moved off the post whose teaser is in hand; and the
		// entry is trusted only while the substitution is still registered, since
		// short of that filter being removed and re-added mid-chain no pass can
		// have substituted, and the body in hand is the unrestricted post. See
		// self::$pending_gates for what that proxy does and does not establish.
		if (
			null !== $substituted_id
			&& isset( self::$restricted_content[ $substituted_id ] )
			&& has_filter( 'the_content', [ __CLASS__, 'replace_restricted_content' ] )
		) {
			return $content . self::$restricted_content[ $substituted_id ]['gate'];
		}

		if ( ! isset( self::$restricted_content[ $post_id ] ) ) {
			return $content;
		}

		// The teaser substitution did not run for this pass, most likely because
		// another plugin removed or short-circuited the filter. Core hands this
		// chain the unrestricted post body, so return the stored gated markup
		// rather than appending the gate to what is in hand, which would publish
		// the restricted post.
		return self::$restricted_content[ $post_id ]['teaser'] . self::$restricted_content[ $post_id ]['gate'];
	}

	/**
	 * Nesting depth of the 'the_content' pass currently running: 1 for an ordinary
	 * render, deeper when a callback runs the filter again from inside it.
	 *
	 * Core pushes the hook name onto $wp_current_filter for the duration of each
	 * pass, so counting the entries is the one reading of the depth that cannot
	 * disagree with the chain actually being run.
	 *
	 * @return int
	 */
	private static function get_content_filter_depth() {
		if ( empty( $GLOBALS['wp_current_filter'] ) || ! is_array( $GLOBALS['wp_current_filter'] ) ) {
			return 0;
		}
		return count( array_keys( $GLOBALS['wp_current_filter'], 'the_content', true ) );
	}

	/**
	 * Get whether the gate is being rendered.
	 *
	 * @return bool
	 */
	public static function is_gated() {
		return self::$is_gated;
	}

	/**
	 * Filter whether comments are open.
	 *
	 * Close comments only on fully locked posts, where the reader cannot access
	 * the content. Metered (currently-accessible) posts are left untouched so
	 * the site's Discussion Settings continue to govern commenting.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function filter_comments_open( $open, $post_id ) {
		if ( self::$is_content_locked && (int) $post_id === (int) get_queried_object_id() ) {
			return false;
		}
		return $open;
	}

	/**
	 * Filter comments array.
	 *
	 * Hide all comments on fully locked posts.
	 *
	 * @param array $comments Array of comments.
	 * @param int   $post_id  Post ID.
	 *
	 * @return array
	 */
	public static function filter_comments_array( $comments, $post_id ) {
		if ( self::$is_content_locked && (int) $post_id === (int) get_queried_object_id() ) {
			return [];
		}
		return $comments;
	}

	/**
	 * Filter the comment count.
	 *
	 * Return 0 on fully locked posts.
	 *
	 * @param int $count   Comment count.
	 * @param int $post_id Post ID.
	 *
	 * @return int
	 */
	public static function filter_comments_number( $count, $post_id ) {
		if ( self::$is_content_locked && (int) $post_id === (int) get_queried_object_id() ) {
			return 0;
		}
		return $count;
	}

	/**
	 * Parses dynamic blocks out of `post_content` and re-renders them.
	 *
	 * This is a copy of `do_blocks()` from `wp-includes/blocks.php` but with
	 * a different filter name for the `wpautop` filter handling.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Updated post content.
	 */
	public static function do_blocks( $content ) {
		$blocks = parse_blocks( $content );
		$output = '';

		foreach ( $blocks as $block ) {
			$output .= render_block( $block );
		}

		// If there are blocks in this content, we shouldn't run wpautop() on it later.
		$priority = has_filter( 'newspack_gate_content', 'wpautop' );
		if ( false !== $priority && doing_filter( 'newspack_gate_content' ) && has_blocks( $content ) ) {
			remove_filter( 'newspack_gate_content', 'wpautop', $priority );
			add_filter( 'newspack_gate_content', [ __CLASS__, 'restore_wpautop_hook' ], $priority + 1 );
		}

		return $output;
	}

	/**
	 * _restore_wpautop_hook filter, but for the newspack_gate_content filter instead of the_content
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function restore_wpautop_hook( $content ) {
		$current_priority = has_filter( 'newspack_gate_content', [ __CLASS__, 'restore_wpautop_hook' ] );

		add_filter( 'newspack_gate_content', 'wpautop', $current_priority - 1 );
		remove_filter( 'newspack_gate_content', [ __CLASS__, 'restore_wpautop_hook' ], $current_priority );

		return $content;
	}

	/**
	 * Get all gate post types.
	 *
	 * @return array Array of gate post types.
	 */
	public static function get_gate_post_types() {
		$cpts = [ self::GATE_CPT ];
		if ( Memberships::is_active() ) {
			$cpts[] = Memberships::GATE_CPT;
		}
		return $cpts;
	}

	/**
	 * Register post type for custom gate.
	 */
	public static function register_post_type() {
		// Register the main gate post type.
		\register_post_type(
			self::GATE_CPT,
			[
				'label'        => __( 'Content Gate', 'newspack-plugin' ),
				'labels'       => [
					'item_published'         => __( 'Content Gate published.', 'newspack-plugin' ),
					'item_reverted_to_draft' => __( 'Content Gate reverted to draft.', 'newspack-plugin' ),
					'item_updated'           => __( 'Content Gate updated.', 'newspack-plugin' ),
					'new_item'               => __( 'New Content Gate', 'newspack-plugin' ),
					'edit_item'              => __( 'Edit Content Gate', 'newspack-plugin' ),
					'view_item'              => __( 'View Content Gate', 'newspack-plugin' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'custom-fields', 'revisions' ],
			]
		);
		// Register the layout post type.
		self::register_layout_post_type( self::GATE_LAYOUT_CPT, __( 'Content Gate Layout', 'newspack-plugin' ) );
	}

	/**
	 * Filter the edit post link for gate CPTs to point to the access control wizard.
	 *
	 * @param string $link    The edit link.
	 * @param int    $post_id Post ID.
	 *
	 * @return string Filtered edit link.
	 */
	public static function filter_edit_post_link( $link, $post_id ) {
		if ( get_post_type( $post_id ) === self::GATE_CPT ) {
			return admin_url( 'admin.php?page=newspack-audience-access-control#/edit/' . $post_id );
		}
		return $link;
	}

	/**
	 * Redirect the custom gate CPT to the Content Gating wizard
	 */
	public static function redirect_cpt() {
		if ( ! self::is_newspack_feature_enabled() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && in_array( $_GET['post_type'], [ self::GATE_CPT, self::GATE_LAYOUT_CPT ], true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect = Memberships::is_active() ? \admin_url( 'admin.php?page=newspack-audience#/content-gating' ) : \admin_url( 'admin.php?page=newspack-audience-access-control#/' );
			\wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Enqueue content banner assets.
	 */
	public static function enqueue_content_banner_assets() {
		if ( Content_Gifting::should_enqueue_assets() || Metering_Countdown::is_enabled() ) {
			$asset = require dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-banner.asset.php';

			// Ensure the content gate metering script is enqueued first.
			if ( is_singular() && self::has_gate() && self::is_post_restricted() && Metering::is_frontend_metering() ) {
				$asset['dependencies'][] = 'newspack-content-gate-metering';
			}
			wp_enqueue_script(
				'newspack-content-banner',
				Newspack::plugin_url() . '/dist/content-banner.js',
				$asset['dependencies'],
				Newspack::asset_version( 'content-banner' ),
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
			wp_enqueue_style( 'newspack-content-banner', Newspack::plugin_url() . '/dist/content-banner.css', [], Newspack::asset_version( 'content-banner' ) );
		}
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		// Share the same feature gate as Content_Restriction_Control::register_meta():
		// with the flag off the exempt key is absent from the REST schema, so the panel
		// must not render a toggle that could not persist. In practice get_gates() is
		// already empty when the flag is off, but gating both on the flag keeps them aligned.
		if ( ! self::is_newspack_feature_enabled() ) {
			return;
		}
		if ( ! in_array( get_post_type(), array_column( Content_Restriction_Control::get_available_post_types(), 'value' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		if ( 0 === count( self::get_gates() ) ) {
			return;
		}
		$asset = require dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-post-settings.asset.php';
		wp_enqueue_script( 'newspack-content-gate-post-settings', Newspack::plugin_url() . '/dist/content-gate-post-settings.js', $asset['dependencies'], $asset['version'], true );

		// Localize active gates data for reactive matching in the editor.
		$gates      = self::get_gates( self::GATE_CPT, 'publish' );
		$gates_data = [];
		foreach ( $gates as $gate ) {
			if ( empty( $gate['registration']['active'] ) && empty( $gate['custom_access']['active'] ) ) {
				continue;
			}
			if ( empty( $gate['content_rules'] ) ) {
				continue;
			}
			$gates_data[] = [
				'id'                  => $gate['id'],
				'title'               => $gate['title'],
				'edit_url'            => get_edit_post_link( $gate['id'], 'raw' ),
				'content_rules'       => $gate['content_rules'],
				'content_rules_match' => $gate['content_rules_match'],
			];
		}

		// Build taxonomy slug to REST attribute name map.
		$taxonomy_map = [];
		foreach ( Content_Restriction_Control::get_available_taxonomies() as $tax ) {
			$taxonomy_obj = get_taxonomy( $tax['slug'] );
			if ( $taxonomy_obj && $taxonomy_obj->show_in_rest ) {
				$rest_base                    = ! empty( $taxonomy_obj->rest_base ) ? $taxonomy_obj->rest_base : $taxonomy_obj->name;
				$taxonomy_map[ $tax['slug'] ] = $rest_base;
			}
		}

		wp_localize_script(
			'newspack-content-gate-post-settings',
			'newspackContentGates',
			[
				'gates'        => $gates_data,
				'taxonomyMap'  => $taxonomy_map,
				'canEditGates' => current_user_can( 'manage_options' ),
			]
		);
	}

	/**
	 * Enqueue frontend scripts and styles for gated content.
	 */
	public static function enqueue_scripts() {
		self::enqueue_content_banner_assets();

		if ( ! self::has_gate() ) {
			return;
		}
		if ( ! is_singular() || ! self::is_post_restricted() ) {
			return;
		}
		$handle = 'newspack-content-gate';
		\wp_enqueue_script(
			$handle,
			Newspack::plugin_url() . '/dist/content-gate.js',
			[],
			filemtime( dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate.js' ),
			true
		);
		\wp_script_add_data( $handle, 'async', true );
		\wp_localize_script(
			$handle,
			'newspack_content_gate',
			[
				'metadata' => self::get_gate_metadata(),
			]
		);
		\wp_enqueue_style(
			$handle,
			Newspack::plugin_url() . '/dist/content-gate.css',
			[],
			filemtime( dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate.css' )
		);
	}

	/**
	 * Get the post ID of the custom gate.
	 *
	 * @param int $post_id Post ID to find gate for.
	 *
	 * @return int|false Post ID or false if not set.
	 */
	public static function get_gate_post_id( $post_id = null ) {
		$gate_post_id = Memberships::is_active() ? Memberships::get_gate_post_id( $post_id ) : Content_Restriction_Control::get_gate_post_id( $post_id );

		/**
		 * Filters the gate post ID.
		 *
		 * @param int $gate_post_id Gate post ID.
		 * @param int $post_id      Post ID.
		 */
		return apply_filters( 'newspack_content_gate_post_id', $gate_post_id, $post_id );
	}

	/**
	 * Get the gate layout ID for the post.
	 *
	 * @param int $post_id Post ID. If not given, uses the current post ID.
	 *
	 * @return int|false
	 */
	public static function get_gate_layout_id( $post_id = null ) {
		$gate_layout_id = Memberships::is_active() ? Memberships::get_gate_post_id( $post_id ) : Content_Restriction_Control::get_gate_layout_id( $post_id );

		/**
		 * Filters the gate layout ID.
		 *
		 * @param int $gate_layout_id Gate layout ID.
		 * @param int $post_id      Post ID.
		 */
		return apply_filters( 'newspack_content_gate_layout_id', $gate_layout_id, $post_id );
	}

	/**
	 * Get gate metadata to be used for analytics purposes.
	 *
	 * @return array {
	 *   The gate metadata.
	 *
	 *   @type int    $gate_post_id The gate post ID.
	 *   @type array  $gate_blocks  Names of unique blocks in the gate post.
	 * }
	 */
	public static function get_gate_metadata() {
		$post_id = self::get_gate_post_id();
		return [
			'gate_post_id' => $post_id,
			'logged_in'    => \is_user_logged_in() ? 'yes' : 'no',
		];
	}

	/**
	 * Whether the gate is available.
	 *
	 * @return bool
	 */
	public static function has_gate() {
		$post_id = self::get_gate_post_id();
		return $post_id && 'publish' === get_post_status( $post_id );
	}

	/**
	 * Whether any gate of the given type meters, i.e. grants at least one free view.
	 *
	 * Read the gate settings through Metering rather than the gate array: metering lives
	 * under the `registration`/`custom_access` sections on the gate CPT and in flat post
	 * meta on the legacy memberships gate, and Metering is what resolves the two.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return bool
	 */
	public static function is_metering_enabled( $post_type = self::GATE_CPT ) {
		$gates = self::get_gates( $post_type );
		foreach ( $gates as $gate ) {
			if ( Metering::is_gate_metered( $gate['id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Public method for marking the gate as rendered.
	 */
	public static function mark_gate_as_rendered() {
		self::$gate_rendered = true;
	}

	/**
	 * Whether the gate has rendered.
	 */
	public static function has_rendered() {
		return self::$gate_rendered;
	}

	/**
	 * Whether the post has restrictions
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function post_has_restrictions( $post_id = null ) {
		$post_id = $post_id ? $post_id : get_the_ID();

		// TODO: Content Gate content rules check.

		/**
		 * Filters whether the post has restrictions.
		 *
		 * @param bool $has_restrictions Whether the post has restrictions.
		 * @param int  $post_id          Post ID.
		 */
		return apply_filters( 'newspack_post_has_restrictions', false, $post_id );
	}

	/**
	 * Whether the post is restricted for the current user.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int|bool Gate ID restricting the post, false if not restricted, or true if restricted by a Woo Memberships plan.
	 */
	public static function is_post_restricted( $post_id = null ) {
		$post_id = $post_id ? $post_id : get_the_ID();

		/**
		 * Filters whether the post is restricted for the current user.
		 *
		 * @param bool $restricted_by Whether the post is restricted.
		 * @param int  $post_id       Post ID.
		 */
		return apply_filters( 'newspack_is_post_restricted', false, $post_id );
	}

	/**
	 * Get the priority to give a new gate, placing it after the last gate of its own bucket.
	 *
	 * Content gates and premium newsletter gates are prioritized separately, so a gate is
	 * numbered against the others in its bucket. Derived from the highest priority in use
	 * rather than the gate count: priorities are positions, not a counter, so a count would
	 * collide with an existing gate as soon as one has been deleted from the middle of the
	 * list — and priority is what orders overlapping gates, so a tie leaves an arbitrary gate
	 * deciding what a reader sees.
	 *
	 * This reads the current max and returns max + 1, a check-then-act pair that isn't atomic:
	 * two concurrent creations could read the same max and both claim it. Gate creation is a
	 * one-at-a-time admin action, so that race can't realistically happen and no lock is warranted.
	 *
	 * Only the single highest-priority gate in the bucket is queried (its ID and priority meta),
	 * rather than hydrating every gate, since that top priority is all this needs.
	 *
	 * @param string $post_type     Post type whose bucket the new gate belongs to. Defaults to self::GATE_CPT.
	 * @param bool   $is_newsletter Whether the new gate is a premium newsletter gate.
	 *
	 * @return int
	 */
	public static function get_next_gate_priority( $post_type = self::GATE_CPT, $is_newsletter = false ) {
		$top_gate_ids = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => self::get_post_statuses(),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => [ 'priority' => 'DESC' ],
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					'priority' => [
						'key'  => 'gate_priority',
						'type' => 'NUMERIC',
					],
					[
						'key'     => 'is_newsletter',
						'compare' => $is_newsletter ? 'EXISTS' : 'NOT EXISTS',
					],
				],
			]
		);
		if ( empty( $top_gate_ids ) ) {
			return 0;
		}
		return (int) get_post_meta( $top_gate_ids[0], 'gate_priority', true ) + 1;
	}

	/**
	 * Create a new gate post.
	 *
	 * @param array  $gate Gate settings.
	 * @param string $post_type Optional post type. Defaults to self::GATE_CPT.
	 * @param bool   $is_newsletter Whether the gate is for a newsletter.
	 *
	 * @return int|\WP_Error The gate post ID or error if not created.
	 */
	public static function create_gate( $gate, $post_type = self::GATE_CPT, $is_newsletter = false ) {
		$args = [
			'post_title'   => $gate['title'] ?? __( 'Untitled Content Gate', 'newspack-plugin' ),
			'post_type'    => $post_type,
			'post_status'  => isset( $gate['status'] ) && in_array( $gate['status'], self::get_post_statuses(), true ) ? $gate['status'] : 'publish',
			'post_content' => '',
			'meta_input'   => [
				'gate_priority' => self::get_next_gate_priority( $post_type, $is_newsletter ),
			],
		];
		if ( $is_newsletter ) {
			$args['meta_input']['is_newsletter'] = true;
		}
		$gate_id = \wp_insert_post(
			$args,
			true // Return WP_Error on failure.
		);

		if ( is_wp_error( $gate_id ) ) {
			return $gate_id;
		}

		// Update content rules.
		if ( isset( $gate['content_rules'] ) ) {
			Content_Rules::update_gate_content_rules( $gate_id, $gate['content_rules'] );
		}

		// Update rule-combination mode.
		if ( isset( $gate['content_rules_match'] ) ) {
			Content_Rules::update_gate_content_rules_match( $gate_id, $gate['content_rules_match'] );
		}

		// Create default layouts for registration and custom_access modes.
		$layout_titles = self::get_gate_mode_layout_titles();

		$registration_settings  = $gate['registration'] ?? [];
		$registration_layout_id = $registration_settings['gate_layout_id'] ?? 0;
		$custom_access_settings  = $gate['custom_access'] ?? [];
		$custom_access_layout_id = $custom_access_settings['gate_layout_id'] ?? 0;

		if ( ! $registration_layout_id ) {
			$registration_content   = self::get_layout_default_content( $gate_id, 'registration', $registration_settings, $custom_access_settings );
			$registration_layout_id = self::create_gate_layout(
				$layout_titles['registration'],
				$registration_content
			);
		}
		if ( ! is_wp_error( $registration_layout_id ) ) {
			$registration_settings['gate_layout_id'] = $registration_layout_id;
		}
		self::update_registration_settings( $gate_id, $registration_settings );

		if ( ! $custom_access_layout_id ) {
			$custom_access_content   = self::get_layout_default_content( $gate_id, 'custom_access', $registration_settings, $custom_access_settings );
			$custom_access_layout_id = self::create_gate_layout(
				$layout_titles['custom_access'],
				$custom_access_content
			);
			if ( ! is_wp_error( $custom_access_layout_id ) ) {
				$custom_access_settings['gate_layout_id'] = $custom_access_layout_id;
			}
		}
		self::update_custom_access_settings( $gate_id, $custom_access_settings );

		return $gate_id;
	}

	/**
	 * The gate meta keys holding a mode's settings, mapped to the default title of that
	 * mode's layout post.
	 *
	 * @return array
	 */
	private static function get_gate_mode_layout_titles() {
		return [
			'registration'  => __( 'Registration Access Layout', 'newspack-plugin' ),
			'custom_access' => __( 'Paid Access Layout', 'newspack-plugin' ),
		];
	}

	/**
	 * Get a unique title for a copy of a gate.
	 *
	 * Appends a translatable " copy" suffix, numbering it (" copy 2", " copy 3", …)
	 * until it no longer collides with an existing gate in the same bucket.
	 *
	 * @param int        $gate_id       Gate ID being duplicated.
	 * @param array|null $bucket_gates  Optional gates of the source's bucket, to save re-fetching them.
	 *
	 * @return string
	 */
	public static function get_duplicate_gate_title( $gate_id, $bucket_gates = null ) {
		// Deliberately not get_the_title(): the 'the_title' filters texturize the title and
		// prefix drafts/private posts, so the copy's stored title would not be the source's
		// title plus a suffix, and would not compare against the raw titles below.
		$source       = get_post( $gate_id );
		$source_title = $source ? $source->post_title : '';

		if ( null === $bucket_gates ) {
			$is_newsletter = (bool) get_post_meta( $gate_id, 'is_newsletter', true );
			$bucket_gates  = self::get_gates( self::GATE_CPT, null, $is_newsletter );
		}
		$taken_titles = wp_list_pluck( $bucket_gates, 'title' );

		/* translators: %s: title of the gate being duplicated. */
		$title = sprintf( __( '%s copy', 'newspack-plugin' ), $source_title );

		$copy_number = 1;
		while ( in_array( $title, $taken_titles, true ) ) {
			++$copy_number;
			/* translators: 1: title of the gate being duplicated. 2: number of this copy. */
			$title = sprintf( __( '%1$s copy %2$d', 'newspack-plugin' ), $source_title, $copy_number );
		}

		return $title;
	}

	/**
	 * Copy a gate layout post, with the presentation settings stored as its meta.
	 *
	 * Those settings ('style', 'visible_paragraphs', …) decide how much of a restricted
	 * article a reader sees, so a copy that dropped them would not just look different —
	 * it would reveal a different amount of the gated content.
	 *
	 * @param \WP_Post $source_layout The layout post to copy.
	 *
	 * @return int|\WP_Error The new layout post ID or error if not created.
	 */
	private static function duplicate_gate_layout( $source_layout ) {
		// Deliberately not create_gate_layout(): it substitutes the default gate content for an
		// empty layout, which would give the copy a member message a deliberately blank source
		// layout doesn't show.
		$new_layout_id = \wp_insert_post(
			[
				'post_title'   => $source_layout->post_title,
				'post_type'    => self::GATE_LAYOUT_CPT,
				'post_content' => $source_layout->post_content,
				'post_status'  => $source_layout->post_status,
			],
			true // Return WP_Error on failure.
		);
		if ( is_wp_error( $new_layout_id ) ) {
			return $new_layout_id;
		}

		foreach ( \get_post_meta( $source_layout->ID ) as $key => $values ) {
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				\add_post_meta( $new_layout_id, $key, \maybe_unserialize( $value ) );
			}
		}

		return $new_layout_id;
	}

	/**
	 * Duplicate a gate.
	 *
	 * The copy is always created inactive, regardless of the site's default status for
	 * new gates: a copy of a live gate silently going live would change the site's
	 * access behavior.
	 *
	 * @param int $gate_id Gate ID to duplicate.
	 *
	 * @return int|\WP_Error The new gate ID, or error if the source is not a gate or the copy could not be created.
	 */
	public static function duplicate_gate( $gate_id ) {
		$source = get_post( $gate_id );
		if ( ! $source || self::GATE_CPT !== $source->post_type ) {
			return new \WP_Error( 'newspack_content_gate_not_found', __( 'Gate not found.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}
		if ( ! in_array( $source->post_status, self::get_post_statuses(), true ) ) {
			return new \WP_Error( 'newspack_content_gate_invalid_status', __( 'This gate cannot be duplicated.', 'newspack-plugin' ), [ 'status' => 400 ] );
		}

		$is_newsletter = (bool) get_post_meta( $gate_id, 'is_newsletter', true );

		// Content gates and premium newsletter gates are prioritized in separate buckets, so
		// the copy goes after the last gate of its own. Derived from the highest priority in
		// use rather than the gate count, which would collide with an existing gate whenever
		// one has been deleted from the middle of the list.
		$bucket_gates = self::get_gates( self::GATE_CPT, null, $is_newsletter );
		$priority     = $bucket_gates ? max( wp_list_pluck( $bucket_gates, 'priority' ) ) + 1 : 0;

		$new_gate_id = \wp_insert_post(
			[
				'post_title'   => self::get_duplicate_gate_title( $gate_id, $bucket_gates ),
				'post_type'    => self::GATE_CPT,
				'post_status'  => 'draft',
				'post_content' => '',
			],
			true // Return WP_Error on failure.
		);
		if ( is_wp_error( $new_gate_id ) ) {
			// A failed insert is a genuine server error, so give it an explicit 500 status
			// (matching the controlled codes on the validation branches above) rather than
			// leaving the REST layer to fall back on its generic 500. The underlying error
			// is preserved so a maintainer can see why the insert failed.
			$new_gate_id->add_data( [ 'status' => 500 ] );
			return $new_gate_id;
		}

		$layout_titles = self::get_gate_mode_layout_titles();

		// Copy the settings generically, so gate settings added later are carried over without
		// a list to maintain here.
		foreach ( \get_post_meta( $gate_id ) as $key => $values ) {
			if ( 'gate_priority' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				$value = \maybe_unserialize( $value );
				// The source's layout IDs must never be persisted on the copy, not even
				// briefly: while they are, deleting the copy would delete the layouts the
				// source is still serving to readers. The copy's own layouts are wired in
				// below.
				if ( isset( $layout_titles[ $key ] ) && is_array( $value ) ) {
					unset( $value['gate_layout_id'] );
				}
				\add_post_meta( $new_gate_id, $key, $value );
			}
		}
		\update_post_meta( $new_gate_id, 'gate_priority', $priority );

		// Deep-copy the layouts. Sharing layout posts between two gates would let
		// delete_gate_layouts() destroy the surviving gate's reader-facing content.
		foreach ( $layout_titles as $gate_mode => $default_layout_title ) {
			$source_settings  = \get_post_meta( $gate_id, $gate_mode, true );
			$source_layout_id = is_array( $source_settings ) && ! empty( $source_settings['gate_layout_id'] ) ? $source_settings['gate_layout_id'] : 0;
			$source_layout    = $source_layout_id ? get_post( $source_layout_id ) : null;

			if ( $source_layout && self::GATE_LAYOUT_CPT === $source_layout->post_type ) {
				$new_layout_id = self::duplicate_gate_layout( $source_layout );
			} else {
				// Stale or missing layout ID: create a fresh default layout, as create_gate() does.
				$new_layout_id = self::create_gate_layout(
					$default_layout_title,
					self::get_layout_default_content(
						$new_gate_id,
						$gate_mode,
						self::get_registration_settings( $new_gate_id ),
						self::get_custom_access_settings( $new_gate_id )
					)
				);
			}

			if ( is_wp_error( $new_layout_id ) ) {
				// Discard the half-built copy rather than leave it in the publisher's list.
				// Its own layouts, if any, go with it via delete_gate_layouts().
				\wp_delete_post( $new_gate_id, true );
				return $new_layout_id;
			}

			$settings                   = \get_post_meta( $new_gate_id, $gate_mode, true );
			$settings                   = is_array( $settings ) ? $settings : [];
			$settings['gate_layout_id'] = $new_layout_id;
			\update_post_meta( $new_gate_id, $gate_mode, $settings );
		}

		return $new_gate_id;
	}

	/**
	 * Delete gate layouts when a gate is permanently deleted.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function delete_gate_layouts( $post_id, $post ) {
		if ( self::GATE_CPT !== $post->post_type ) {
			return;
		}

		$gate = self::get_gate( $post_id );
		if ( is_wp_error( $gate ) ) {
			return;
		}

		// Delete registration layout if it exists.
		if ( ! empty( $gate['registration']['gate_layout_id'] ) ) {
			\wp_delete_post( $gate['registration']['gate_layout_id'], true );
		}

		// Delete custom access layout if it exists.
		if ( ! empty( $gate['custom_access']['gate_layout_id'] ) ) {
			\wp_delete_post( $gate['custom_access']['gate_layout_id'], true );
		}
	}

	/**
	 * Create a new gate layout post.
	 *
	 * @param string $title   Optional gate layout title. Defaults to 'Content Gate Layout'.
	 * @param string $content Optional post content. Defaults to a simple paragraph.
	 *
	 * @return int|\WP_Error The gate layout post ID or error if not created.
	 */
	public static function create_gate_layout( $title = '', $content = '' ) {
		if ( empty( $title ) ) {
			$title = __( 'Content Gate Layout', 'newspack-plugin' );
		}
		if ( empty( $content ) ) {
			$content = self::get_default_gate_content();
		}
		return \wp_insert_post(
			[
				'post_title'   => $title,
				'post_type'    => self::GATE_LAYOUT_CPT,
				'post_content' => $content,
				'post_status'  => 'publish',
			],
			true // Return WP_Error on failure.
		);
	}

	/**
	 * Get block pattern content by slug.
	 *
	 * @param string $pattern_slug The pattern slug (e.g., 'registration-wall').
	 * @param array  $pattern_context Optional context available to pattern files as $pattern_context.
	 *
	 * @return string The pattern content, or empty string if not found.
	 */
	private static function get_block_pattern_content( $pattern_slug, $pattern_context = [] ) {
		$patterns_dir = realpath( __DIR__ . '/block-patterns' );
		if ( ! $patterns_dir ) {
			return '';
		}

		$path = realpath( $patterns_dir . '/' . $pattern_slug . '.php' );

		// Ensure the resolved path is within the block-patterns directory to prevent directory traversal.
		if ( ! $path || strpos( $path, $patterns_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
			return '';
		}

		ob_start();
		require $path;
		return Content_Gate\Block_Patterns::strip_pattern_whitespace( ob_get_clean() );
	}

	/**
	 * Get the block pattern content for a gate layout.
	 *
	 * @param int    $gate_id                Gate ID.
	 * @param string $gate_mode              Gate mode.
	 * @param array  $registration_settings  Registration settings.
	 * @param array  $custom_access_settings Custom access settings.
	 *
	 * @return string
	 */
	private static function get_layout_default_content( $gate_id, $gate_mode, $registration_settings = [], $custom_access_settings = [] ) {
		if ( empty( $registration_settings ) ) {
			$registration_settings = self::get_registration_settings( $gate_id );
		}
		if ( empty( $custom_access_settings ) ) {
			$custom_access_settings = self::get_custom_access_settings( $gate_id );
		}

		$pattern_slug = '';
		if ( 'registration' === $gate_mode ) {
			$pattern_slug = 'registration-wall';
			// Upgrade to the metering layout only when the paid tier actually grants free
			// views. A tier that is active but meters 0 views gates every reader on their
			// first view, so its layout must not advertise "free articles" it never
			// delivers (NPPD-2056).
			$custom_access_meters = ! empty( $custom_access_settings['active'] )
				&& ! empty( $custom_access_settings['metering']['enabled'] )
				&& absint( $custom_access_settings['metering']['count'] ?? 0 ) > 0;
			if ( $custom_access_meters ) {
				$pattern_slug = 'pay-wall-one-tier-metering';
			}
		} elseif ( 'custom_access' === $gate_mode ) {
			$pattern_slug = 'pay-wall-one-tier';
		}

		if ( empty( $pattern_slug ) ) {
			return '<p>' . esc_html( __( 'This article is only available to members.', 'newspack-plugin' ) ) . '</p>';
		}
		return self::get_block_pattern_content(
			$pattern_slug,
			[
				'registration_settings'  => $registration_settings,
				'custom_access_settings' => $custom_access_settings,
			]
		);
	}

	/**
	 * Get edit gate layout URL.
	 *
	 * @param int|false    $gate_id   Gate ID or false if not set.
	 * @param string|false $gate_mode Gate mode or false if not set.
	 *
	 * @return string Edit gate layout URL.
	 */
	public static function get_edit_gate_layout_url( $gate_id = false, $gate_mode = false ) {
		$action = 'newspack_edit_gate_layout';
		$url    = add_query_arg( '_wpnonce', \wp_create_nonce( $action ), \admin_url( 'admin.php?action=' . $action ) );
		if ( $gate_id ) {
			$url = add_query_arg( 'gate_id', $gate_id, $url );
		}
		if ( $gate_mode ) {
			$url = add_query_arg( 'gate_mode', $gate_mode, $url );
		}
		return \wp_make_link_relative( $url );
	}

	/**
	 * Handle edit gate layout.
	 */
	public static function handle_edit_gate_layout() {
		if ( ! isset( $_GET['action'] ) || 'newspack_edit_gate_layout' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'newspack_edit_gate_layout' );

		$gate_id = isset( $_GET['gate_id'] ) ? \absint( $_GET['gate_id'] ) : false;
		if ( ! $gate_id ) {
			\wp_die( esc_html( __( 'Gate ID is required.', 'newspack-plugin' ) ) );
		}

		$gate_mode = isset( $_GET['gate_mode'] ) ? \sanitize_text_field( $_GET['gate_mode'] ) : false;
		if ( ! $gate_mode ) {
			\wp_die( esc_html( __( 'Gate mode is required.', 'newspack-plugin' ) ) );
		}

		$gate = self::get_gate( $gate_id );
		if ( ! $gate ) {
			\wp_die( esc_html( __( 'Gate not found.', 'newspack-plugin' ) ) );
		}

		$gate_layout_id            = 0;
		$gate_layout_default_title = __( 'Content Gate Layout', 'newspack-plugin' );

		if ( 'registration' === $gate_mode ) {
			$gate_layout_id = $gate['registration']['gate_layout_id'];
			$gate_layout_default_title = __( 'Registration Access Layout', 'newspack-plugin' );
		} elseif ( 'custom_access' === $gate_mode ) {
			$gate_layout_id = $gate['custom_access']['gate_layout_id'];
			$gate_layout_default_title = __( 'Paid Access Layout', 'newspack-plugin' );
		} else {
			\wp_die( esc_html( __( 'Invalid gate mode.', 'newspack-plugin' ) ) );
		}

		$gate_layout = get_post( $gate_layout_id );
		if ( $gate_layout ) {
			if ( 'trash' === get_post_status( $gate_layout_id ) ) {
				\wp_untrash_post( $gate_layout_id );
			}
			\wp_safe_redirect( \get_edit_post_link( $gate_layout_id, 'edit' ) );
			exit;
		} else {
			// Use registration pattern for registration mode, default content for custom_access.
			$gate_layout_content = self::get_layout_default_content( $gate_id, $gate_mode, $gate['registration'], $gate['custom_access'] );
			$gate_layout_id      = self::create_gate_layout( $gate_layout_default_title, $gate_layout_content );
			if ( is_wp_error( $gate_layout_id ) ) {
				\wp_die( esc_html( $gate_layout_id->get_error_message() ) );
			}
			$gate[ $gate_mode ]['gate_layout_id'] = $gate_layout_id;
			self::update_gate_settings( $gate_id, $gate );
			\wp_safe_redirect( \get_edit_post_link( $gate_layout_id, 'edit' ) );
			exit;
		}
	}

	/**
	 * Get the post excerpt to be displayed in the gate.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return string
	 */
	public static function get_restricted_post_excerpt( $post ) {
		self::$is_gated = true;
		return self::get_restricted_post_excerpt_for_gate( $post, self::get_gate_layout_id() );
	}

	/**
	 * Render the overlay gate.
	 */
	public static function render_overlay_gate() {
		if ( ! self::has_gate() ) {
			return;
		}
		if (
			/**
			 * Filters whether the overlay gate can be rendered.
			 *
			 * @param bool $can_render Whether the overlay gate can be rendered.
			 */
			! apply_filters( 'newspack_can_render_overlay_gate', true )
		) {
			return;
		}
		// Only render overlay gate for a restricted singular content.
		if ( ! is_singular() || ! self::is_post_restricted() ) {
			return;
		}
		// Bail if metering allows rendering the content.
		if ( ! Metering::is_frontend_metering() && Metering::is_logged_in_metering_allowed() ) {
			return;
		}
		$gate_layout_id = self::get_gate_layout_id();
		$style          = \get_post_meta( $gate_layout_id, 'style', true );
		if ( 'overlay' !== $style ) {
			return;
		}
		self::$is_gated = true;

		global $post;
		$_post = $post;
		$post  = \get_post( $gate_layout_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		self::render_overlay_gate_html( $gate_layout_id );
		self::$overlay_gate_output = true;

		self::mark_gate_as_rendered();
		wp_reset_postdata();
		$post = $_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Register overlay gate hooks after the theme has been set up.
	 *
	 * Deferred to after_setup_theme so that wp_is_block_theme() can be called safely,
	 * after theme directories have been registered.
	 */
	public static function register_overlay_gate_hooks() {
		if ( self::is_block_theme() ) {
			add_filter( 'render_block', [ __CLASS__, 'inject_overlay_gate_after_post_content_block' ], 10, 2 );
		} else {
			add_action( 'get_footer', [ __CLASS__, 'render_overlay_gate' ], 1 );
		}
	}

	/**
	 * Inject overlay gate markup right after the post content block.
	 *
	 * Used for block themes where there aren't hooks to use in time to get do_blocks() to run.
	 *
	 * @param string $block_content Block content.
	 * @param array  $block         Parsed block.
	 *
	 * @return string
	 */
	public static function inject_overlay_gate_after_post_content_block( $block_content, $block ) {
		static $injected = false;

		// $injected prevents re-entry even if render_overlay_gate() bails early (e.g. gate style is not "overlay").
		// $overlay_gate_output is only set when HTML is actually rendered. Both guards are needed.
		if ( $injected || self::$overlay_gate_output || ! is_singular() ) {
			return $block_content;
		}

		if ( 'core/post-content' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		$injected = true;
		ob_start();
		self::render_overlay_gate();
		return $block_content . ob_get_clean();
	}

	/**
	 * Disable popups if rendering a restricted post.
	 *
	 * @param bool $disabled Whether popups are disabled.
	 *
	 * @return bool
	 */
	public static function disable_popups( $disabled ) {
		if (
			is_singular() &&
			self::has_gate() &&
			self::is_post_restricted() &&
			! Metering::is_metering()
		) {
			return true;
		}
		return $disabled;
	}

	/**
	 * Suppress 'article_view' reader activity on locked posts.
	 *
	 * @param array $activity Activity.
	 */
	public static function suppress_article_view_activity( $activity ) {
		if ( Metering::is_frontend_metering() || ( self::is_post_restricted() && ! Metering::is_logged_in_metering_allowed() ) ) {
			return false;
		}
		return $activity;
	}

	/**
	 * Get registration settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Registration settings.
	 */
	public static function get_registration_settings( $gate_id ) {
		$registration = \get_post_meta( $gate_id, 'registration', true );
		if ( empty( $registration ) ) {
			$registration = [];
		}

		$default_metering = [
			'enabled' => false,
			'count'   => 1,
			'period'  => 'month',
		];

		return [
			'active'               => isset( $registration['active'] ) ? (bool) $registration['active'] : false,
			'metering'             => isset( $registration['metering'] ) && is_array( $registration['metering'] ) ? wp_parse_args( $registration['metering'], $default_metering ) : $default_metering,
			'require_verification' => isset( $registration['require_verification'] ) ? (bool) $registration['require_verification'] : false,
			'gate_layout_id'       => isset( $registration['gate_layout_id'] ) ? (int) $registration['gate_layout_id'] : 0,
		];
	}

	/**
	 * Whether the gate requires account verification.
	 *
	 * @param int $gate_id Optional gate ID. Default is the current gate.
	 *
	 * @return bool Whether the gate requires account verification.
	 */
	public static function requires_account_verification( $gate_id = null ) {
		if ( ! $gate_id ) {
			$gate_id = self::get_gate_post_id();
			if ( ! $gate_id ) {
				return false;
			}
		}
		$registration = self::get_registration_settings( $gate_id );
		return $registration['require_verification'];
	}

	/**
	 * Update registration settings for a gate.
	 *
	 * @param int   $gate_id  Gate ID.
	 * @param array $settings Registration settings.
	 *
	 * @return void
	 */
	public static function update_registration_settings( $gate_id, $settings ) {
		$registration = get_post_meta( $gate_id, 'registration', true );
		if ( $registration ) {
			if ( isset( $settings['metering'], $registration['metering'] ) && is_array( $settings['metering'] ) && is_array( $registration['metering'] ) ) {
				$settings['metering'] = wp_parse_args( $settings['metering'], $registration['metering'] );
			}
			$settings = wp_parse_args( $settings, $registration );
		}
		\update_post_meta( $gate_id, 'registration', $settings );
	}

	/**
	 * Get custom access settings for a gate.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return array Custom access settings.
	 */
	public static function get_custom_access_settings( $gate_id ) {
		$custom_access = \get_post_meta( $gate_id, 'custom_access', true );
		if ( empty( $custom_access ) ) {
			$custom_access = [];
		}

		$access_rules = isset( $custom_access['access_rules'] ) ? $custom_access['access_rules'] : [];

		// Normalize legacy flat rules to grouped format.
		$access_rules = Access_Rules::normalize_rules( $access_rules );

		$default_metering = [
			'enabled' => false,
			'count'   => 1,
			'period'  => 'month',
		];

		return [
			'active'                 => isset( $custom_access['active'] ) ? (bool) $custom_access['active'] : false,
			'metering'               => isset( $custom_access['metering'] ) && is_array( $custom_access['metering'] ) ? wp_parse_args( $custom_access['metering'], $default_metering ) : $default_metering,
			'access_rules'           => $access_rules,
			'gate_layout_id'         => isset( $custom_access['gate_layout_id'] ) ? (int) $custom_access['gate_layout_id'] : 0,
			// Defaults to ON so gates saved before the setting existed keep granting
			// access to readers whose subscription is in payment recovery.
			'payment_recovery_grace' => isset( $custom_access['payment_recovery_grace'] ) ? (bool) $custom_access['payment_recovery_grace'] : true,
		];
	}

	/**
	 * Update custom access settings for a gate.
	 *
	 * @param int   $gate_id  Gate ID.
	 * @param array $settings Custom access settings.
	 *
	 * @return void
	 */
	public static function update_custom_access_settings( $gate_id, $settings ) {
		$custom_access = get_post_meta( $gate_id, 'custom_access', true );
		if ( $custom_access ) {
			if ( isset( $settings['metering'], $custom_access['metering'] ) && is_array( $settings['metering'] ) && is_array( $custom_access['metering'] ) ) {
				$settings['metering'] = wp_parse_args( $settings['metering'], $custom_access['metering'] );
			}
			$settings = wp_parse_args( $settings, $custom_access );
		}
		\update_post_meta( $gate_id, 'custom_access', $settings );
	}

	/**
	 * Get gate.
	 *
	 * @param int $id Gate ID.
	 *
	 * @return array|\WP_Error The gate or error if not found.
	 */
	public static function get_gate( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'newspack_content_gate_not_found', __( 'Gate not found.', 'newspack-plugin' ) );
		}

		return [
			'id'                  => $post->ID,
			'status'              => $post->post_status,
			'title'               => $post->post_title,
			'priority'            => (int) get_post_meta( $post->ID, 'gate_priority', true ),
			'content_rules'       => Content_Rules::get_gate_content_rules( $post->ID ),
			'content_rules_match' => Content_Rules::get_gate_content_rules_match( $post->ID ),
			'registration'        => self::get_registration_settings( $post->ID ),
			'custom_access'       => self::get_custom_access_settings( $post->ID ),
		];
	}

	/**
	 * Update single gate setting
	 *
	 * @param int    $id    Gate ID.
	 * @param string $key   Gate setting key.
	 * @param mixed  $value Gate setting value.
	 *
	 * @return array|\WP_Error
	 */
	public static function update_gate_setting( $id, $key, $value ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'newspack_content_gate_not_found', __( 'Gate not found.', 'newspack-plugin' ) );
		}

		$update = [];

		if ( 'title' === $key ) {
			$update['post_title'] = $value;
		} elseif ( 'description' === $key ) {
			$update['post_excerpt'] = $value;
		} elseif ( 'gate_priority' === $key ) {
			$update['meta_input'] = [
				'gate_priority' => (int) $value,
			];
		} elseif ( 'content_rules' === $key ) {
			Content_Rules::update_gate_content_rules( $id, $value );
			return self::get_gate( $id );
		} elseif ( 'content_rules_match' === $key ) {
			Content_Rules::update_gate_content_rules_match( $id, $value );
			return self::get_gate( $id );
		} elseif ( 'registration' === $key ) {
			self::update_registration_settings( $id, $value );
			return self::get_gate( $id );
		} elseif ( 'custom_access' === $key ) {
			self::update_custom_access_settings( $id, $value );
			return self::get_gate( $id );
		} else {
			return new \WP_Error( 'newspack_content_gate_invalid_key', __( 'Invalid gate setting key.', 'newspack-plugin' ) );
		}

		// Update title and description.
		wp_update_post(
			array_merge(
				[
					'ID' => $id,
				],
				$update
			)
		);

		return self::get_gate( $id );
	}

	/**
	 * Update gate settings
	 *
	 * @param int   $id   Gate ID.
	 * @param array $gate Gate settings.
	 *
	 * @return array|\WP_Error
	 */
	public static function update_gate_settings( $id, $gate ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'newspack_content_gate_not_found', __( 'Gate not found.', 'newspack-plugin' ) );
		}

		// Update title, priority, and status.
		$update_args = [
			'ID'          => $id,
			'post_status' => isset( $gate['status'] ) ? $gate['status'] : $post->post_status,
		];
		if ( isset( $gate['title'] ) ) {
			$update_args['post_title'] = $gate['title'];
		}
		if ( isset( $gate['priority'] ) ) {
			$update_args['meta_input'] = [
				'gate_priority' => $gate['priority'],
			];
		}
		wp_update_post( $update_args );

		// Update content rules.
		if ( isset( $gate['content_rules'] ) ) {
			Content_Rules::update_gate_content_rules( $id, $gate['content_rules'] );
		}

		// Update rule-combination mode.
		if ( isset( $gate['content_rules_match'] ) ) {
			Content_Rules::update_gate_content_rules_match( $id, $gate['content_rules_match'] );
		}

		// Update registration settings.
		if ( isset( $gate['registration'] ) ) {
			self::update_registration_settings( $id, $gate['registration'] );
		}

		// Update custom access settings.
		if ( isset( $gate['custom_access'] ) ) {
			self::update_custom_access_settings( $id, $gate['custom_access'] );
		}

		return self::get_gate( $id );
	}

	/**
	 * Get the valid gate post statuses.
	 *
	 * @return array
	 */
	public static function get_post_statuses() {
		/**
		 * Filters the valid post statuses for content gates.
		 *
		 * @param array $valid_post_statuses Valid gate post statuses.
		 */
		return apply_filters( 'newspack_content_gate_valid_post_statuses', self::$valid_gate_post_statuses );
	}

	/**
	 * Option name storing the default status applied to newly created gates.
	 */
	const DEFAULT_STATUS_OPTION = 'newspack_content_gate_default_status';

	/**
	 * Get the default status ('publish' or 'draft') for newly created gates.
	 *
	 * Defaults to 'draft' (inactive) so new gates are set up before going live.
	 * Only affects gates created going forward; existing gates keep their own
	 * status. Publishers can change this default in the Access control preferences.
	 *
	 * @return string
	 */
	public static function get_default_new_gate_status() {
		$value = get_option( self::DEFAULT_STATUS_OPTION, 'draft' );
		return in_array( $value, [ 'publish', 'draft' ], true ) ? $value : 'draft';
	}

	/**
	 * Set the default status for newly created gates.
	 *
	 * @param string $status Either 'publish' or 'draft'.
	 *
	 * @return string The stored status.
	 */
	public static function set_default_new_gate_status( $status ) {
		$status = in_array( $status, [ 'publish', 'draft' ], true ) ? $status : 'draft';
		update_option( self::DEFAULT_STATUS_OPTION, $status, false );
		return $status;
	}

	/**
	 * Fill in the site-wide default status on a new-gate payload when none was provided.
	 *
	 * For REST create endpoints only. Direct PHP callers of create_gate() (e.g. the
	 * WooCommerce Memberships auto-gate creators) rely on its 'publish' fallback,
	 * which must not be routed through this option.
	 *
	 * @param array $gate Gate payload.
	 *
	 * @return array The gate payload with a status.
	 */
	public static function with_default_new_gate_status( $gate ) {
		if ( is_array( $gate ) && ! isset( $gate['status'] ) ) {
			$gate['status'] = self::get_default_new_gate_status();
		}
		return $gate;
	}

	/**
	 * User meta key for the pre-save checklist preference.
	 */
	const PRESAVE_CHECKS_META_KEY = 'np_gate_presave_checks';

	/**
	 * Whether the current user should see the gate pre-save checklist panel.
	 *
	 * Defaults to enabled (true) when the user has never set the preference.
	 *
	 * @return bool
	 */
	public static function get_presave_checks_enabled() {
		$value = get_user_meta( get_current_user_id(), self::PRESAVE_CHECKS_META_KEY, true );
		return '' === $value ? true : '1' === $value;
	}

	/**
	 * Set the pre-save checklist preference for the current user.
	 *
	 * @param bool $enabled Whether the pre-save checklist is enabled.
	 *
	 * @return void
	 */
	public static function set_presave_checks_enabled( $enabled ) {
		update_user_meta( get_current_user_id(), self::PRESAVE_CHECKS_META_KEY, $enabled ? '1' : '0' );
	}

	/**
	 * Get all gates.
	 *
	 * @param string          $post_type Post type.
	 * @param string|string[] $post_status Post status or array of statuses to fetch.
	 * @param bool            $is_newsletter Whether to fetch premium newsletter gates.
	 *
	 * @return array Array of content gates.
	 */
	public static function get_gates( $post_type = self::GATE_CPT, $post_status = null, $is_newsletter = false ) {
		$is_cacheable = self::is_gates_cache_enabled();
		// Keyed by blog as well as by arguments: the cache is a plain static, so it
		// would otherwise outlive a switch_to_blog() and hand one site another
		// site's gates.
		$cache_key = wp_json_encode( [ get_current_blog_id(), $post_type, $post_status, $is_newsletter ] );
		if ( $is_cacheable && isset( self::$gates_cache[ $cache_key ] ) ) {
			return self::$gates_cache[ $cache_key ];
		}
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => $post_status ? $post_status : self::get_post_statuses(),
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Content-gate CPT; config-scale.
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => 'is_newsletter',
						'compare' => $is_newsletter ? 'EXISTS' : 'NOT EXISTS',
					],
				],
			]
		);
		$gates = array_map( [ __CLASS__, 'get_gate' ], wp_list_pluck( $posts, 'ID' ) );
		if ( $post_type === self::GATE_CPT ) {
			usort(
				$gates,
				function( $a, $b ) {
					return $a['priority'] <=> $b['priority'];
				}
			);
		}
		if ( $is_cacheable ) {
			self::$gates_cache[ $cache_key ] = $gates;
		}
		return $gates;
	}

	/**
	 * Whether get_gates() may serve from (and populate) its cache.
	 *
	 * Off by default under PHPUnit: tests are rolled back at the database level,
	 * which fires none of the write hooks the cache is invalidated by, so a gate
	 * created in one test would still be "visible" in the next.
	 *
	 * @return bool
	 */
	private static function is_gates_cache_enabled(): bool {
		if ( null === self::$gates_cache_enabled ) {
			self::$gates_cache_enabled = ! defined( 'IS_TEST_ENV' ) || ! IS_TEST_ENV;
		}
		return self::$gates_cache_enabled;
	}

	/**
	 * Turn the get_gates() cache on or off for the rest of the request.
	 *
	 * Exists so the cache is not merely untested under PHPUnit but untestable:
	 * with the test-env default (off) hard-coded into get_gates(), neither the
	 * cache read, the cache write nor any of the five invalidation hooks could be
	 * exercised at all. A test that covers them turns the cache on for its own
	 * duration and calls this with no argument to restore the default.
	 *
	 * @param bool|null $enabled True/false to force, null to restore the default.
	 */
	public static function set_gates_cache_enabled( ?bool $enabled = null ) {
		self::$gates_cache_enabled = $enabled;
		self::flush_gates_cache();
	}

	/**
	 * Flush the get_gates() cache.
	 *
	 * Hooked to every post and post-meta write rather than only to gate-CPT
	 * writes: gate settings are persisted with bare update_post_meta() calls, and
	 * the hooks that carry a post ID would each need a get_post_type() lookup to
	 * tell a gate write from any other. Flushing unconditionally is cheaper than
	 * that check and can only cost a re-query on requests that write posts.
	 */
	public static function flush_gates_cache() {
		self::$gates_cache = [];
	}

	/**
	 * Get an array of tier-eligible subscription product options, formatted for select controls.
	 *
	 * @return array Array of subscription product options.
	 *              [
	 *                  'label' => Product Name,
	 *                  'value' => product_id,
	 *              ]
	 */
	public static function get_purchasable_product_options() {
		return array_map(
			function( $product ) {
				return [
					'label' => $product->get_name(),
					'value' => (int) $product->get_id(),
				];
			},
			Subscriptions_Tiers::get_tier_eligible_products( [ 'grouped','subscription', 'variable-subscription' ] )
		);
	}
}
Content_Gate::init();
