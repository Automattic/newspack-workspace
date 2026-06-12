<?php
/**
 * Redirection plugin integration.
 *
 * Forces the third-party Redirection plugin's redirect/404 logging off
 * (with a code-level escape hatch) and optionally suppresses its hit counter.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Redirection {
	/**
	 * Initialize. Registration is deferred to plugins_loaded so Redirection's
	 * classes are loaded before we guard on them.
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'register' ], 11 );
	}

	/**
	 * Register hooks, but only when the Redirection plugin is present.
	 *
	 * Guards on function_exists( 'red_get_options' ) — a stable symbol in the
	 * released Redirection (5.5.2). Load-context-independent, unlike
	 * is_plugin_active() (undefined on front-end requests). NOT class_exists(
	 * 'Red_Options' ): that class exists only on Redirection's trunk, so it would
	 * make this a silent no-op on every site running a released version.
	 */
	public static function register() {
		if ( ! function_exists( 'red_get_options' ) ) {
			return;
		}

		if ( ! self::is_logging_enabled() ) {
			// Layer 1: hard-stop both log writes at the source (independent of stored options).
			add_filter( 'redirection_log_data', '__return_false' );
			add_filter( 'redirection_404_data', '__return_false' );
			add_filter( 'redirection_log_404', '__return_false' );

			// Layer 2: read-only neutralize + honest UI (see force_logging_off_in_options()).
			add_filter( 'option_redirection_options', [ __CLASS__, 'force_logging_off_in_options' ] );

			// Layer 3: hide the log-retention controls on Redirection's options screen.
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

			// Durability: structural-drift notice if Redirection's log classes are gone.
			add_action( 'admin_notices', [ __CLASS__, 'maybe_render_drift_notice' ] );
		}

		if ( ! self::is_hit_tracking_enabled() ) {
			add_filter( 'redirection_redirect_counter', '__return_false' );
		}
	}

	/**
	 * Whether Redirection's redirect/404 logging is enabled.
	 *
	 * Default: false (Newspack forces logging off). Precedence:
	 * constant NEWSPACK_REDIRECTION_LOGGING_ENABLED wins, then the
	 * `newspack_redirection_logging_enabled` filter, then the default.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		if ( defined( 'NEWSPACK_REDIRECTION_LOGGING_ENABLED' ) ) {
			return (bool) NEWSPACK_REDIRECTION_LOGGING_ENABLED;
		}
		/**
		 * Filters whether the Redirection plugin's redirect/404 logging is enabled.
		 *
		 * @param bool $enabled Default false (Newspack forces logging off).
		 */
		return (bool) apply_filters( 'newspack_redirection_logging_enabled', false );
	}

	/**
	 * Whether Redirection's per-redirect hit counter is enabled.
	 *
	 * Default: true (left unchanged). Precedence:
	 * constant NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED wins, then the
	 * `newspack_redirection_hit_tracking_enabled` filter, then the default.
	 *
	 * @return bool
	 */
	public static function is_hit_tracking_enabled() {
		if ( defined( 'NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED' ) ) {
			return (bool) NEWSPACK_REDIRECTION_HIT_TRACKING_ENABLED;
		}
		/**
		 * Filters whether the Redirection plugin's per-redirect hit counter is enabled.
		 *
		 * @param bool $enabled Default true (left unchanged).
		 */
		return (bool) apply_filters( 'newspack_redirection_hit_tracking_enabled', true );
	}

	/**
	 * Layer 2: force the stored log-retention values to "off" on read.
	 *
	 * Read-only (we never write the row), non-destructive, and fully reversible —
	 * deactivating Newspack reverts the site to its prior settings. Makes the
	 * Redirection options screen show logging disabled and prevents re-enabling
	 * from the UI (the value reverts on next read).
	 *
	 * Must pass a non-array value through untouched: get_option() returns false
	 * on a fresh site with no saved row.
	 *
	 * @param mixed $value Stored redirection_options value.
	 * @return mixed
	 */
	public static function force_logging_off_in_options( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value['expire_redirect'] = -1;
		$value['expire_404']      = -1;
		return $value;
	}

	/**
	 * Layer 3: enqueue the admin script that hides the log-retention controls,
	 * only on Redirection's options screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Redirection's menu lives under Tools → Redirection (slug `redirection.php`).
		if ( 'tools_page_redirection' !== $hook_suffix ) {
			return;
		}

		$path = NEWSPACK_ABSPATH . 'dist/other-scripts/redirection-admin.js';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$asset  = include NEWSPACK_ABSPATH . 'dist/other-scripts/redirection-admin.asset.php';
		$handle = 'newspack-redirection-admin';
		wp_enqueue_script(
			$handle,
			plugins_url( 'dist/other-scripts/redirection-admin.js', NEWSPACK_PLUGIN_FILE ),
			$asset['dependencies'] ?? [],
			$asset['version'] ?? false,
			true
		);

		// Translated note text, ready for the disable+note fallback.
		wp_localize_script(
			$handle,
			'newspackRedirection',
			[
				'noteText' => __( 'Logging is disabled for performance reasons on Newspack.', 'newspack' ),
			]
		);
	}

	/**
	 * Durability notice — implemented in a later task.
	 */
	public static function maybe_render_drift_notice() {}
}
Redirection::init();
