<?php
/**
 * Donor flag backfill CLI command.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Donations;
use Newspack\Reader_Data;
use Newspack\Subscribers_Donors;

defined( 'ABSPATH' ) || exit;

/**
 * Flags readers who donated before the donor flags existed.
 */
class Donors {

	/**
	 * How many orders to read per query when `--batch-size` isn't given.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Flag readers who donated before the donor flags existed.
	 *
	 * Gives the flags to readers who have neither, with the same rules as the donor
	 * badge in Audience > Subscribers. A reader whose donation subscription ended,
	 * with no live one, gets `is_former_donor`. Any other reader with a completed
	 * donation order gets `is_donor`. Readers who already have either flag are left
	 * alone, so a repeat run flags no one new. Donation orders with no customer can't
	 * be matched to a reader. They are skipped and counted.
	 *
	 * Only runs when the donation platform is WooCommerce.
	 *
	 * Setting the flags changes who matches the donor segments in Campaigns, which is
	 * why this only runs on request. Each flag write fires `newspack_reader_data_updated`.
	 * When this command was added, nothing in the Newspack plugins listened to that
	 * action, so a run doesn't queue ESP syncs and isn't throttled. Custom code on the
	 * site that listens to it runs once per write.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the counts and write nothing.
	 *
	 * [--batch-size=<n>]
	 * : How many orders to read at a time.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack donors backfill --dry-run
	 *     wp newspack donors backfill
	 *     wp newspack donors backfill --batch-size=500
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public static function backfill( array $args, array $assoc_args ): void {
		$dry_run    = filter_var( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ), FILTER_VALIDATE_BOOLEAN );
		$batch_size = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', self::DEFAULT_BATCH_SIZE );

		if ( ! Subscribers_Donors::is_enabled() ) {
			WP_CLI::error( 'Donor flags only apply when the donation platform is WooCommerce.' );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}
		if ( $batch_size < 1 ) {
			WP_CLI::error( 'The batch size must be a whole number of at least 1.' );
		}

		$donors  = 0;
		$former  = 0;
		$already = 0;
		$guests  = 0;

		// Every reader the run has counted, so one with several donation orders, or
		// both an ended subscription and an order, lands in exactly one tally. It also
		// stops the order walk from overwriting a flag this run just set: in a real
		// run set_is_donor() clears `is_former_donor`, and in a dry run nothing is
		// written to tell the two passes apart.
		$counted = [];

		// Rules, as in Subscribers_Donors::kind(): a live donation subscription outranks
		// an ended one, so only holders with no live one are former donors.
		$holders = Subscribers_Donors::holders( 0 );
		$live    = array_flip( $holders['live'] );
		foreach ( $holders['ended'] as $user_id ) {
			if ( isset( $live[ $user_id ] ) ) {
				continue;
			}
			$counted[ $user_id ] = true;
			if ( ! self::is_unflagged( $user_id ) ) {
				++$already;
				continue;
			}
			if ( ! $dry_run ) {
				Reader_Data::set_is_former_donor( time(), [ 'user_id' => $user_id ] );
			}
			++$former;
		}

		// Ordered by ID so an order placed during the run lands on a later page
		// instead of shifting the pages already read.
		$page = 1;
		do {
			$orders = \wc_get_orders(
				[
					'type'    => 'shop_order',
					'status'  => [ 'wc-completed' ],
					'limit'   => $batch_size,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				]
			);
			foreach ( $orders as $order ) {
				if ( ! Donations::is_donation_order( $order ) ) {
					continue;
				}
				$user_id = (int) $order->get_customer_id();
				if ( 0 === $user_id ) {
					++$guests;
					continue;
				}
				if ( isset( $counted[ $user_id ] ) ) {
					continue;
				}
				$counted[ $user_id ] = true;
				if ( ! self::is_unflagged( $user_id ) ) {
					++$already;
					continue;
				}
				if ( ! $dry_run ) {
					Reader_Data::set_is_donor( time(), [ 'user_id' => $user_id ] );
				}
				++$donors;
			}
			$read = count( $orders );
			++$page;
			// A long run loads every order and customer into the object cache.
			if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
				\WP_CLI\Utils\wp_clear_object_cache();
			}
		} while ( $read === $batch_size );

		WP_CLI::success(
			sprintf(
				'%s %d donor(s) and %d former donor(s). %d already flagged; %d guest order(s) skipped.',
				$dry_run ? 'Would flag' : 'Flagged',
				$donors,
				$former,
				$already,
				$guests
			)
		);
	}

	/**
	 * Whether a reader has neither donor flag.
	 *
	 * @param int $user_id The reader's user ID.
	 *
	 * @return bool
	 */
	private static function is_unflagged( int $user_id ): bool {
		return ! Reader_Data::get_bool( $user_id, 'is_donor' ) && ! Reader_Data::get_bool( $user_id, 'is_former_donor' );
	}
}
