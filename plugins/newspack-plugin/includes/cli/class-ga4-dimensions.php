<?php
/**
 * WP-CLI commands for GA4 custom dimension provisioning.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\GA4_Custom_Dimensions;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Provisions Newspack's standard GA4 custom dimensions.
 */
class GA4_Dimensions {

	/**
	 * Provision Newspack's GA4 custom dimensions on the connected property.
	 *
	 * Authenticates via Newspack's Google OAuth (preferred – its tokens carry
	 * the `analytics.edit` scope) and falls back to Google Site Kit. Without an
	 * explicit `--user`, it authenticates as the Site Kit module owner, so the
	 * command works unattended from cron; pass `--user=<admin>` to run as a
	 * specific administrator instead. Exits non-zero if any dimension could not
	 * be created.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report connection status and available slots without creating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack ga4-dimensions provision
	 *     wp newspack ga4-dimensions provision --dry-run
	 *     wp --user=admin newspack ga4-dimensions provision
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function provision( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			$status = GA4_Custom_Dimensions::status();
			if ( is_wp_error( $status ) ) {
				WP_CLI::error( $status->get_error_message() );
			}
			WP_CLI::log( 'GA4 dimension provisioning status:' );
			WP_CLI::log( sprintf( '  Property ID:               %s', $status['property_id'] ) );
			WP_CLI::log( sprintf( '  Site Kit connected:        %s', $status['site_kit_connected'] ? 'yes' : 'no' ) );
			WP_CLI::log( sprintf( '  Auth source:               %s', $status['auth_source'] ?? 'unknown' ) );
			WP_CLI::log( sprintf( '  Event-scoped existing:     %d', $status['event_scoped_existing'] ) );
			WP_CLI::log( sprintf( '  Newspack dimensions:       %d total, %d present, %d missing', $status['newspack_total'], count( $status['newspack_present'] ), count( $status['newspack_missing'] ) ) );
			if ( $status['newspack_missing'] ) {
				WP_CLI::log( '  Missing:                   ' . implode( ', ', $status['newspack_missing'] ) );
			}
			return;
		}

		$result = GA4_Custom_Dimensions::provision();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		GA4_Custom_Dimensions::maybe_schedule_recheck();
		WP_CLI::log( sprintf( 'Property ID:      %s', $result['property_id'] ) );
		WP_CLI::log( sprintf( 'Auth source:      %s', $result['auth_source'] ?? 'unknown' ) );
		WP_CLI::log( sprintf( 'Created:          %d (%s)', count( $result['created'] ), implode( ', ', $result['created'] ) ) );
		WP_CLI::log( sprintf( 'Already existed:  %d', count( $result['skipped_exists'] ) ) );
		if ( ! empty( $result['errors'] ) ) {
			WP_CLI::warning( 'Errors:' );
			foreach ( $result['errors'] as $name => $message ) {
				WP_CLI::log( "  $name: $message" );
			}
			WP_CLI::error( sprintf( '%d dimension(s) could not be created.', count( $result['errors'] ) ) );
		}
		WP_CLI::success( 'GA4 dimension provisioning complete.' );
	}
}
