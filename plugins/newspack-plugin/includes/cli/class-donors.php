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
	 * How many orders to read per query, and lapsed donors to check between cache
	 * clears, when `--batch-size` isn't given. WP-CLI fills that in from the
	 * `default:` in backfill()'s help first, so keep the two equal.
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
	 * alone, so a repeat run skips everyone it flagged before. When a flag can't be
	 * written, the run warns, doesn't count that reader, and tries them again next
	 * time. Donation orders with no customer can't be matched to a reader. They are
	 * skipped and counted.
	 *
	 * A real run prints a line for each reader it flags, naming the user ID and the
	 * flag. The flags don't record what set them, so keep that output: it is the
	 * only record of which readers the run changed.
	 *
	 * Only runs when the donation platform is WooCommerce and WooCommerce
	 * Subscriptions is active.
	 *
	 * Setting the flags changes who matches the donor segments in Campaigns, which is
	 * why this only runs on request. `is_donor` also adds `reader_status=donor` to
	 * Google Ad Manager targeting when Newspack Ads is active, and sets the GA4
	 * `is_donor` parameter to `yes` when Site Kit's GA4 is set up, so warn the
	 * publisher before a real run. Each flag write fires `newspack_reader_data_updated`.
	 * When this command was added, nothing in the Newspack plugins listened to that
	 * action, so a run doesn't queue ESP syncs and isn't throttled. Custom code on the
	 * site that listens to it runs twice for each reader flagged, because flagging a
	 * reader writes two keys: the flag it sets and the one it clears.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the counts and write nothing.
	 *
	 * [--batch-size=<n>]
	 * : How many orders to read, or lapsed donors to check, between cache clears.
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
		// Without Subscriptions the scan finds no donation subscription, so a reader
		// whose recurring donation ended would be flagged a donor, not a former one,
		// and a later run would leave that flag alone.
		if ( ! function_exists( 'wcs_get_subscriptions_for_product' ) || ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'wcs_get_subscription_statuses' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions must be active to tell former donors apart.' );
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
		$holders = Subscribers_Donors::holders(
			0,
			function () {
				// Every donation subscription is read, so clear the object cache
				// between chunks, as the order walk below does between pages.
				self::clear_object_cache();
			}
		);
		$live    = array_flip( $holders['live'] );
		$checked = 0;
		foreach ( $holders['ended'] as $user_id ) {
			if ( isset( $live[ $user_id ] ) ) {
				continue;
			}
			// Checking a reader's flags loads their user meta, so clear the cache after
			// every `--batch-size` readers checked, already-flagged ones included: on a
			// repeat run every lapsed donor is already flagged.
			if ( $checked > 0 && 0 === $checked % $batch_size ) {
				self::clear_object_cache();
			}
			++$checked;
			$counted[ $user_id ] = true;
			if ( ! self::is_unflagged( $user_id ) ) {
				++$already;
				continue;
			}
			// A dry run can't know which writes would be refused, so it counts every
			// reader it would try.
			if ( ! $dry_run && ! self::write_flag( $user_id, 'is_former_donor' ) ) {
				continue;
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
				if ( ! $dry_run && ! self::write_flag( $user_id, 'is_donor' ) ) {
					continue;
				}
				++$donors;
			}
			$read = count( $orders );
			++$page;
			// A long run loads every order and customer into the object cache.
			self::clear_object_cache();
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
	 * Set a donor flag, confirm it was written, and list the reader in the output.
	 *
	 * The Reader_Data setters drop the error update_item() returns when a reader
	 * already holds the most items it allows, so the only sign of a refused write is
	 * the flag reading false afterwards. `is_former_donor` can also come back false
	 * after a half write: the setter stores `is_donor = false` in the last free slot
	 * and is refused the second key.
	 *
	 * @param int    $user_id The reader's user ID.
	 * @param string $flag    The flag to set: `is_donor` or `is_former_donor`.
	 *
	 * @return bool Whether the flag now reads true.
	 */
	private static function write_flag( int $user_id, string $flag ): bool {
		$data = [ 'user_id' => $user_id ];
		if ( 'is_former_donor' === $flag ) {
			Reader_Data::set_is_former_donor( time(), $data );
		} else {
			Reader_Data::set_is_donor( time(), $data );
		}
		if ( Reader_Data::get_bool( $user_id, $flag ) ) {
			// line(), not log(): `--quiet` silences log(), and this list is the only record of who was flagged.
			WP_CLI::line( sprintf( 'Flagged user %d: %s', $user_id, $flag ) );
			return true;
		}
		WP_CLI::warning( sprintf( 'Could not set `%s` for user %d: the write was refused. The reader\'s data may be full.', $flag, $user_id ) );
		return false;
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

	/**
	 * Drop what the run has loaded into this process's copy of the object cache, so
	 * a long run's memory stays bounded. A persistent cache such as memcached is
	 * left alone; wp_cache_flush() would empty it for the whole site.
	 */
	private static function clear_object_cache(): void {
		if ( function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
			\WP_CLI\Utils\wp_clear_object_cache();
		}
	}
}
