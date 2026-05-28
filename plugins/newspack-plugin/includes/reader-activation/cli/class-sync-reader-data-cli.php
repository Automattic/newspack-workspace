<?php // phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users, WordPress.DB.DirectDatabaseQuery
/**
 * Reader Activation Data CLI sync script.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Memberships;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Reader Data CLI Class.
 */
final class Sync_Reader_Data_CLI {
	/**
	 * Initialized this class and adds hooks to register CLI commands
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_commands' ] );
	}

	/**
	 * Register CLI command.
	 */
	public static function register_commands() {
		if ( ! defined( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'newspack reader-data sync-memberships', [ __CLASS__, 'fix_reader_data_and_membership_discrepancy' ] );
	}

	/**
	 * Fix discrepancies between stored reader data and memberships.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Live mode, performing the fix.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function fix_reader_data_and_membership_discrepancy( $args, $assoc_args ) {
		\WP_CLI::line( '' );
		$live = isset( $assoc_args['live'] ) ? true : false;

		if ( $live ) {
			\WP_CLI::line( 'Live mode - data will be changed.' );
		} else {
			\WP_CLI::line( 'Dry run. Use --live flag to run in live mode.' );
		}
		\WP_CLI::line( '' );

		global $wpdb;
		// This will return all members who have discrepancies in the active memberships
		// and the reader data stored. The SQL will return a lot of false-positives, because
		// The membership plan IDs in the reader data are not sorted.
		$sql = "
            SELECT u.ID AS user_id,
                GROUP_CONCAT(DISTINCT p.post_parent ORDER BY p.post_parent) AS actual_membership_plan_ids,
                -- Clean the stored memberships by removing '[]\"' characters
                REPLACE(REPLACE(REPLACE(um.meta_value, '[', ''), ']', ''), '\"', '') AS stored_membership_plan_ids
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u
                ON p.post_author = u.ID
            LEFT JOIN {$wpdb->prefix}usermeta um
                ON u.ID = um.user_id
                AND um.meta_key = %s
            WHERE p.post_type = 'wc_user_membership'
            AND p.post_status = 'wcm-active'
            GROUP BY u.ID
            HAVING actual_membership_plan_ids != stored_membership_plan_ids
            OR stored_membership_plan_ids IS NULL
        ";

		$reader_data_user_meta_key = Reader_Data::get_meta_key_name( 'active_memberships' );
		$potentially_misaliged_members = $wpdb->get_results( $wpdb->prepare( $sql, $reader_data_user_meta_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $potentially_misaliged_members as $reader_data ) {
			// Rule out false-positives.
			$actual_membership_plan_ids = explode( ',', $reader_data->actual_membership_plan_ids ?? '' );
			$stored_membership_plan_ids = explode( ',', $reader_data->stored_membership_plan_ids ?? '' );
			sort( $actual_membership_plan_ids );
			sort( $stored_membership_plan_ids );

			if ( $actual_membership_plan_ids === $stored_membership_plan_ids ) {
				// False positive.
				continue;
			}

			if ( $live ) {
				$update_result = update_user_meta( $reader_data->user_id, $reader_data_user_meta_key, implode( ',', $actual_membership_plan_ids ) );
				if ( $update_result !== false ) {
					\WP_CLI::success( sprintf( 'Updated user #%d reader data.', $reader_data->user_id ) );
				}
			} else {
				\WP_CLI::log( sprintf( 'Would update user #%d reader data.', $reader_data->user_id ) );
			}
		}

		\WP_CLI::line( '' );
	}
}
Sync_Reader_Data_CLI::init();
