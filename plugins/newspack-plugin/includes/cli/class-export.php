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
	 * : Subscription statuses to export, comma-separated (e.g. active,on-hold). Defaults to all statuses.
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
	 * [--date-from=<yyyy-mm-dd>]
	 * : Only subscriptions created on or after this date. Supersedes --month.
	 *
	 * [--date-to=<yyyy-mm-dd>]
	 * : Only subscriptions created on or before this date. Supersedes --month.
	 *
	 * [--delimiter=<delimiter>]
	 * : Field delimiter: comma (default), semicolon, tab or pipe.
	 *
	 * [--date-format=<format>]
	 * : PHP date format for date columns. Default Y-m-d H:i:s.
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
	 * : Only users with these roles, comma-separated (e.g. subscriber,customer).
	 *
	 * [--search=<term>]
	 * : Search term (same semantics as the admin users list search).
	 *
	 * [--date-from=<yyyy-mm-dd>]
	 * : Only users registered on or after this date.
	 *
	 * [--date-to=<yyyy-mm-dd>]
	 * : Only users registered on or before this date.
	 *
	 * [--meta=<keys>]
	 * : Add one column per user meta key, comma-separated. Keys the site does not store are rejected.
	 *
	 * [--delimiter=<delimiter>]
	 * : Field delimiter: comma (default), semicolon, tab or pipe.
	 *
	 * [--date-format=<format>]
	 * : PHP date format for date columns. Default Y-m-d H:i:s.
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
		if ( ! empty( $assoc_args['search'] ) ) {
			$params['s'] = $assoc_args['search'];
		}
		self::run_export( 'users', $params, $assoc_args );
	}

	/**
	 * Translate the export-option flags into the export config the admin
	 * dialog posts, so both surfaces go through one sanitizer.
	 *
	 * @param string $type       Export type: 'subscriptions' or 'users'.
	 * @param array  $assoc_args CLI associative args.
	 * @return array Sanitized export config.
	 */
	private static function build_export_config( string $type, array $assoc_args ): array {
		$raw = [
			'date_from' => $assoc_args['date-from'] ?? '',
			'date_to'   => $assoc_args['date-to'] ?? '',
			'delimiter' => $assoc_args['delimiter'] ?? '',
		];

		$date_format = $assoc_args['date-format'] ?? '';
		if ( '' !== $date_format ) {
			// Anything outside the offered formats is still a valid PHP date
			// format on the command line; route it through the custom slot.
			if ( in_array( $date_format, CSV_Exports::DATE_FORMATS, true ) ) {
				$raw['date_format'] = $date_format;
			} else {
				$raw['date_format']        = 'custom';
				$raw['date_format_custom'] = $date_format;
			}
		}

		if ( 'users' === $type ) {
			$raw['meta_keys'] = self::split_list( $assoc_args['meta'] ?? '' );
			$raw['roles']     = self::split_list( $assoc_args['role'] ?? '' );
		}
		if ( 'subscriptions' === $type ) {
			$raw['statuses'] = self::split_list( $assoc_args['status'] ?? '' );
		}

		// selection_submitted is deliberately not set here. It exists so an
		// emptied dialog overrides the list filter it was opened on; the CLI
		// has no list filter, so setting it would only suppress the flags that
		// travel in $params — --month among them.
		$config = CSV_Exports::sanitize_export_config( $raw, $type );
		self::assert_flags_survived_sanitization( $raw, $config, $assoc_args );
		return $config;
	}

	/**
	 * Stop the run when a supplied flag did not survive sanitization.
	 *
	 * The dialog can drop an unrecognized value silently — its inputs come from
	 * a list the server rendered. A hand-typed flag cannot: dropping it removes
	 * the restriction the operator asked for, so `--role=subsciber` would write
	 * every user to a CSV instead of none.
	 *
	 * @param array $raw        The raw config assembled from the flags.
	 * @param array $config     The sanitized config.
	 * @param array $assoc_args CLI associative args.
	 */
	private static function assert_flags_survived_sanitization( array $raw, array $config, array $assoc_args ): void {
		foreach ( [
			'roles'    => 'role',
			'statuses' => 'status',
		] as $key => $flag ) {
			// Compared value by value rather than by count: a repeated value
			// collapses on one side only, which would let a typo through
			// alongside a duplicate.
			$dropped = [];
			foreach ( $raw[ $key ] ?? [] as $value ) {
				$normalized = 'statuses' === $key
					? \wcs_sanitize_subscription_status_key( $value )
					: \sanitize_key( $value );
				if ( ! in_array( $normalized, $config[ $key ] ?? [], true ) ) {
					$dropped[] = $value;
				}
			}
			if ( ! empty( $dropped ) ) {
				WP_CLI::error( sprintf( 'Unrecognized --%1$s value: %2$s', $flag, implode( ', ', array_unique( $dropped ) ) ) );
			}
		}
		$dropped_keys = array_diff( $raw['meta_keys'] ?? [], $config['meta_keys'] ?? [] );
		if ( ! empty( $dropped_keys ) ) {
			WP_CLI::error( sprintf( 'This site stores no user meta under: %s', implode( ', ', $dropped_keys ) ) );
		}
		foreach ( [
			'date_from' => 'date-from',
			'date_to'   => 'date-to',
		] as $key => $flag ) {
			if ( '' !== ( $raw[ $key ] ?? '' ) && ! isset( $config[ $key ] ) ) {
				WP_CLI::error( sprintf( 'Invalid --%1$s value "%2$s"; expected YYYY-MM-DD.', $flag, $raw[ $key ] ) );
			}
		}
		if ( '' !== ( $raw['delimiter'] ?? '' ) && ! isset( $config['delimiter'] ) ) {
			WP_CLI::error( sprintf( 'Unrecognized --delimiter value "%s"; expected comma, semicolon, tab or pipe.', $raw['delimiter'] ) );
		}
		// Truncating this would not fail; it would format every date cell wrongly.
		$custom_format = $raw['date_format_custom'] ?? '';
		if ( '' !== $custom_format && mb_strlen( $custom_format ) > CSV_Exports::MAX_CUSTOM_DATE_FORMAT_LENGTH ) {
			WP_CLI::error( sprintf( '--date-format must be at most %d characters.', CSV_Exports::MAX_CUSTOM_DATE_FORMAT_LENGTH ) );
		}
	}

	/**
	 * Split a comma-separated flag value into a list.
	 *
	 * @param mixed $value Flag value.
	 * @return string[]
	 */
	private static function split_list( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
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
		$config        = self::build_export_config( $type, $assoc_args );
		$make_exporter = function ( $page ) use ( $type, $params, $config, $filename, $assoc_args ) {
			$exporter = CSV_Exports::get_exporter( $type );
			if ( ! $exporter ) {
				WP_CLI::error( 'WooCommerce (with its CSV export framework) must be active.' );
			}
			$exporter->set_filename( $filename );
			$exporter->set_list_params( $params );
			$exporter->set_export_config( $config );
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
