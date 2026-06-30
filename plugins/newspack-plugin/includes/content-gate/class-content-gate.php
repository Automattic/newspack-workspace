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
	private static $gate_rendered = false;

	/**
	 * Whether the gate is being rendered.
	 *
	 * @var boolean
	 */
	private static $is_gated = false;

	/**
	 * Whether the post is being shown via metering.
	 *
	 * @var boolean
	 */
	private static $is_metered = false;

	/**
	 * Valid gate post statuses.
	 *
	 * @var array
	 */
	public static $valid_gate_post_statuses = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];

	/**
	 * Restricted content per post ID.
	 *
	 * @var string[]
	 */
	private static $restricted_content = [];

	/**
	 * Whether the overlay gate markup has been output in this execution.
	 *
	 * @var boolean
	 */
	private static $overlay_gate_output = false;

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
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_popups' ] );
		add_filter( 'newspack_reader_activity_article_view', [ __CLASS__, 'suppress_article_view_activity' ], 100 );

		add_action( 'the_post', [ __CLASS__, 'restrict_post' ], 10, 2 );
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
			// Content is accessible via metering — show comments but prevent commenting.
			self::$is_metered        = true;
			$post->comment_status    = 'closed';
			return;
		}

		self::$is_gated = true;

		$content = self::get_restricted_post_excerpt( $post );

		$post->post_content   = $content . self::get_inline_gate_html();
		$post->post_excerpt   = $content;
		$post->comment_status = 'closed';
		$post->comment_count  = 0;

		self::$restricted_content[ $post->ID ] = $post->post_content;

		self::mark_gate_as_rendered();
	}

	/**
	 * Handle restricted post content filtering.
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	public static function handle_restricted_content( $content ) {
		if ( ! isset( self::$restricted_content[ get_the_ID() ] ) ) {
			return $content;
		}
		return self::$restricted_content[ get_the_ID() ];
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
	 * Close comments on gated and metered posts.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 *
	 * @return bool
	 */
	public static function filter_comments_open( $open, $post_id ) {
		if ( ( self::$is_gated || self::$is_metered ) && (int) $post_id === (int) get_queried_object_id() ) {
			return false;
		}
		return $open;
	}

	/**
	 * Filter comments array.
	 *
	 * Hide all comments on fully gated posts.
	 *
	 * @param array $comments Array of comments.
	 * @param int   $post_id  Post ID.
	 *
	 * @return array
	 */
	public static function filter_comments_array( $comments, $post_id ) {
		if ( self::$is_gated && (int) $post_id === (int) get_queried_object_id() ) {
			return [];
		}
		return $comments;
	}

	/**
	 * Filter the comment count.
	 *
	 * Return 0 on fully gated posts.
	 *
	 * @param int $count   Comment count.
	 * @param int $post_id Post ID.
	 *
	 * @return int
	 */
	public static function filter_comments_number( $count, $post_id ) {
		if ( self::$is_gated && (int) $post_id === (int) get_queried_object_id() ) {
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
			wp_enqueue_script( 'newspack-content-banner', Newspack::plugin_url() . '/dist/content-banner.js', $asset['dependencies'], NEWSPACK_PLUGIN_VERSION, true );
			wp_enqueue_style( 'newspack-content-banner', Newspack::plugin_url() . '/dist/content-banner.css', [], NEWSPACK_PLUGIN_VERSION );
		}
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
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
	 * Whether any gates of the given type has metering enabled.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return bool
	 */
	public static function is_metering_enabled( $post_type = self::GATE_CPT ) {
		$gates = self::get_gates( $post_type );
		foreach ( $gates as $gate ) {
			if ( isset( $gate['metering'] ) && ! empty( $gate['metering']['enabled'] ) ) {
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
	 * Create a new gate post.
	 *
	 * @param array  $gate Gate settings.
	 * @param string $post_type Optional post type. Defaults to self::GATE_CPT.
	 * @param bool   $is_newsletter Whether the gate is for a newsletter.
	 *
	 * @return int|\WP_Error The gate post ID or error if not created.
	 */
	public static function create_gate( $gate, $post_type = self::GATE_CPT, $is_newsletter = false ) {
		$all_gates = self::get_gates();
		$args      = [
			'post_title'   => $gate['title'],
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'post_content' => '',
			'meta_input'   => [
				'gate_priority' => count( $all_gates ),
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
		$registration_settings  = $gate['registration'] ?? [];
		$registration_layout_id = $registration_settings['gate_layout_id'] ?? 0;
		$custom_access_settings  = $gate['custom_access'] ?? [];
		$custom_access_layout_id = $custom_access_settings['gate_layout_id'] ?? 0;

		if ( ! $registration_layout_id ) {
			$registration_content   = self::get_layout_default_content( $gate_id, 'registration', $registration_settings, $custom_access_settings );
			$registration_layout_id = self::create_gate_layout(
				__( 'Registration Access Layout', 'newspack-plugin' ),
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
				__( 'Paid Access Layout', 'newspack-plugin' ),
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
			if ( ! empty( $custom_access_settings['active'] ) ) {
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
			'metering'             => isset( $registration['metering'] ) ? $registration['metering'] : $default_metering,
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
			'active'         => isset( $custom_access['active'] ) ? (bool) $custom_access['active'] : false,
			'metering'       => isset( $custom_access['metering'] ) ? $custom_access['metering'] : $default_metering,
			'access_rules'   => $access_rules,
			'gate_layout_id' => isset( $custom_access['gate_layout_id'] ) ? (int) $custom_access['gate_layout_id'] : 0,
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
		wp_update_post(
			[
				'ID'          => $id,
				'post_title'  => $gate['title'],
				'post_status' => isset( $gate['status'] ) ? $gate['status'] : $post->post_status,
				'meta_input'  => [
					'gate_priority' => $gate['priority'],
				],
			]
		);

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
	 * Get all gates.
	 *
	 * @param string          $post_type Post type.
	 * @param string|string[] $post_status Post status or array of statuses to fetch.
	 * @param bool            $is_newsletter Whether to fetch premium newsletter gates.
	 *
	 * @return array Array of content gates.
	 */
	public static function get_gates( $post_type = self::GATE_CPT, $post_status = null, $is_newsletter = false ) {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => $post_status ? $post_status : self::get_post_statuses(),
				'posts_per_page' => -1,
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
		return $gates;
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
