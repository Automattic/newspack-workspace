<?php
/**
 * Newspack Listings - Handles user permissions for self-serve listings customers.
 * Customers are WooCommerce customers who have purchased at least one self-serve
 * listing product.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
final class Products_User extends Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// WP user actions to add capabilities and admin access for WC customers, who normally can't access the post editor.
		add_filter( 'user_has_cap', [ $this, 'allow_customers_to_edit_own_posts' ], 10, 3 );
		add_filter( 'allowed_block_types_all', [ $this, 'restrict_blocks_for_customers' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'hide_admin_menu_for_customers' ], PHP_INT_MAX ); // Late execution to override other plugins like Jetpack.
		add_filter( 'admin_bar_menu', [ $this, 'hide_admin_bar_for_customers' ], PHP_INT_MAX ); // Late execution to override other plugins like Jetpack.
		add_action( 'admin_page_access_denied', [ $this, 'redirect_to_dashboard' ] );
	}

	/**
	 * Filter user capability check. Customers who have purchased a listing item have the edit_posts capability added
	 * so that they can be assigned as an author to listing posts, edit those posts, and submit for review to publish.
	 * However, we only want them to be able to edit the specific posts they've purchased, not create new ones.
	 * So we should remove the edit_posts capability if the user tries to edit another post create a new one,
	 * or access other WordPress admin pages that are usually allowed under the edit_posts capability.
	 *
	 * @param bool[]   $allcaps Array of key/value pairs where keys represent a capability name and
	 *                          boolean values represent whether the user has that capability.
	 * @param string[] $caps Required primitive capabilities for the requested capability.
	 * @param array    $args Arguments that accompany the requested capability check.
	 *
	 * @return bool[] Filtered array of allowed/disallowed capabilities.
	 */
	public function allow_customers_to_edit_own_posts( $allcaps, $caps, $args ) {
		$capabilities        = [ 'edit_post', 'edit_posts', 'edit_published_posts', 'publish_posts' ];
		$capability          = $args[0];
		$user_id             = $args[1];
		$post_id             = isset( $args[2] ) ? $args[2] : null;
		$is_listing_customer = false;

		if ( in_array( $capability, $capabilities ) && $user_id ) {
			$is_listing_customer = self::is_listing_customer( $user_id );
		}

		if ( (bool) $is_listing_customer ) {
			global $pagenow;
			$is_published   = 'publish' === get_post_status( get_the_ID() );
			$actions        = [ 'edit', 'editposts' ];
			$action         = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( sanitize_text_field( $_REQUEST['action'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$context        = isset( $_REQUEST['context'] ) ? sanitize_text_field( wp_unslash( sanitize_text_field( $_REQUEST['context'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_edit_screen = ( $action && in_array( $action, $actions, true ) ) || ( $context && in_array( $context, $actions, true ) );

			// If not an edit screen, if the post ID isn't set or isn't in the user's allowed post IDs,
			// or the user is trying to access an admin page other than the post editor, disallow.
			if ( ! $is_edit_screen || 'post.php' !== $pagenow ) {
				$allcaps[ $capability ] = 0;
			}

			// If on the edit screen for a post that has already been published, allow the user to update their published post.
			if ( 'publish_posts' === $capability ) {
				$allcaps[ $capability ] = $is_published ? 1 : 0;
			}

			// As of WP 5.9, some capabilities in the block editor are checked via REST API. e.g. reusable blocks and featured images.
			if ( 'edit_posts' === $capability && $is_edit_screen ) {
				$allcaps[ $capability ] = 1;
			}
		}

		return $allcaps;
	}

	/**
	 * Customer users should have access to a basic set of core blocks only.
	 *
	 * @param bool|array              $allowed_block_types Array of block type slugs, or boolean to enable/disable all. Default true (all registered block types supported).
	 * @param WP_Block_Editor_Context $block_editor_context The current block editor context.
	 *
	 * @return bool|array Filtered boolean or array of allowed blocks.
	 */
	public function restrict_blocks_for_customers( $allowed_block_types, $block_editor_context ) {
		$is_listing_customer = self::is_listing_customer();

		if ( $is_listing_customer ) {
			$allowed_block_types = [
				'core/paragraph',
				'core/image',
				'core/heading',
				'core/gallery',
				'core/list',
				'core/quote',
				'core/audio',
				'core/button',
				'core/buttons',
				'core/calendar',
				'core/code',
				'core/columns',
				'core/column',
				'core/cover',
				'core/embed',
				'core/file',
				'core/group',
				'core/freeform',
				'core/html',
				'core/media-text',
				'core/more',
				'core/nextpage',
				'core/preformatted',
				'core/pullquote',
				'core/rss',
				'core/search',
				'core/separator',
				'core/block',
				'core/social-links',
				'core/social-link',
				'core/spacer',
				'core/table',
				'core/text-columns',
				'core/verse',
				'core/video',
				'core/site-logo',
				'core/site-tagline',
				'core/site-title',
				'core/post-title',
				'core/post-content',
				'core/post-date',
				'core/post-excerpt',
				'core/post-featured-image',
				'core/post-terms',
				'jetpack/business-hours',
				'jetpack/button',
				'jetpack/field-text',
				'jetpack/field-name',
				'jetpack/field-email',
				'jetpack/field-url',
				'jetpack/field-date',
				'jetpack/field-telephone',
				'jetpack/field-textarea',
				'jetpack/field-checkbox',
				'jetpack/field-consent',
				'jetpack/field-checkbox-multiple',
				'jetpack/field-radio',
				'jetpack/field-select',
				'jetpack/contact-info',
				'jetpack/address',
				'jetpack/email',
				'jetpack/phone',
				'jetpack/gif',
				'jetpack/image-compare',
				'jetpack/instagram-gallery',
				'jetpack/map',
				'jetpack/markdown',
				'jetpack/opentable',
				'jetpack/pinterest',
				'jetpack/podcast-player',
				'jetpack/rating-star',
				'jetpack/repeat-visitor',
				'jetpack/send-a-message',
				'jetpack/whatsapp-button',
				'jetpack/simple-payments',
				'jetpack/slideshow',
				'jetpack/story',
				'jetpack/tiled-gallery',
				'newspack-listings/event-dates',
				'newspack-listings/price',
			];
		}

		return $allowed_block_types;
	}

	/**
	 * For listing customers, hide all admin dashboard links. Capabilities are handled by allow_customers_to_edit_own_posts,
	 * but we also don't want these users to see any dashboard links they can't access while in the post editor.
	 */
	public function hide_admin_menu_for_customers() {
		global $menu;
		$is_listing_customer = self::is_listing_customer();

		if ( $is_listing_customer && is_array( $menu ) ) {
			$allowed_items = [ 'index.php' ];
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && ! in_array( $item[2], $allowed_items ) ) {
					remove_menu_page( $item[2] );
				}
			}
		}
	}

	/**
	 * Modifies the admin bar in dashboard to hide most menu items for customers.
	 * This affects the admin bar shown at the top of the editor if not in "full-screen" mode.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar WP Admin Bar object.
	 */
	public function hide_admin_bar_for_customers( $wp_admin_bar ) {
		$is_listing_customer = self::is_listing_customer();

		if ( $is_listing_customer ) {
			$nodes = $wp_admin_bar->get_nodes();

			// Allow user-related nodes to get back to "My Account" pages or to log out.
			$allowed_nodes = [
				'wp-logo',
				'site-name',
				'edit-profile',
				'logout',
				'my-account',
				'top-secondary',
				'user-actions',
				'user-info',
			];

			// Remove all the other nodes.
			foreach ( $nodes as $id => $node ) {
				if ( ! in_array( $id, $allowed_nodes ) ) {
					$wp_admin_bar->remove_node( $id );
				}
			}
		}

		return $wp_admin_bar;
	}

	/**
	 * Redirect customers to main admin screen if trying to access restricted admin pages.
	 */
	public function redirect_to_dashboard() {
		if ( self::is_listing_customer() ) {
			\wp_safe_redirect( \get_admin_url() );
			exit;
		}
	}
}
