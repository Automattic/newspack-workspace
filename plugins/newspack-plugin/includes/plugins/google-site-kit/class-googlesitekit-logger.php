<?php
/**
 * Google Site Kit Logger class.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Core\Authentication\Has_Connected_Admins;
use Google\Site_Kit\Core\Authentication\Disconnected_Reason;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class GoogleSiteKit_Logger {
	/**
	 * The hook name for site kit disconnection logger cron job.
	 */
	const CRON_HOOK = 'newspack_googlesitekit_disconnection_logger';

	/**
	 * The log code for disconnections.
	 */
	const LOG_CODE_DISCONNECTED = 'newspack_googlesitekit_disconnected';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'init_hooks' ] );
	}

	/**
	 * Initialize hooks and filters.
	 */
	public static function init_hooks() {
		if (
			! method_exists( 'Newspack_Manager', 'is_connected_to_production_manager' ) || (
				method_exists( 'Newspack_Manager', 'is_connected_to_production_manager' )
				&& ! \Newspack_Manager::is_connected_to_production_manager()
			)
		) {
			return false;
		}

		/**
		 * Disables Google Site Kit connection checks and logging.
		 * Use for sites that don't use Site Kit.
		 *
		 * @constant NEWSPACK_DISABLE_SITEKIT_CHECK
		 * @type     bool
		 * @default  Site Kit checks enabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_DISABLE_SITEKIT_CHECK', true );
		 */
		if ( defined( 'NEWSPACK_DISABLE_SITEKIT_CHECK' ) && NEWSPACK_DISABLE_SITEKIT_CHECK ) {
			return false;
		}

		if ( GoogleSiteKit::is_active() ) {
			add_action( 'admin_init', [ __CLASS__, 'cron_init' ] );
			add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron_event' ] );
		}
	}

	/**
	 * Schedule cron job to check for site kit connection. If the connection is lost we log it.
	 */
	public static function cron_init() {
		register_deactivation_hook( NEWSPACK_PLUGIN_FILE, [ __CLASS__, 'cron_deactivate' ] );

		// Switch the cadence of the cron job from hourly to daily so we need to unschedule any existing hourly jobs.
		$cadence_updated_option = 'newspack_googlesitekit_cron_cadence_updated';
		if ( ! get_option( $cadence_updated_option ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			update_option( $cadence_updated_option, true );
		}

		if ( defined( 'NEWSPACK_CRON_DISABLE' ) && is_array( NEWSPACK_CRON_DISABLE ) && in_array( self::CRON_HOOK, NEWSPACK_CRON_DISABLE, true ) ) {
			self::cron_deactivate();
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivate the cron job.
	 */
	public static function cron_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Logs when cron event runs and all admins are disconnected.
	 */
	public static function handle_cron_event() {
		$connection_info = self::get_connection_status();

		if ( 'connected' !== $connection_info['status'] ) {
			$message = 'Google Site Kit disconnection detected: ' . $connection_info['reason'];
			if ( isset( $connection_info['details'] ) ) {
				$message .= ' (' . $connection_info['details'] . ')';
			}
			self::log( self::LOG_CODE_DISCONNECTED, $message, false, 4 );
		}
	}

	/**
	 * Get comprehensive connection status information.
	 *
	 * @return array Connection status details with 'status', 'reason', and optional 'details'.
	 */
	public static function get_connection_status() {
		// Check if Site Kit is active.
		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			return [
				'status'  => 'disconnected',
				'reason'  => 'plugin_inactive',
				'details' => 'GOOGLESITEKIT_PLUGIN_MAIN_FILE not defined',
			];
		}

		try {
			// Check Analytics 4 settings.
			$analytics_settings      = get_option( 'googlesitekit_analytics-4_settings', [] );
			$analytics_will_output   = ! empty( $analytics_settings['useSnippet'] ) && ! empty( $analytics_settings['measurementID'] );

			// Check Tag Manager settings (which can also output Google Analytics).
			$tagmanager_settings     = get_option( 'googlesitekit_tagmanager_settings', [] );
			$tagmanager_will_output  = ! empty( $tagmanager_settings['useSnippet'] ) && ! empty( $tagmanager_settings['containerID'] );

			// If neither Analytics 4 nor Tag Manager will output snippets, consider it disconnected.
			if ( ! $analytics_will_output && ! $tagmanager_will_output ) {
				return [
					'status' => 'disconnected',
					'reason' => 'will_not_output_snippet',
				];
			}

			return [
				'status' => 'connected',
				'reason' => 'fully_connected',
			];

		} catch ( \Exception $e ) {
			return [
				'status'  => 'disconnected',
				'reason'  => 'exception',
				'details' => $e->getMessage(),
			];
		}
	}

	/**
	 * Main site kit logger.
	 *
	 * @param string $code      The code for the log.
	 * @param string $message   The message to log. Optional.
	 * @param bool   $backtrace Whether to include a backtrace.
	 * @param int    $log_level The log level.
	 */
	private static function log( $code, $message, $backtrace = true, $log_level = 2 ) {
		$data = [
			'file'       => $code,
			'user_email' => wp_get_current_user()->user_email,
		];
		if ( $backtrace ) {
			$e                 = new \Exception();
			$data['backtrace'] = $e->getTraceAsString();
		}
		Logger::newspack_log( $code, $message, $data, 'error', $log_level );
	}
}
GoogleSiteKit_Logger::init();
