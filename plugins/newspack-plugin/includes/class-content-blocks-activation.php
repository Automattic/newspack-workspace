<?php
/**
 * One-time, retrying activation of the Newspack Content Blocks plugin.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Activates newspack-content-blocks on sites where newspack-blocks is active.
 *
 * On Newspack's platform the plugin is usually already present (delivered as a
 * WP Cloud external) and only needs activating. On self-hosted sites it is
 * installed from the download URL when the host allows installs.
 */
class Content_Blocks_Activation {

	/**
	 * Option holding the step's state: [ status, time, attempts, error ].
	 */
	const OPTION = 'newspack_content_blocks_activation';

	/**
	 * Slug of the plugin to activate.
	 */
	const SLUG = 'newspack-content-blocks';

	/**
	 * Option used as a lock around an attempt. Not strictly exclusive under a persistent
	 * object cache, which is acceptable: running twice is recoverable. A second activation
	 * is a no-op, and a second install fails on the existing directory, records `failed`,
	 * and the next retry finds the plugin and records `done`.
	 */
	const LOCK_OPTION = 'newspack_content_blocks_activation_lock';

	/**
	 * Seconds after which a lock is considered stale and taken over.
	 */
	const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_run_admin' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_run_cron' ] );
	}

	/**
	 * Run from cron requests.
	 */
	public static function maybe_run_cron() {
		if ( wp_doing_cron() ) {
			self::maybe_run();
		}
	}

	/**
	 * Run from admin_init, for users allowed to activate (and install) plugins only.
	 *
	 * Logged-out requests reach admin_init through admin-ajax.php and admin-post.php.
	 */
	public static function maybe_run_admin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// Cheap check first: this runs on every admin page load.
		if ( ! self::should_run( self::get_status() ) ) {
			return;
		}
		if ( ! self::is_installed() && ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		self::maybe_run();
	}

	/**
	 * Whether the step still has work to do for a given state.
	 *
	 * @param array $state State from get_status().
	 * @return bool
	 */
	private static function should_run( $state ) {
		if ( 'done' === $state['status'] ) {
			return false;
		}
		// Skipped because newspack-blocks was inactive; it may have been activated since,
		// e.g. by the setup wizard after this step first ran on a fresh install.
		if ( 'skipped' === $state['status'] ) {
			return self::is_newspack_blocks_active();
		}
		return ! (
			in_array( $state['status'], [ 'failed', 'not_installable' ], true )
			&& $state['time'] + self::retry_after( $state['attempts'] ) > time()
		);
	}

	/**
	 * Take the lock. A lock older than the TTL is stale and is taken over.
	 *
	 * @return bool True if acquired.
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		if ( (int) get_option( self::LOCK_OPTION ) + self::LOCK_TTL > time() ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return (bool) add_option( self::LOCK_OPTION, time(), '', 'no' );
	}

	/**
	 * Whether newspack-blocks is active, in whatever directory it was installed
	 * (a GitHub zip unpacks to a versioned one).
	 *
	 * @return bool
	 */
	private static function is_newspack_blocks_active() {
		$plugins = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		foreach ( $plugins as $plugin ) {
			if ( 'newspack-blocks.php' === basename( $plugin ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether files can be written without credentials. Installing from a background
	 * request can't prompt for FTP or SSH details, and the upgrader fails hard without them.
	 *
	 * @return bool
	 */
	private static function can_write_files() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return 'direct' === get_filesystem_method();
	}

	/**
	 * Whether the plugin files are present.
	 *
	 * @return bool
	 */
	private static function is_installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return isset( Plugin_Manager::get_installed_plugins()[ self::SLUG ] );
	}

	/**
	 * Get the recorded state.
	 *
	 * @return array{status: string, time: int, attempts: int, error: string}
	 */
	public static function get_status() {
		$value = get_option( self::OPTION, [] );
		return wp_parse_args(
			is_array( $value ) ? $value : [],
			[
				'status'   => '',
				'time'     => 0,
				'attempts' => 0,
				'error'    => '',
			]
		);
	}

	/**
	 * Seconds to wait before retrying after a given number of failed attempts.
	 *
	 * @param int $attempts Failed attempts so far.
	 * @return int
	 */
	public static function retry_after( $attempts ) {
		return (int) min( DAY_IN_SECONDS, HOUR_IN_SECONDS * 2 ** ( max( 1, (int) $attempts ) - 1 ) );
	}

	/**
	 * Record the state.
	 *
	 * @param string $status   One of done, skipped, not_installable, failed.
	 * @param int    $attempts Failed attempts so far.
	 * @param string $error    Error message, if any.
	 */
	private static function record( $status, $attempts = 0, $error = '' ) {
		update_option(
			self::OPTION,
			[
				'status'   => $status,
				'time'     => time(),
				'attempts' => $attempts,
				'error'    => $error,
			],
			true // Read on every admin and cron request.
		);
	}

	/**
	 * Run the step unless it is finished or waiting out a retry delay.
	 *
	 * Test seam: the `newspack_content_blocks_activation_pre_result` filter runs
	 * right before activation. Return null to proceed, or a WP_Error to treat as
	 * the activation result without touching the plugin or the network.
	 */
	public static function maybe_run() {
		if ( ! self::should_run( self::get_status() ) ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			// Re-read under the lock: another request may have finished meanwhile.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$state = self::get_status();
			if ( self::should_run( $state ) ) {
				self::run( $state );
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * The attempt itself, called with the lock held.
	 *
	 * @param array $state State from get_status().
	 */
	private static function run( $state ) {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! self::is_newspack_blocks_active() ) {
			self::record( 'skipped' );
			return;
		}
		if ( is_plugin_active( self::SLUG . '/' . self::SLUG . '.php' ) ) {
			self::record( 'done' );
			return;
		}

		if ( ! self::is_installed() && ( ! Plugin_Manager::can_install_plugins() || ! self::can_write_files() ) ) {
			self::record( 'not_installable', (int) $state['attempts'] );
			return;
		}

		// Record the attempt first: activation includes the plugin file, and a fatal
		// there would otherwise leave no trace and be retried on every request. This
		// also keeps a concurrent request out for the retry window.
		$attempts = (int) $state['attempts'] + 1;
		self::record( 'failed', $attempts, 'Activation did not complete.' );

		/**
		 * Filters the activation result before it is attempted.
		 *
		 * @param WP_Error|null $result Null to proceed; a WP_Error to use as the result.
		 */
		$result = apply_filters( 'newspack_content_blocks_activation_pre_result', null );
		if ( null === $result ) {
			$result = Plugin_Manager::activate( self::SLUG );
		}

		// A concurrent request may have activated it meanwhile.
		if ( is_wp_error( $result ) && ! is_plugin_active( self::SLUG . '/' . self::SLUG . '.php' ) ) {
			$message = $result->get_error_message();
			self::record( 'failed', $attempts, $message );
			Logger::newspack_log( 'newspack_content_blocks_activation_failed', $message, [ 'attempts' => $attempts ], 'error' );
			return;
		}

		self::record( 'done' );
	}
}
Content_Blocks_Activation::init();
