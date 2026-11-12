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
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_run' ] );
		add_action(
			'wp_loaded',
			function () {
				if ( wp_doing_cron() ) {
					self::maybe_run();
				}
			}
		);
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
			false
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
		$state = self::get_status();
		if ( in_array( $state['status'], [ 'done', 'skipped' ], true ) ) {
			return;
		}
		if (
			in_array( $state['status'], [ 'failed', 'not_installable' ], true )
			&& $state['time'] + self::retry_after( $state['attempts'] ) > time()
		) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'newspack-blocks/newspack-blocks.php' ) ) {
			self::record( 'skipped' );
			return;
		}
		if ( is_plugin_active( self::SLUG . '/' . self::SLUG . '.php' ) ) {
			self::record( 'done' );
			return;
		}

		$installed = isset( Plugin_Manager::get_installed_plugins()[ self::SLUG ] );
		if ( ! $installed && ! Plugin_Manager::can_install_plugins() ) {
			self::record( 'not_installable', (int) $state['attempts'] );
			return;
		}

		/**
		 * Filters the activation result before it is attempted.
		 *
		 * @param WP_Error|null $result Null to proceed; a WP_Error to use as the result.
		 */
		$result = apply_filters( 'newspack_content_blocks_activation_pre_result', null );
		if ( null === $result ) {
			$result = Plugin_Manager::activate( self::SLUG );
		}

		if ( is_wp_error( $result ) ) {
			$attempts = (int) $state['attempts'] + 1;
			$message  = $result->get_error_message();
			self::record( 'failed', $attempts, $message );
			Logger::newspack_log( 'newspack_content_blocks_activation_failed', $message, [ 'attempts' => $attempts ], 'error' );
			return;
		}

		self::record( 'done' );
	}
}
Content_Blocks_Activation::init();
