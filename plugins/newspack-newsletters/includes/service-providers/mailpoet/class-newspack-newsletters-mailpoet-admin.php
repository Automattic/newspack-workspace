<?php
/**
 * Service Provider: MailPoet admin routing.
 *
 * Supports an authoring mode in which newsletters are composed in MailPoet's own
 * UI rather than ours. Our menu stays registered — it still owns Newsletter Ads
 * and Settings — but the entries that list or create newsletters point at
 * MailPoet, and the matching screens redirect there for anyone arriving by
 * bookmark or direct URL.
 *
 * Exploratory. See LEO-65.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes newsletter listing and creation to MailPoet.
 */
class Newspack_Newsletters_Mailpoet_Admin {

	/**
	 * Option choosing where newsletters are authored.
	 *
	 * `newspack` keeps our editor and syncs to MailPoet. `mailpoet` hands
	 * authoring over to MailPoet's UI.
	 */
	const AUTHORING_OPTION = 'newspack_newsletters_mailpoet_authoring';

	/**
	 * Query arg that reaches our newsletter list without being redirected.
	 */
	const BYPASS_ARG = 'newspack_past_newsletters';

	/**
	 * MailPoet's newsletter list page.
	 */
	const MAILPOET_LIST_PAGE = 'admin.php?page=mailpoet-newsletters';

	/**
	 * MailPoet's "new newsletter" route. A hash route on the list page.
	 */
	const MAILPOET_NEW_ROUTE = 'admin.php?page=mailpoet-newsletters#/new';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'rewrite_menu' ], 999 );
		add_action( 'current_screen', [ __CLASS__, 'maybe_redirect' ] );
	}

	/**
	 * Whether MailPoet owns authoring.
	 *
	 * Requires MailPoet to be the active provider, so the mode is inert on a site
	 * using any other ESP.
	 *
	 * @return boolean
	 */
	public static function is_mailpoet_authoring() {
		if ( ! class_exists( 'Newspack_Newsletters' ) || 'mailpoet' !== Newspack_Newsletters::service_provider() ) {
			return false;
		}
		$mode = get_option( self::AUTHORING_OPTION, 'newspack' );

		/**
		 * Filters where newsletters are authored when MailPoet is the provider.
		 *
		 * @param string $mode Either 'newspack' or 'mailpoet'.
		 */
		$mode = apply_filters( 'newspack_newsletters_mailpoet_authoring', $mode );

		return 'mailpoet' === $mode;
	}

	/**
	 * Our newsletter list, exempt from the redirect.
	 *
	 * Admin-relative, so it works as a submenu slug and WordPress can mark the
	 * entry current when the screen is open.
	 *
	 * @return string
	 */
	public static function get_past_newsletters_slug() {
		return sprintf(
			'edit.php?post_type=%s&%s=1',
			Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			self::BYPASS_ARG
		);
	}

	/**
	 * The absolute URL of our newsletter list, exempt from the redirect.
	 *
	 * @return string
	 */
	public static function get_past_newsletters_url() {
		return admin_url( self::get_past_newsletters_slug() );
	}

	/**
	 * Point the listing and creation entries at MailPoet, and add a way back to
	 * newsletters authored before the handover.
	 *
	 * The parent menu slug is left alone so Newsletter Ads and Settings stay
	 * parented to it. WordPress links a CPT's top-level menu to its first
	 * submenu, so repointing that entry moves the menu itself without detaching
	 * anything beneath it.
	 *
	 * @return void
	 */
	public static function rewrite_menu() {
		if ( ! self::is_mailpoet_authoring() ) {
			return;
		}

		global $submenu;
		$parent = 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		$list_slug = $parent;
		$new_slug  = 'post-new.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$has_past  = false;

		foreach ( $submenu[ $parent ] as $index => $item ) {
			if ( $list_slug === $item[2] ) {
				$submenu[ $parent ][ $index ][2] = self::MAILPOET_LIST_PAGE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			if ( $new_slug === $item[2] ) {
				$submenu[ $parent ][ $index ][2] = self::MAILPOET_NEW_ROUTE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			if ( false !== strpos( (string) $item[2], self::BYPASS_ARG ) ) {
				$has_past = true;
			}
		}

		// TODO(LEO-65): show this only when there are newsletters to see, and
		// settle on a better label than "Past newsletters".
		if ( ! $has_past ) {
			add_submenu_page(
				$parent,
				__( 'Past newsletters', 'newspack-newsletters' ),
				__( 'Past newsletters', 'newspack-newsletters' ),
				'edit_posts',
				self::get_past_newsletters_slug()
			);
		}
	}

	/**
	 * Send listing and creation screens to MailPoet.
	 *
	 * Covers bookmarks, direct URLs and "Add New" links elsewhere in wp-admin,
	 * which the menu rewrite alone would miss. Mirrors the guards in
	 * Admin_Shell_Legacy_Redirect: GET requests only, and never when `action`
	 * carries a real value, so classic list-table flows keep working.
	 *
	 * @param \WP_Screen $screen Current screen.
	 *
	 * @return void
	 */
	public static function maybe_redirect( $screen ) {
		if ( ! is_admin() || ! $screen instanceof \WP_Screen ) {
			return;
		}
		if ( ! self::is_mailpoet_authoring() ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $screen->post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation check.
		if ( isset( $_GET[ self::BYPASS_ARG ] ) ) {
			return;
		}
		if ( self::has_real_get_action() ) {
			return;
		}

		if ( 'edit' === $screen->base ) {
			wp_safe_redirect( admin_url( self::MAILPOET_LIST_PAGE ) );
			exit;
		}
		if ( 'post' === $screen->base && 'add' === $screen->action ) {
			wp_safe_redirect( admin_url( self::MAILPOET_NEW_ROUTE ) );
			exit;
		}
	}

	/**
	 * Whether `action` / `action2` carry a real value, rather than WP's `-1`
	 * "no action selected" sentinel.
	 *
	 * @return boolean
	 */
	private static function has_real_get_action() {
		foreach ( [ 'action', 'action2' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation check.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			if ( '' !== $value && '-1' !== $value ) {
				return true;
			}
		}
		return false;
	}
}

// Only registers hooks; every callback checks the active provider and mode
// first, so this is inert unless MailPoet owns authoring.
Newspack_Newsletters_Mailpoet_Admin::init();
