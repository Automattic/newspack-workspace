<?php
/**
 * WooCommerce Memberships integration class.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Content_Gate;
use Newspack\WooCommerce_Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Memberships {

	use Content_Gate_Layout;

	const GATE_CPT = 'np_memberships_gate';

	const SKIP_RESTRICTION_IN_RSS_OPTION_NAME = 'newspack_skip_content_restriction_in_rss_feeds';

	/**
	 * Membership statuses that should grant access to restricted content.
	 * See: https://woocommerce.com/document/woocommerce-memberships-user-memberships/#section-4
	 *
	 * @var array
	 */
	public static $active_statuses = [ 'active', 'complimentary', 'free_trial', 'pending' ];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Hook into the Content Gate.
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_edit_plan_gate' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_edit_gate' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ], 9 ); // Render before gate layout editor.
		add_filter( 'newspack_post_has_restrictions', [ __CLASS__, 'post_has_restrictions' ], 10, 2 );
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'is_post_restricted' ], 10, 2 );

		// Handle restriction when using metering.
		add_action( 'wp', [ __CLASS__, 'handle_metering_restriction' ], 5 ); // Before Woo Memberships' restriction handler, which was lowered to 9 in 1.27.2.

		// WC Memberships hooks.
		add_filter( 'wc_memberships_notice_html', [ __CLASS__, 'wc_memberships_notice_html' ], 100, 4 );
		add_filter( 'wc_memberships_restricted_message', [ __CLASS__, 'wc_memberships_restricted_message' ], 100, 4 );
		add_filter( 'wc_memberships_restricted_content_excerpt', [ __CLASS__, 'wc_memberships_excerpt' ], 100, 3 );
		add_filter( 'wc_memberships_message_excerpt_apply_the_content_filter', '__return_false' );
		add_filter( 'wc_memberships_admin_screen_ids', [ __CLASS__, 'admin_screens' ] );
		add_filter( 'wc_memberships_general_settings', [ __CLASS__, 'wc_memberships_general_settings' ] );
		add_filter( 'wc_memberships_is_post_public', [ __CLASS__, 'wc_memberships_is_post_public' ] );
		add_action( 'wc_memberships_user_membership_actions', [ __CLASS__, 'user_membership_meta_box_actions' ], 1, 2 );
		add_action( 'admin_init', [ __CLASS__, 'handle_reevaluation_request' ] );
		add_filter( 'user_has_cap', [ __CLASS__, 'user_has_cap' ], 10, 3 );
		add_action( 'wp', [ __CLASS__, 'remove_unnecessary_content_restriction' ], 11 );
		add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'disable_subscription_linked_membership_fields' ] );
		add_action( 'save_post_wc_user_membership', [ __CLASS__, 'prevent_subscription_linked_membership_field_updates' ], 10, 2 );
		add_action( 'post_row_actions', [ __CLASS__, 'prevent_subscription_linked_membership_field_updates_row_actions' ], 20, 2 );
		add_action( 'load-edit.php', [ __CLASS__, 'store_original_membership_data_before_bulk_edit' ] );
		add_action( 'bulk_edit_posts', [ __CLASS__, 'prevent_bulk_edit_subscription_linked_memberships' ], 10, 2 );

		include __DIR__ . '/class-import-export.php';
		include __DIR__ . '/class-membership-expiry.php';
	}

	/**
	 * Check if Memberships is available.
	 */
	public static function is_active() {
		return class_exists( 'WC_Memberships' ) && function_exists( 'wc_memberships' );
	}

	/**
	 * Register post type for custom gate.
	 */
	public static function register_post_type() {
		self::register_layout_post_type( self::GATE_CPT, __( 'Memberships Gate', 'newspack-plugin' ) );
	}

	/**
	 * Redirect the custom gate CPT to the Content Gating wizard
	 */
	public static function redirect_cpt() {
		if ( ! self::is_active() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && $_GET['post_type'] === self::GATE_CPT ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			\wp_safe_redirect( \admin_url( 'admin.php?page=newspack-audience#/content-gating' ) );
			exit;
		}
	}

	/**
	 * Get the URL for editing the custom gate.
	 *
	 * @param int|false $gate_id Gate ID.
	 *
	 * @return string
	 */
	public static function get_edit_gate_url( $gate_id = false ) {
		$action = 'newspack_edit_memberships_gate';
		$url    = \add_query_arg( '_wpnonce', \wp_create_nonce( $action ), \admin_url( 'admin.php?action=' . $action ) );
		if ( $gate_id ) {
			$url = \add_query_arg( 'gate_id', $gate_id, $url );
		}
		return str_replace( \site_url(), '', $url );
	}

	/**
	 * Handle editing the content gate.
	 */
	public static function handle_edit_gate() {
		if ( ! isset( $_GET['action'] ) || 'newspack_edit_memberships_gate' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'newspack_edit_memberships_gate' );

		$gate_post_id = Content_Gate::get_gate_post_id();
		$is_primary   = true;

		if ( isset( $_GET['gate_post_id'] ) ) {
			$gate_post_id = absint( $_GET['gate_post_id'] );
			$is_primary   = false;
			if ( ! $gate_post_id || ! get_post( $gate_post_id ) ) {
				\wp_die( esc_html( __( 'Invalid gate post ID.', 'newspack-plugin' ) ) );
			}
		}

		if ( $gate_post_id && get_post( $gate_post_id ) ) {
			// Untrash post if it's in the trash.
			if ( 'trash' === get_post_status( $gate_post_id ) ) {
				\wp_untrash_post( $gate_post_id );
			}
			// Gate found, edit it.
			\wp_safe_redirect( \admin_url( 'post.php?post=' . $gate_post_id . '&action=edit' ) );
			exit;
		} else {
			// Gate not found, create it.
			$post_title   = __( 'Content Gate', 'newspack-plugin' );
			$gate_post_id = Content_Gate::create_gate( [ 'title' => $post_title ], self::GATE_CPT );
			if ( is_wp_error( $gate_post_id ) ) {
				\wp_die( esc_html( $gate_post_id->get_error_message() ) );
			}
			if ( $is_primary ) {
				self::set_gate_post_id( $gate_post_id );
			}
			\wp_safe_redirect( \admin_url( 'post.php?post=' . $gate_post_id . '&action=edit' ) );
			exit;
		}
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		if ( get_post_type() !== self::GATE_CPT ) {
			return;
		}
		wp_enqueue_script(
			'newspack-content-gate-memberships',
			Newspack::plugin_url() . '/dist/content-gate-editor-memberships.js',
			[],
			filemtime( dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/content-gate-editor-memberships.js' ),
			true
		);
		\wp_localize_script(
			'newspack-content-gate-memberships',
			'newspack_memberships_gate',
			[
				'edit_gate_url'      => self::get_edit_gate_url(),
				'plans'              => self::get_plans(),
				'gate_plans'         => self::get_gate_plans( get_the_ID() ),
				'edit_plan_gate_url' => self::get_edit_plan_gate_url(),
			]
		);
	}

	/**
	 * Set the post ID of the custom Memberships gate.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function set_gate_post_id( $post_id ) {
		\update_option( 'newspack_memberships_gate_post_id', $post_id );
	}

	/**
	 * Get the post ID of the custom gate.
	 *
	 * @param int $post_id      Post ID to find gate for.
	 *
	 * @return int|false Post ID or false if not set.
	 */
	public static function get_gate_post_id( $post_id = null ) {
		$gate_post_id = intval( \get_option( 'newspack_memberships_gate_post_id' ) );
		if ( ! $gate_post_id ) {
			$gate_post_id = false;
		}
		if ( is_singular() ) {
			$post_id = $post_id ? $post_id : get_queried_object_id();
		}
		if ( ! empty( $post_id ) ) {
			$plans = self::get_restricted_post_plans( $post_id );
			$gates = array_map( [ __CLASS__, 'get_plan_gate_id' ], $plans );
			$gates = array_values( array_filter( $gates ) );
			foreach ( $gates as $gate_id ) {
				if ( 'publish' === get_post_status( $gate_id ) ) {
					return $gate_id;
				}
			}
		}
		return $gate_post_id;
	}

	/**
	 * Get all gates.
	 */
	public static function get_gates() {
		return Content_Gate::get_gates( self::GATE_CPT );
	}

	/**
	 * Get the URL for editing the custom gate.
	 *
	 * @param int|false $plan_id Plan ID.
	 *
	 * @return string
	 */
	public static function get_edit_plan_gate_url( $plan_id = false ) {
		$action = 'newspack_edit_memberships_plan_gate';
		$url    = \add_query_arg( '_wpnonce', \wp_create_nonce( $action ), \admin_url( 'admin.php?action=' . $action ) );
		if ( $plan_id ) {
			$url = \add_query_arg( 'plan_id', $plan_id, $url );
		}
		return str_replace( \site_url(), '', $url );
	}

	/**
	 * Create a post for the custom gate.
	 */
	public static function handle_edit_plan_gate() {
		if ( ! isset( $_GET['action'] ) || 'newspack_edit_memberships_plan_gate' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'newspack_edit_memberships_plan_gate' );

		$plan_id = isset( $_GET['plan_id'] ) ? \absint( $_GET['plan_id'] ) : false;
		if ( ! $plan_id ) {
			\wp_die( esc_html( __( 'Plan ID is required.', 'newspack-plugin' ) ) );
		}

		$gate_post_id = self::get_plan_gate_id( $plan_id );

		if ( $gate_post_id && get_post( $gate_post_id ) ) {
			// Untrash post if it's in the trash.
			if ( 'trash' === get_post_status( $gate_post_id ) ) {
				\wp_untrash_post( $gate_post_id );
			}
			// Gate found, edit it.
			\wp_safe_redirect( \admin_url( 'post.php?post=' . $gate_post_id . '&action=edit' ) );
			exit;
		} else {
			// Gate not found, create it.
			$plan = \wc_memberships_get_membership_plan( $plan_id );
			if ( ! $plan ) {
				\wp_die( esc_html( __( 'Plan not found.', 'newspack-plugin' ) ) );
			}
			$post_title = sprintf(
				// Translators: %s is the plan name.
				__( '%s Gate', 'newspack-plugin' ),
				$plan->get_name()
			);
			$gate_post_id = Content_Gate::create_gate( [ 'title' => $post_title ], self::GATE_CPT );
			if ( is_wp_error( $gate_post_id ) ) {
				\wp_die( esc_html( $gate_post_id->get_error_message() ) );
			}
			\update_post_meta( $gate_post_id, 'plans', [ $plan_id ] );
			\wp_safe_redirect( \admin_url( 'post.php?post=' . $gate_post_id . '&action=edit' ) );
			exit;
		}
	}

	/**
	 * Get the gate post object for the given plan.
	 *
	 * @param int $plan_id Plan ID.
	 *
	 * @return int|false Gate post ID or false if not found.
	 */
	public static function get_plan_gate_id( $plan_id ) {
		$gates = get_posts(
			[
				'post_type'      => self::GATE_CPT,
				'post_status'    => [ 'publish', 'draft', 'trash', 'pending', 'future' ],
				'posts_per_page' => -1,
			]
		);
		foreach ( $gates as $gate ) {
			$plans = get_post_meta( $gate->ID, 'plans', true );
			if ( is_array( $plans ) && ! empty( $plans ) && in_array( $plan_id, $plans ) ) {
				return $gate->ID;
			}
		}
		return false;
	}

	/**
	 * Get the gate plans names.
	 *
	 * @param int $gate_id Gate post ID.
	 *
	 * @return string[] Plan names keyed by plan ID.
	 */
	public static function get_gate_plans( $gate_id ) {
		if ( ! self::is_active() || ! function_exists( 'wc_memberships_get_membership_plan' ) ) {
			return [];
		}
		$ids = get_post_meta( $gate_id, 'plans', true );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return [];
		}
		$plans = [];
		foreach ( $ids as $id ) {
			$plan = wc_memberships_get_membership_plan( $id );
			if ( $plan ) {
				$plans[ $id ] = $plan->get_name();
			}
		}
		return $plans;
	}

	/**
	 * Get all plans and their respective gate ID if available.
	 *
	 * @return array
	 */
	public static function get_plans() {
		if ( ! self::is_active() || ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return [];
		}
		$membership_plans = wc_memberships_get_membership_plans();
		$plans            = [];
		foreach ( $membership_plans as $plan ) {
			$plan_id = $plan->get_id();
			$plans[] = [
				'id'          => $plan_id,
				'name'        => $plan->get_name(),
				'gate_id'     => self::get_plan_gate_id( $plan_id ),
				'gate_status' => get_post_status( self::get_plan_gate_id( $plan_id ) ),
				'plan_url'    => get_edit_post_link( $plan_id ),
			];
		}
		return $plans;
	}

	/**
	 * Get the current setting of the "Require memberships in all plans" option.
	 *
	 * @return boolean
	 */
	public static function get_require_all_plans_setting() {
		return \get_option( 'newspack_memberships_require_all_plans', false );
	}

	/**
	 * Set the "Require memberships in all plans" option.
	 *
	 * @param boolean $require False to require membership in any plan restricting content (default)
	 *                         or true to require membership in all plans restricting content.
	 *
	 * @return boolean
	 */
	public static function set_require_all_plans_setting( $require = false ) {
		return \update_option( 'newspack_memberships_require_all_plans', $require );
	}

	/**
	 * Get the current setting of the "Display memberships on the subscriptions tab" option.
	 *
	 * @return boolean
	 */
	public static function get_show_on_subscription_tab_setting() {
		return \get_option( 'newspack_memberships_show_on_subscription_tab', false );
	}

	/**
	 * Set the "Display memberships on the subscriptions tab" option.
	 *
	 * @param boolean $show False to show memberships without subscriptions on the subscriptions tab (default)
	 *                      or true to display those memberships on the subscriptions tab..
	 *
	 * @return boolean
	 */
	public static function set_show_on_subscription_tab_setting( $show = false ) {
		return \update_option( 'newspack_memberships_show_on_subscription_tab', $show );
	}

	/**
	 * Whether the current user is a member of the given plan.
	 *
	 * @param int $plan_id Plan ID.
	 *
	 * @return bool
	 */
	private static function current_user_has_plan( $plan_id ) {
		if ( ! \is_user_logged_in() ) {
			return false;
		}
		if ( ! self::is_active() || ! function_exists( 'wc_memberships_is_user_active_or_delayed_member' ) ) {
			return false;
		}
		return \wc_memberships_is_user_active_or_delayed_member( \get_current_user_id(), $plan_id );
	}

	/**
	 * Get the plans that are currently restricting the given post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int[] Array of plan IDs.
	 */
	public static function get_restricted_post_plans( $post_id ) {
		if ( ! class_exists( 'WC_Memberships' ) ) {
			return [];
		}
		$rules = wc_memberships()->get_rules_instance()->get_post_content_restriction_rules( $post_id );
		if ( ! $rules || empty( $rules ) ) {
			return [];
		}
		$plans = [];
		foreach ( $rules as $rule ) {
			$plan_id = $rule->get_membership_plan_id();
			if ( ! empty( $plan_id ) && ! self::current_user_has_plan( $plan_id ) ) {
				$plans[] = $plan_id;
			}
		}
		return $plans;
	}

	/**
	 * Whether the post has restrictions.
	 *
	 * @param bool $has_restrictions Whether the post has restrictions.
	 * @param int  $post_id          Post ID.
	 *
	 * @return bool
	 */
	public static function post_has_restrictions( $has_restrictions, $post_id = null ) {
		if ( ! self::is_active() || ! function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
			return $has_restrictions;
		}
		return \wc_memberships_is_post_content_restricted( $post_id );
	}

	/**
	 * Whether the post is restricted for the current user.
	 *
	 * @param bool $is_post_restricted Whether the post is restricted for the current user.
	 * @param int  $post_id            Post ID.
	 *
	 * @return bool
	 */
	public static function is_post_restricted( $is_post_restricted, $post_id = null ) {
		// Return early if the post is already restricted for the current user.
		if ( $is_post_restricted ) {
			return $is_post_restricted;
		}

		if ( ! class_exists( 'WC_Memberships' ) ) {
			return false;
		}
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! self::is_active() || ! function_exists( 'wc_memberships_is_post_content_restricted' ) || ! \wc_memberships_is_post_content_restricted( $post_id ) ) {
			return false;
		}
		return ! is_user_logged_in() || ! current_user_can( 'wc_memberships_view_restricted_post_content', $post_id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown
	}

	/**
	 * Custom handling of content restriction when using metering.
	 */
	public static function handle_metering_restriction() {
		if ( ! class_exists( 'WC_Memberships' ) ) {
			return;
		}
		if ( ! \is_singular() || ! Content_Gate::is_post_restricted() || ! Metering::is_metering() ) {
			return;
		}

		// Remove the default restriction handler from 'SkyVerge\WooCommerce\Memberships\Restrictions\Posts::restrict_post'.
		$restriction_instance = \wc_memberships()->get_restrictions_instance()->get_posts_restrictions_instance();
		\remove_action( 'wp', spl_object_hash( $restriction_instance ) . 'handle_restriction_modes', 9 );
		\remove_action( 'wp', spl_object_hash( $restriction_instance ) . 'handle_restriction_modes' ); // For compatibility with Woo Memberships < 1.27.2.
		\add_filter( 'wc_memberships_restrictable_comment_types', '__return_empty_array' );
	}

	/**
	 * Filter WooCommerce Memberships' notice HTML.
	 *
	 * @param string $notice Notice HTML.
	 * @param string $message_body original message content.
	 * @param string $message_code message code.
	 * @param array  $message_args associative array of message arguments.
	 */
	public static function wc_memberships_notice_html( $notice, $message_body, $message_code, $message_args ) {
		// If the gate is not available, don't mess with the notice.
		// Membership notices are only displayed on products with discounts from plans. The is_product() check makes sure that still works as normal.
		if ( ! Content_Gate::has_gate() || is_product() ) {
			return $notice;
		}
		// Don't show gate unless attached to a specific post.
		if ( empty( $message_args['post'] ) ) {
			return '';
		}
		// If rendering the content in a loop, don't render the gate.
		if ( get_queried_object_id() !== get_the_ID() ) {
			return '';
		}
		Content_Gate::mark_gate_as_rendered();
		return Content_Gate::get_inline_gate_html();
	}

	/**
	 * Filter WooCommerce Memberships' restricted message before it is rendered.
	 *
	 * When all WC Memberships message templates are set to empty strings, the
	 * `get_message_html()` method skips `get_notice_html()` entirely — which
	 * means `wc_memberships_notice_html` never fires and Newspack's gate is
	 * never rendered.  We work around this by returning a non-empty placeholder
	 * here so that `get_notice_html()` is always called when a Newspack gate is
	 * configured for a content-restriction context.  The placeholder is then
	 * replaced by the real gate HTML inside `wc_memberships_notice_html()`.
	 *
	 * @param string $message      Restriction message text (may be empty).
	 * @param object $post         Post object.
	 * @param string $message_code Message code (e.g. 'content_delayed', 'content_restricted').
	 * @param array  $args         Additional arguments.
	 *
	 * @return string
	 */
	public static function wc_memberships_restricted_message( $message, $post, $message_code, $args ) {
		// Only intervene when the message is empty — a non-empty message means
		// the publisher configured their own copy and we should leave it alone.
		if ( '' !== trim( $message ) ) {
			return $message;
		}
		// Only replace for content-restriction contexts (not product discounts etc.).
		if ( ! str_contains( $message_code, 'content_' ) ) {
			return $message;
		}
		// Only replace when a Newspack gate is configured.
		if ( ! Content_Gate::has_gate() ) {
			return $message;
		}
		// Return a single non-breaking space so that get_message_html() proceeds
		// to call get_notice_html() → wc_memberships_notice_html, where the
		// real gate HTML will be substituted.
		return '&nbsp;';
	}

	/**
	 * Filter WooCommerce Memberships' generated excerpt for restricted content.
	 *
	 * @param string $excerpt      Excerpt.
	 * @param object $post         Post object.
	 * @param string $message_code Message code.
	 *
	 * @return string
	 */
	public static function wc_memberships_excerpt( $excerpt, $post, $message_code ) {
		// If the gate is not available, don't mess with the excerpt.
		// Products with discounts from plans also display this excerpt; the is_product() check makes sure that still works as normal.
		if ( ! Content_Gate::has_gate() || is_product() ) {
			return $excerpt;
		}
		// If rendering the content in a loop, don't truncate the excerpt.
		if ( get_queried_object_id() !== $post->ID ) {
			return $excerpt;
		}
		return Content_Gate::get_restricted_post_excerpt( $post );
	}

	/**
	 * Check if the passed in caps contain a positive 'manage_woocommerce' capability.
	 * Copied from the WooCommerce Memberships plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param array $caps Capabilities.
	 * @return bool
	 */
	private static function can_manage_woocommerce( $caps ) {
		return isset( $caps['manage_woocommerce'] ) && $caps['manage_woocommerce'];
	}

	/**
	 * Checks if a user has a certain capability.
	 * Overrides behvavior from the WooCommerce Memberships plugin to decide whether to show restricted content.
	 *
	 * @internal
	 *
	 * @since 1.0.0
	 *
	 * @param array $all_caps All capabilities.
	 * @param array $caps Capabilities.
	 * @param array $args Capability arguments.
	 * @return array Filtered capabilities.
	 */
	public static function user_has_cap( $all_caps, $caps, $args ) {
		if ( ! did_action( 'wp' ) ) {
			return $all_caps;
		}

		// Bail if Woo Memberships is not active or if this is a product.
		if ( ! self::is_active() || is_product() ) {
			return $all_caps;
		}

		if ( ! empty( $caps ) ) {
			foreach ( $caps as $cap ) {

				switch ( $cap ) {
					case 'wc_memberships_access_all_restricted_content':
					case 'wc_memberships_view_restricted_product':
					case 'wc_memberships_purchase_restricted_product':
					case 'wc_memberships_view_restricted_product_taxonomy_term':
					case 'wc_memberships_view_delayed_product_taxonomy_term':
					case 'wc_memberships_view_restricted_taxonomy_term':
					case 'wc_memberships_view_restricted_taxonomy':
					case 'wc_memberships_view_restricted_post_type':
					case 'wc_memberships_view_delayed_post_type':
					case 'wc_memberships_view_delayed_taxonomy':
					case 'wc_memberships_view_delayed_taxonomy_term':
					case 'wc_memberships_view_delayed_post_content':
					case 'wc_memberships_view_restricted_post_content':
						if ( self::can_manage_woocommerce( $all_caps ) ) {
							$all_caps[ $cap ] = true;
							break;
						}

						// Allow user who can edit posts (by default: editors, authors, contributors).
						if ( isset( $all_caps['edit_posts'] ) && true === $all_caps['edit_posts'] ) {
							$all_caps[ $cap ] = true;
							break;
						}

						if ( ! isset( $args[1] ) || ! isset( $args[2] ) ) {
							break;
						}

						$user_id = (int) $args[1];
						$post_id = (int) $args[2];

						if ( wc_memberships()->get_restrictions_instance()->is_post_public( $post_id ) ) {
							$all_caps[ $cap ] = true;
							break;
						}

						$rules            = wc_memberships()->get_rules_instance()->get_post_content_restriction_rules( $post_id );
						$all_caps[ $cap ] = self::user_has_content_access_from_rules( $user_id, $rules, $post_id );

						break;

					case 'wc_memberships_view_delayed_product':
						// Allow users who can edit posts (by default: editors, authors, contributors).
						if ( isset( $all_caps['edit_posts'] ) && true === $all_caps['edit_posts'] ) {
							$all_caps[ $cap ] = true;
							break;
						}
						break;
				}
			}
		}

		return $all_caps;
	}

	/**
	 * Checks if a user has content access from rules.
	 * Overrides behvavior from the WooCommerce Memberships plugin to decide whether to show restricted content.
	 * Default behavior matches the WooCommerce Memberships plugin: if a user matches ANY applicable membership
	 * plan rules, they are granted access to the content.
	 *
	 * Custom behavior: If the "Require membership in all plans" option is enabled in the Engagement wizard,
	 * then a user must match ALL applicable membership plan rules before being granted access to the content.
	 *
	 * @since 1.9.0
	 *
	 * @param int                                    $user_id WP_User ID.
	 * @param \WC_Memberships_Membership_Plan_Rule[] $rules array of rules to search access from.
	 * @param int                                    $object_id Optional object ID to check access for (defaults to null).
	 * @return bool returns true if there are no rules at all (users always have access).
	 */
	private static function user_has_content_access_from_rules( $user_id, array $rules, $object_id = null ) {
		// Return true if there are no rules at all (users always have access).
		if ( empty( $rules ) ) {
			return true;
		}

		$require_all_plans = self::get_require_all_plans_setting();
		$has_access        = false;
		$has_subscription  = false;

		foreach ( $rules as $rule ) {
			$membership_plan_id = $rule->get_membership_plan_id();
			$has_subscription   = ! empty( self::get_user_subscription_for_membership_plan( $user_id, $membership_plan_id ) );

			// If no object ID is provided, then we are looking at rules that apply to whole post types or taxonomies.
			// In this case, rules that apply to specific objects should be skipped.
			if ( empty( $object_id ) && $rule->has_objects() ) {
				continue;
			}

			if ( $has_subscription || wc_memberships_is_user_active_or_delayed_member( $user_id, $rule->get_membership_plan_id() ) ) {
				$has_access = true;
				if ( ! $require_all_plans ) {
					break;
				}
			} elseif ( $require_all_plans ) {
				$has_access = false;
				break;
			}
		}

		return $has_access;
	}

	/**
	 * Remove content restriction on the front page and archives, to increase performance.
	 * The only thing Memberships would really do on these pages is add a "You need a membership"-type message in excerpts.
	 */
	public static function remove_unnecessary_content_restriction() {
		if ( ( is_front_page() || is_archive() ) && function_exists( 'wc_memberships' ) ) {
			$memberships = wc_memberships();
			$restrictions_instance = $memberships->get_restrictions_instance();
			$posts_restrictions_instance = $restrictions_instance->get_posts_restrictions_instance();
			remove_action( 'the_post', [ $posts_restrictions_instance, 'restrict_post' ], 0 );
			remove_filter( 'the_content', [ $posts_restrictions_instance, 'handle_restricted_post_content_filtering' ], 999 );
			remove_action( 'loop_start', [ $posts_restrictions_instance, 'display_restricted_taxonomy_term_notice' ], 1 );
		}
	}

	/**
	 * Admin meta boxes handling.
	 *
	 * @param array $screen_ids associative array organized by context.
	 */
	public static function admin_screens( $screen_ids ) {
		$unrestrictable_post_types = [ 'partner_rss_feed' ];
		$screen_ids['meta_boxes'] = array_filter(
			$screen_ids['meta_boxes'],
			function( $screen_id ) use ( $unrestrictable_post_types ) {
				$allow_restrictions = true;
				foreach ( $unrestrictable_post_types as $post_type ) {
					// Use strpos instead of full string match, because each CPT get two items in this array:
					// the `<CPT>` and `edit-<CPT>`.
					if ( strpos( $screen_id, $post_type ) !== false ) {
						$allow_restrictions = false;
					}
				}
				return $allow_restrictions;
			}
		);
		return $screen_ids;
	}

	/**
	 * Check if the content should be restricted by WooCommerce Memberships.
	 *
	 * @param bool $is_public whether the post is public (default false unless explicitly marked as public by an admin).
	 */
	public static function wc_memberships_is_post_public( $is_public ) {
		if ( is_feed() && 'yes' === get_option( self::SKIP_RESTRICTION_IN_RSS_OPTION_NAME ) ) {
			return true;
		}
		return $is_public;
	}

	/**
	 * Add a setting to skip content restrictions in RSS feeds.
	 *
	 * @param array $settings associative array of the plugin settings.
	 */
	public static function wc_memberships_general_settings( $settings ) {
		$setting = [
			'type'    => 'checkbox',
			'id'      => self::SKIP_RESTRICTION_IN_RSS_OPTION_NAME,
			'name'    => __( 'Skip content restriction in RSS feeds', 'newspack-plugin' ),
			'desc'    =>
				'<span class="show-if-hide-content-only-restriction-mode">' . __( 'If enabled, full content will be available in RSS feeds.', 'newspack-plugin' ) . '</span>',
			'default' => 'no',
		];

		$position_of_show_excerpts_setting = array_search( 'wc_memberships_show_excerpts', array_column( $settings, 'id' ) );
		return array_slice( $settings, 0, $position_of_show_excerpts_setting, true ) +
			[ $setting['id'] => $setting ] +
			array_slice( $settings, $position_of_show_excerpts_setting, null, true );
	}

	/**
	 * Add relevant body CSS classnames.
	 *
	 * @param array $classes Array of body class names.
	 */
	public static function add_body_class( $classes ) {
		// If a user has a paid membership, add a body class.
		if ( ! function_exists( 'wc_memberships_get_user_active_memberships' ) ) {
			return $classes;
		}

		$user_active_memberships = \wc_memberships_get_user_active_memberships();
		foreach ( $user_active_memberships as $membership ) {
			$plan = $membership->plan;
			if ( $plan ) {
				$plan_products = $membership->get_plan()->get_product_ids();
				$classes[] = 'is-member-' . $plan->slug;
				$paid_plan_classname = 'is-paid-plan-member';
				if ( ! empty( $plan_products ) && ! in_array( $paid_plan_classname, $classes ) ) {
					$classes[] = $paid_plan_classname;
				}
			}
		}
		return $classes;
	}

	/**
	 * Does the given user have an active subscription with the product required by the given membership plan?
	 *
	 * @param int $user_id User ID.
	 * @param int $membership_plan_id Membership plan ID.
	 *
	 * @return int Subscription ID if the user has an active subscription with the required product. False if the user does not have the required subscription. Null if the membership plan doesn't require a subscription.
	 */
	public static function get_user_subscription_for_membership_plan( $user_id, $membership_plan_id ) {
		$integrations = wc_memberships()->get_integrations_instance();
		$integration  = $integrations ? $integrations->get_subscriptions_instance() : null;
		if ( ! $integration || ! $integration->has_membership_plan_subscription( $membership_plan_id ) ) {
			return null;
		}

		$subscription_plan  = new \WC_Memberships_Integration_Subscriptions_Membership_Plan( $membership_plan_id );
		$required_products  = $subscription_plan->get_subscription_product_ids();
		$user_subscriptions = WooCommerce_Connection::get_active_subscriptions_for_user( $user_id, $required_products );
		if ( empty( $user_subscriptions ) ) {
			return false;
		}

		return (int) reset( $user_subscriptions );
	}

	/**
	 * Handle reevaluation request, triggered by the User Membership meta box action.
	 * Membership and subscription can get unlinked, this will help the administrator
	 * resync the membership status after relinking the subscription.
	 */
	public static function handle_reevaluation_request() {
		if ( isset( $_GET['reevaluate'] ) && isset( $_GET['post'] ) && function_exists( 'wcs_get_subscription' ) && function_exists( 'wc_memberships_get_user_membership' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$subscription = \wcs_get_subscription( absint( $_GET['reevaluate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$membership = \wc_memberships_get_user_membership( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $subscription instanceof \WC_Subscription && $membership ) {
				$integrations = wc_memberships()->get_integrations_instance();
				$integration  = $integrations ? $integrations->get_subscriptions_instance() : null;
				if ( $integration ) {
					$has_same_status = $integration->has_subscription_same_status( $subscription, $membership );
					if ( ! $has_same_status ) {
						$integration->update_related_membership_status(
							$subscription,
							$membership,
							$subscription->get_status()
						);
					}
				}

				wp_safe_redirect( remove_query_arg( 'reevaluate' ) );
				exit;
			}
		}
	}

	/**
	 * Adds User Membership meta box actions.
	 *
	 * @param array $actions associative array.
	 * @param int   $user_membership_id \WC_Membership_User_Membership post ID.
	 * @return array
	 */
	public static function user_membership_meta_box_actions( $actions, $user_membership_id ) {
		$integration  = wc_memberships()->get_integrations_instance()->get_subscriptions_instance();
		$subscription = $integration ? $integration->get_subscription_from_membership( $user_membership_id ) : null;

		if ( $subscription instanceof \WC_Subscription ) {
				$actions = array_merge(
					[
						'reevaluate' => [
							'link'              => admin_url( 'post.php?post=' . $user_membership_id . '&action=edit&reevaluate=' . $subscription->get_id() ),
							'text'              => __( 'Reevaluate status', 'newspack-plugin' ),
							'custom_attributes' => [
								'title' => __( 'Reevaluate status based on subscription status.', 'newspack-plugin' ),
							],
						],
					],
					$actions
				);
		}
		return $actions;
	}

	/**
	 * Should editing the status be disabled for a membership?
	 *
	 * @param \WP_Post $post Membership post object.
	 */
	public static function should_disable_editing_membership_status( $post ): bool {
		// Ensure we have a valid post.
		if ( ! $post || 'wc_user_membership' !== $post->post_type ) {
			return false;
		}
		$subscription_id = get_post_meta( $post->ID, '_subscription_id', true );
		return ! empty( $subscription_id );
	}

	/**
	 * Disable Status, Member since, and Expires fields for subscription-linked memberships.
	 */
	public static function disable_subscription_linked_membership_fields() {
		global $current_screen, $post;

		// Only apply on user membership edit screen.
		if ( ! $current_screen || 'wc_user_membership' !== $current_screen->id || 'post' !== $current_screen->base ) {
			return;
		}

		if ( ! self::should_disable_editing_membership_status( $post ) ) {
			return;
		}

		// Enqueue JavaScript to disable the fields.
		wp_add_inline_script(
			'jquery',
			'
			jQuery(document).ready(function($) {
				function disableFields() {
					// Disable Status field (Select2-based)
					$(".plan-details #post_status").prop("readonly", true).css("opacity", "0.6");
					$("#post_status").next(".select2-container").css("opacity", "0.6").css("pointer-events", "none");

					// Disable Member since fields
					$("#_start_date").prop("readonly", true).css("opacity", "0.6");
					$("#_start_date").next(".ui-datepicker-trigger").css("display", "none");

					// Disable Expires fields
					$("#_end_date").prop("readonly", true).css("opacity", "0.6");
					$("#_end_date").next(".ui-datepicker-trigger").css("display", "none");

					// Add visual indication that fields are readonly
					$("#post_status, #_start_date, #_end_date").each(function() {
						var container = $(this).closest("p, .form-field");
						if (!container.find(".subscription-linked-notice").length) {
							container.append("<small class=\"subscription-linked-notice\" style=\"color: #666; font-style: italic; display: block; margin-top: 5px;\">This field cannot be edited because this membership is linked to a subscription.</small>");
						}
					});
				}

				// Initial disable
				disableFields();

				// Re-disable after Select2 initialization (it may re-enable the field)
				setTimeout(disableFields, 500);

				// Watch for Select2 events and re-disable if needed
				$(document).on("select2:opening", "#post_status", function(e) {
					e.preventDefault();
					return false;
				});
			});
		'
		);
	}

	/**
	 * Removes edit screen row actions from subscription-linked user memberships.
	 *
	 * @param array    $actions associative array of row actions.
	 * @param \WP_Post $post related membership post object.
	 * @return array
	 */
	public static function prevent_subscription_linked_membership_field_updates_row_actions( $actions, $post ) {
		if ( self::should_disable_editing_membership_status( $post ) ) {
			unset( $actions['pause'], $actions['cancel'], $actions['delete'] );
		}
		return $actions;
	}

	/**
	 * Prevent updating Status, Member since, and Expires fields for subscription-linked memberships.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function prevent_subscription_linked_membership_field_updates( $post_id, $post ) {
		// Skip if not a user membership post type.
		if ( 'wc_user_membership' !== $post->post_type ) {
			return;
		}

		// Skip during autosave.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Check if membership is linked to a subscription.
		$subscription_id = get_post_meta( $post_id, '_subscription_id', true );
		if ( empty( $subscription_id ) ) {
			return;
		}

		// Get the original post data before the update.
		$original_post = get_post( $post_id );

		// Restore original status if it was changed.
		if ( $original_post && $original_post->post_status !== $post->post_status ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => $original_post->post_status,
				]
			);
		}

		// Restore original start date if it was changed.
		$original_start_date = get_post_meta( $post_id, '_start_date', true );

		if ( isset( $_POST['_start_date'] ) && $_POST['_start_date'] !== $original_start_date ) { // phpcs:disable WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, '_start_date', $original_start_date );
		}

		// Restore original end date if it was changed.
		$original_end_date = get_post_meta( $post_id, '_end_date', true );
		if ( isset( $_POST['_end_date'] ) && $_POST['_end_date'] !== $original_end_date ) { // phpcs:disable WordPress.Security.NonceVerification.Missing
			update_post_meta( $post_id, '_end_date', $original_end_date );
		}
	}

	/**
	 * Store original membership data before bulk edit to allow restoration.
	 */
	public static function store_original_membership_data_before_bulk_edit() {
		// Only on wc_user_membership edit page.
		if ( ! isset( $_GET['post_type'] ) || 'wc_user_membership' !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// Check if this is a bulk edit request.
		if ( ! isset( $_REQUEST['bulk_edit'] ) && ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// Get post IDs from bulk edit.
		$post_ids = [];
		if ( isset( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$post_ids = array_map( 'intval', $_REQUEST['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( empty( $post_ids ) ) {
			return;
		}

		// Store original data for subscription-linked memberships.
		$original_data = [];
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'wc_user_membership' === $post->post_type && self::should_disable_editing_membership_status( $post ) ) {
				$original_data[ $post_id ] = [
					'post_status' => $post->post_status,
					'_start_date' => get_post_meta( $post_id, '_start_date', true ),
					'_end_date'   => get_post_meta( $post_id, '_end_date', true ),
				];
			}
		}

		if ( ! empty( $original_data ) ) {
			set_transient( 'newspack_membership_original_data_' . get_current_user_id(), $original_data, 300 );
		}
	}

	/**
	 * Prevent bulk editing of subscription-linked memberships.
	 * This fires after bulk edits are processed and restores original values for subscription-linked memberships.
	 *
	 * @param array $post_ids Array of post IDs that were bulk edited.
	 * @param array $edit_data Array of edit data from the bulk edit form.
	 */
	public static function prevent_bulk_edit_subscription_linked_memberships( $post_ids, $edit_data ) {
		// Only handle wc_user_membership posts.
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return;
		}

		// Get stored original data.
		$original_data = get_transient( 'newspack_membership_original_data_' . get_current_user_id() );
		if ( ! $original_data ) {
			return;
		}

		$reverted_count = 0;

		foreach ( $post_ids as $post_id ) {
			// Skip if we don't have original data for this post or it's not subscription-linked.
			if ( ! isset( $original_data[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post || 'wc_user_membership' !== $post->post_type ) {
				continue;
			}

			// Restore original post status.
			if ( $post->post_status !== $original_data[ $post_id ]['post_status'] ) {
				wp_update_post(
					[
						'ID'          => $post_id,
						'post_status' => $original_data[ $post_id ]['post_status'],
					]
				);
				$reverted_count++;

				// Add a note to the user membership explaining why the changes were reverted.
				if ( function_exists( 'wc_memberships_get_user_membership' ) ) {
					$membership = wc_memberships_get_user_membership( $post_id );
					if ( $membership ) {
						$membership->add_note( __( 'Bulk edit changes were reverted because this membership is linked to a subscription.', 'newspack-plugin' ) );
					}
				}
			}

			// Restore original start date.
			$current_start_date = get_post_meta( $post_id, '_start_date', true );
			if ( $current_start_date !== $original_data[ $post_id ]['_start_date'] ) {
				update_post_meta( $post_id, '_start_date', $original_data[ $post_id ]['_start_date'] );
			}

			// Restore original end date.
			$current_end_date = get_post_meta( $post_id, '_end_date', true );
			if ( $current_end_date !== $original_data[ $post_id ]['_end_date'] ) {
				update_post_meta( $post_id, '_end_date', $original_data[ $post_id ]['_end_date'] );
			}
		}

		// Clean up stored data.
		delete_transient( 'newspack_membership_original_data_' . get_current_user_id() );
	}
}
Memberships::init();
