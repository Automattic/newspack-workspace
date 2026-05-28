<?php
/**
 * Reader Activation Data Syncing.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Reader_Activation;
use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Class.
 */
class Sync {

	/**
	 * Log a message to the Newspack Logger.
	 *
	 * @param string $message The message to log.
	 * @param array  $data    Optional. Additional data to log.
	 */
	protected static function log( $message, $data = [] ) {
		Logger::log( $message, 'NEWSPACK-SYNC' );
		if ( ! empty( $data ) ) {
			Logger::newspack_log(
				'newspack_sync',
				$message,
				$data,
				'debug'
			);
		}
	}

	/**
	 * Whether reader data can be synced.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|WP_Error True if reader data can be synced, false otherwise. WP_Error if return_errors is true.
	 */
	public static function can_sync( $return_errors = false ) {
		$errors = new \WP_Error();

		if ( ! Reader_Activation::is_enabled() ) {
			$errors->add(
				'ras_not_enabled',
				__( 'Audience Management is not enabled.', 'newspack-plugin' )
			);
		}

		if ( class_exists( 'WCS_Staging' ) && \WCS_Staging::is_duplicate_site() ) {
			$errors->add(
				'wcs_duplicate_site',
				__( 'Audience Management contact data syncing is disabled for cloned sites.', 'newspack-plugin' )
			);
		}

		$site_url = strtolower( \untrailingslashit( \get_site_url() ) );
		/**
		 * Enables reader data syncing to ESP on staging or non-production sites.
		 * By default, syncing is disabled on staging sites to prevent test data
		 * from being sent to production ESP lists.
		 *
		 * @constant NEWSPACK_ALLOW_READER_SYNC
		 * @type     bool
		 * @default  Sync disabled on staging/non-production sites
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ALLOW_READER_SYNC', true );
		 */
		if (
			(
				false !== stripos( $site_url, '.newspackstaging.com' ) ||
				! method_exists( 'Newspack_Manager', 'is_connected_to_production_manager' ) ||
				! \Newspack_Manager::is_connected_to_production_manager()
			) &&
			( ! defined( 'NEWSPACK_ALLOW_READER_SYNC' ) || ! NEWSPACK_ALLOW_READER_SYNC )
		) {
			$errors->add(
				'esp_sync_not_allowed',
				__( 'Contact data syncing is disabled for staging sites. To bypass this check, set the NEWSPACK_ALLOW_READER_SYNC constant in your wp-config.php.', 'newspack-plugin' )
			);
		}

		if ( $return_errors ) {
			return $errors;
		}

		if ( $errors->has_errors() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether at least one integration is enabled and can sync.
	 *
	 * @param bool $return_errors Optional. Whether to return a WP_Error object. Default false.
	 *
	 * @return bool|WP_Error True if at least one integration can sync, false otherwise. WP_Error if return_errors is true.
	 */
	public static function has_one_syncable_integration( $return_errors = false ) {

		// Check if integrations have been registered.
		if ( ! Integrations::are_integrations_registered() ) {
			$message = __( 'This method was called before integrations were registered. Integrations are registered on the "init" hook with priority 5. Make sure to call this method after that hook has fired.', 'newspack-plugin' );

			_doing_it_wrong(
				__METHOD__,
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong expects translated string.
				$message,
				'6.29.3'
			);

			if ( $return_errors ) {
				return new \WP_Error(
					'integrations_not_registered',
					$message
				);
			}

			return false;
		}

		$can_sync = static::can_sync( $return_errors );

		if ( $return_errors && is_wp_error( $can_sync ) && $can_sync->has_errors() ) {
			return $can_sync;
		}

		if ( ! $return_errors && false === $can_sync ) {
			return false;
		}

		$integrations = Integrations::get_active_integrations();

		// If there are no active integrations, return false or an error.
		if ( empty( $integrations ) ) {
			if ( $return_errors ) {
				return new \WP_Error( 'no_active_integrations', __( 'No active integrations found.', 'newspack-plugin' ) );
			}
			return false;
		}

		$result = new \WP_Error();

		foreach ( $integrations as $integration ) {
			$can_sync_integration = $integration->can_sync( true );

			// If any integration can sync, return true.
			if ( ! $can_sync_integration->has_errors() ) {
				if ( $return_errors ) {
					return $result;
				} else {
					return true;
				}
			}

			$result->merge_from( $can_sync_integration );
		}

		if ( $return_errors ) {
			return $result;
		}

		// If we've checked all integrations and none can sync, return false.
		return false;
	}
}
