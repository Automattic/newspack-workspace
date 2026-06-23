<?php
/**
 * Newspack Newsletters Wizard Bridge.
 *
 * Enqueues the bridge JS bundle on the bundled-mode Newsletters
 * Settings wizard so its `<SubscriptionLists>` card can mount this
 * plugin's local-list modals via document events.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters;

use Newspack\Newsletters\Admin\Asset_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Wizard Bridge.
 */
class Wizard_Bridge {

	const SCRIPT_HANDLE    = 'newspack-newsletters-wizard-bridge';
	const WIZARD_PAGE_SLUG = 'newspack-newsletters';

	/**
	 * Hook registration entry point.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
	}

	/**
	 * Decide whether to enqueue on the current admin screen.
	 *
	 * @return bool
	 */
	public static function should_enqueue() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( ! class_exists( '\Newspack\Newspack' ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return self::WIZARD_PAGE_SLUG === $page;
	}

	/**
	 * Enqueue the bridge bundle when applicable.
	 */
	public static function maybe_enqueue() {
		if ( ! self::should_enqueue() ) {
			return;
		}

		$asset = Asset_Loader::enqueue_bundle(
			self::SCRIPT_HANDLE,
			'wizard-bridge',
			NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist',
			plugins_url( '../dist', __FILE__ )
		);
		if ( ! $asset ) {
			return;
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'newspack_newsletters_wizard_bridge',
			[
				'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			]
		);
	}
}
