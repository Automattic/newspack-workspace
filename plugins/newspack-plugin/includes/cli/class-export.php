<?php
/**
 * CSV export CLI commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\CSV_Exports;

defined( 'ABSPATH' ) || exit;

/**
 * CSV export CLI commands: support-driven equivalents of the admin list
 * export buttons, sharing the same exporters and param translation.
 */
class Export {

	/**
	 * Export WooCommerce subscriptions to a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Subscription status to export (e.g. active, on-hold). Defaults to all statuses.
	 *
	 * [--product=<id>]
	 * : Only subscriptions containing this product ID.
	 *
	 * [--customer=<id>]
	 * : Only subscriptions belonging to this customer (user ID).
	 *
	 * [--payment-method=<method>]
	 * : Payment gateway ID, or "_manual_renewal" for manual-renewal subscriptions.
	 *
	 * [--group=<group>]
	 * : Newspack group filter: "group" or "non-group".
	 *
	 * [--search=<term>]
	 * : Search term (same semantics as the admin list search).
	 *
	 * [--month=<yyyymm>]
	 * : Only subscriptions created in this month, e.g. 202605.
	 *
	 * [--output=<path>]
	 * : Output file path. Defaults to newspack-subscriptions-export-<date>-<random>.csv in the current directory.
	 *
	 * [--per-page=<n>]
	 * : Rows fetched per batch. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack export-subscriptions --status=active --product=123 --output=/tmp/active-print.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function export_subscriptions( array $args, array $assoc_args ): void {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active.' );
		}
		// Map flags to the admin-list param shape so the exporters' query
		// translation is the single code path for both surfaces.
		$flag_map = [
			'status'         => 'post_status',
			'product'        => '_wcs_product',
			'customer'       => '_customer_user',
			'payment-method' => '_payment_method',
			'group'          => '_newspack_group_subscription',
			'search'         => 's',
			'month'          => 'm',
		];
		$params   = [];
		foreach ( $flag_map as $flag => $param ) {
			if ( isset( $assoc_args[ $flag ] ) && '' !== $assoc_args[ $flag ] ) {
				$params[ $param ] = $assoc_args[ $flag ];
			}
		}
		self::run_export( 'subscriptions', $params, $assoc_args );
	}

	/**
	 * Export WP users (with WooCommerce billing/shipping meta) to a CSV file.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Only users with this role.
	 *
	 * [--search=<term>]
	 * : Search term (same semantics as the admin users list search).
	 *
	 * [--output=<path>]
	 * : Output file path. Defaults to newspack-users-export-<date>-<random>.csv in the current directory.
	 *
	 * [--per-page=<n>]
	 * : Rows fetched per batch. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack export-users --role=subscriber --output=/tmp/subscribers.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function export_users( array $args, array $assoc_args ): void {
		$params = [];
		if ( ! empty( $assoc_args['role'] ) ) {
			$params['role'] = $assoc_args['role'];
		}
		if ( ! empty( $assoc_args['search'] ) ) {
			$params['s'] = $assoc_args['search'];
		}
		self::run_export( 'users', $params, $assoc_args );
	}

	/**
	 * Drive an exporter through all pages and save the result.
	 *
	 * @param string $type       Export type: 'subscriptions' or 'users'.
	 * @param array  $params     Admin-list-shaped query params.
	 * @param array  $assoc_args CLI associative args (output, per-page).
	 */
	private static function run_export( string $type, array $params, array $assoc_args ): void {
		$filename = CSV_Exports::generate_export_filename( $type );
		// Arm the stale-file sweep in case this run is killed mid-export.
		CSV_Exports::schedule_cleanup();
		$output = ! empty( $assoc_args['output'] )
			? $assoc_args['output']
			: \trailingslashit( getcwd() ) . $filename;

		// A fresh exporter per page, exactly like the admin AJAX flow: the WC
		// batch exporter's exported-row counter accumulates per instance, so
		// reusing one instance across pages inflates get_total_exported() and
		// ends the export early.
		$make_exporter = function ( $page ) use ( $type, $params, $filename, $assoc_args ) {
			$exporter = CSV_Exports::get_exporter( $type );
			if ( ! $exporter ) {
				WP_CLI::error( 'WooCommerce (with its CSV export framework) must be active.' );
			}
			$exporter->set_filename( $filename );
			$exporter->set_list_params( $params );
			if ( ! empty( $assoc_args['per-page'] ) ) {
				$exporter->set_limit( absint( $assoc_args['per-page'] ) );
			}
			$exporter->set_page( $page );
			return $exporter;
		};

		$page        = 1;
		$exported    = 0;
		$ended_short = false;
		do {
			$exporter = $make_exporter( $page );
			$exporter->generate_file();
			$percent = $exporter->get_percent_complete();
			// Guard against a stall if the underlying data changes mid-export:
			// the run's total is pinned to page 1, so a shrinking set ends on
			// an empty page rather than a shrinking total. Gating on
			// ended_short() rather than the percentage catches the sub-page
			// case too, where the percentage is back at exactly 100 (see
			// CSV_Batch_Exporter::ended_short()).
			if ( $exporter->ended_short() ) {
				$ended_short = true;
				WP_CLI::warning( 'No progress in the last batch; finishing early. The data may have changed during the export.' );
				break;
			}
			// Read off the last page that actually wrote rows: on the terminal
			// empty page the parent's counter reports the pinned total, not
			// what the file holds.
			$exported = $exporter->get_total_exported();
			WP_CLI::log( sprintf( '%d rows (%d%%)', $exported, min( 100, $percent ) ) );
			$page++;
			// The object cache accumulates every loaded subscription/user in a
			// long-running CLI process; without this, large exports exhaust
			// memory (the admin AJAX flow is immune — one page per request).
			if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
		} while ( $percent < 100 );

		$saved = $exporter->save_to( $output );
		// The run is over either way: drop its pinned total rather than
		// leaving the transient to expire on its own.
		$exporter->clear_pinned_total();
		if ( ! $saved ) {
			WP_CLI::error( sprintf( 'Could not write to %s.', $output ) );
		}
		// The no-progress guard can break the loop before completion, shipping
		// a partial CSV; say so rather than reporting an unqualified success.
		if ( ! $ended_short ) {
			WP_CLI::success( sprintf( 'Exported %d rows to %s.', $exported, $output ) );
		} else {
			WP_CLI::warning( sprintf( 'Export incomplete: wrote %d rows to %s before stopping early.', $exported, $output ) );
		}
	}
}
