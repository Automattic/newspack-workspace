<?php
/**
 * CLI tools for the woocommerce support
 *
 * @package Newspack
 */

namespace Newspack;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Order UTM class.
 */
class WooCommerce_Cli {

	/**
	 * Lists the subscriptions that needs to be fixed by the fix_subscriptions_missing_fee command.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count, yaml. Default: table
	 *
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function list_subscriptions_missing_fee( $args, $assoc_args ) {

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$subscriptions = $this->get_all_old_subscriptions();

		$subscriptions = array_filter(
			$subscriptions,
			function( $subscription ) {
				return empty( $subscription->get_fees() );
			}
		);

		if ( empty( $subscriptions ) ) {
			WP_CLI::success( 'No subscriptions missing fees found.' );
			return;
		}

		WP_CLI::success( 'Subscriptions missing fees:' );
		$this->output_subscriptions( $subscriptions, $format );
	}

	/**
	 * Lists the subscriptions that were already fixed by the fix_subscriptions_missing_fee command.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count, yaml. Default: table
	 *
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function list_subscriptions_missing_fee_fixed( $args, $assoc_args ) {

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$subscriptions = $this->get_all_old_subscriptions();

		$subscriptions = array_filter(
			$subscriptions,
			function( $subscription ) {
				return ! empty( $subscription->get_fees() );
			}
		);

		if ( empty( $subscriptions ) ) {
			WP_CLI::success( 'No subscriptions missing fees found.' );
			return;
		}

		WP_CLI::success( 'Fixed subscriptions that had missing fees:' );
		$this->output_subscriptions( $subscriptions, $format );
	}

	/**
	 * Updates the subscriptions that had the Stripe cover fee option added in the old way.
	 *
	 * Will look for all subcriptions that had fees added in the old way and fix them.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If set, no changes will be made.
	 *
	 * @param [type] $args Args.
	 * @param [type] $assoc_args Assoc args.
	 */
	public function fix_subscriptions_missing_fee( $args, $assoc_args ) {

		$dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];

		if ( ! $dry_run ) {
			WP_CLI::line( 'This command will modify the database.' );
			WP_CLI::line( 'Consider running it with --dry-run first to see what it will do.' );
			WP_CLI::confirm( 'Are you sure you want to continue?', $assoc_args );
		}

		$subscriptions = $this->get_all_old_subscriptions();

		$subscriptions = array_filter(
			$subscriptions,
			function( $subscription ) {
				return empty( $subscription->get_fees() );
			}
		);

		if ( empty( $subscriptions ) ) {
			WP_CLI::success( 'No subscriptions missing fees found.' );
			return;
		}

		foreach ( $subscriptions as $subscription ) {

			WP_CLI::success( 'Fixing subscription #' . $subscription->get_id() );
			WP_CLI::log( 'Subscription total is ' . $subscription->get_total() );

			$fee_value = WooCommerce_Cover_Fees::get_fee_value( $subscription->get_total() );
			WP_CLI::log( 'Fee value will be: ' . $fee_value );

			$fee_display_value = WooCommerce_Cover_Fees::get_fee_display_value( $subscription->get_total() );
			WP_CLI::log( 'Fee display value will be: ' . $fee_display_value );

			$new_total = WooCommerce_Cover_Fees::get_total_with_fee( $subscription->get_total() );
			WP_CLI::log( 'Subscription new total will be: ' . $new_total );

			if ( $dry_run ) {
				WP_CLI::warning( 'Dry run, not saving.' );
				continue;
			}

			$fee_name = sprintf(
				// Translators: %s is the fee percentage.
				__( 'Transaction fee (%s)', 'newspack-plugin' ),
				$fee_display_value
			);

			$fee = (object) [
				'name'     => $fee_name,
				'amount'   => $fee_value,
				'tax'      => '',
				'taxable'  => false,
				'tax_data' => '',
			];

			$subscription->add_fee( $fee );
			$subscription->add_order_note( 'Subscription fee fixed and added via script' );
			update_post_meta( $subscription->get_id(), '_newspack_fixed_subscription_fees', 1 );
			$subscription->calculate_totals( false );

			WP_CLI::success( 'Subscription #' . $subscription->get_id() . ' fixed.' );
			WP_CLI::log( '' );
		}
	}

	/**
	 * Fixes or reports active subscriptions that have missed next payment dates.
	 * By default, will only process subscriptions started in the past 90 days.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If set, will report results but will not make any changes.
	 *
	 * [--batch-size=<batch-size>]
	 * : The number of subscriptions to process in each batch. Default: 50.
	 *
	 * [--start-date=<date-string>]
	 * : A date string in YYYY-MM-DD format to use as the start date for the script. Default: 90 days ago.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function fix_missing_next_payment_dates( $args, $assoc_args ) {
		$dry_run    = ! empty( $assoc_args['dry-run'] );
		$now        = time();
		$batch_size = ! empty( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 50;
		$start_date = ! empty( $assoc_args['start-date'] ) ? strtotime( $assoc_args['start-date'] ) : strtotime( '-90 days', $now );

		if ( ! $dry_run ) {
			\WP_CLI::line( "\n=====================\n=     LIVE MODE     =\n=====================\n" );
		} else {
			\WP_CLI::line( "\n===================\n=     DRY RUN     =\n===================\n" );
		}
		sleep( 2 );

		\WP_CLI::log(
			'
Fetching active subscriptions with missing or missed next_payment dates...
		'
		);

		$query_args    = [
			'subscriptions_per_page' => $batch_size,
			'subscription_status'    => [ 'active', 'pending' ],
			'offset'                 => 0,
		];
		$processed     = 0;
		$subscriptions = \wcs_get_subscriptions( $query_args );
		$total_revenue = 0;
		$results       = [];

		while ( ! empty( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription_id => $subscription ) {
				array_shift( $subscriptions );

				// If the subscription start date is before the $args start date, we're done.
				if ( strtotime( $subscription->get_date( 'start' ) ) < $start_date ) {
					$subscriptions = [];
					break;
				}

				$result = self::validate_subscription_dates( $subscription, $dry_run );
				if ( ! $result ) {
					continue;
				}

				if ( $result['missed_periods'] ) {
					$total_revenue += $result['missed_total'];
				}

				$results[] = $result;
				$processed++;

				// Get the next batch.
				if ( empty( $subscriptions ) ) {
					$query_args['offset'] += $batch_size;
					$subscriptions        = \wcs_get_subscriptions( $query_args );
				}
			}
		}

		if ( empty( $results ) ) {
			\WP_CLI::log( 'No subscriptions with missing next_payment dates found in the given time period.' );
		} else {
			\WP_CLI\Utils\format_items(
				'table',
				$results,
				[
					'ID',
					'status',
					'start_date',
					'next_payment_date',
					'end_date',
					'billing_period',
					'missed_periods',
					'missed_total',
				]
			);
			\WP_CLI::success(
				sprintf(
					'Finished processing %d subscriptions. %s',
					$processed,
					$total_revenue ? 'Total missed revenue: ' . \wp_strip_all_tags( html_entity_decode( \wc_price( $total_revenue ) ) ) : ''
				)
			);
		}
		\WP_CLI::line( '' );
	}

	/**
	 * Validate renewal date for the given subscription, accounting for end date.
	 * If missing, calculates the next_payment date and reports missed payments
	 * since the last successful order or subscription start.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param bool            $dry_run If set, will not make any changes.
	 *
	 * @return array|false The result array or false if the subscription is broken.
	 */
	public static function validate_subscription_dates( $subscription, $dry_run = false ) {
		$now                = time();
		$subscription_start = $subscription->get_date( 'start' );
		$next_payment_date  = $subscription->get_date( 'next_payment' );
		$is_in_past         = ! strtotime( $next_payment_date ) || strtotime( $next_payment_date ) < $now;

		// Subscription has a valid next payment date and it's in the future, so skip.
		if ( $next_payment_date && ! $is_in_past ) {
			return false;
		}

		$result = [
			'ID'                => $subscription->get_id(),
			'status'            => $subscription->get_status(),
			'start_date'        => $subscription_start,
			'next_payment_date' => $next_payment_date,
			'end_date'          => $subscription->get_date( 'end' ),
			'billing_period'    => $subscription->get_billing_period(),
			'billing_interval'  => $subscription->get_billing_interval(),
			'missed_periods'    => 0,
			'missed_total'      => 0,
		];

		// Can't process a broken subscription (missing a billing period or interval).
		if ( empty( $result['billing_period'] ) || empty( $result['billing_interval'] ) ) {
			return false;
		}

		$period   = $result['billing_period'];
		$interval = (int) $result['billing_interval'];
		$min_date = strtotime( "+$interval $period", strtotime( $subscription_start ) ); // Start after first period so we don't count in-progress periods as missed.
		$end_date = $now;

		// If there were successful orders for this subscription, start from the last one.
		$last_order = $subscription->get_last_order( 'all', 'any', [ 'pending', 'processing', 'on-hold', 'cancelled', 'refunded', 'failed' ] );
		if ( $last_order && $last_order->get_date_completed() ) {
			$min_date = strtotime( "+$interval $period", $last_order->get_date_completed()->getOffsetTimestamp() );
		}

		// If there's an end date, end there.
		if ( ! empty( $result['end_date'] ) ) {
			$end_date = strtotime( $result['end_date'] );
		}

		while ( $min_date <= $end_date ) {
			$result['missed_periods']++;
			$min_date = strtotime( "+$interval $period", $min_date );
		}

		if ( $result['missed_periods'] ) {
			$result['missed_total'] += $subscription->get_total() * $result['missed_periods'];
		}

		$calculated_next_payment = $subscription->calculate_date( 'next_payment' );
		if ( ! $result['end_date'] || strtotime( $result['end_date'] ) > strtotime( $calculated_next_payment ) ) {
			$result['next_payment_date'] = $calculated_next_payment;
			if ( ! $dry_run ) {
				$subscription->update_dates(
					[
						'next_payment' => $calculated_next_payment,
					]
				);
				$subscription->save();
			}
		}

		return $result;
	}

	/**
	 * Outputs a list of subscription in CLI
	 *
	 * @param WC_Subscription[] $subscriptions The subscriptions.
	 * @param string            $format The output format.
	 * @return void
	 */
	private function output_subscriptions( $subscriptions, $format = 'table' ) {
		$subscriptions = array_map(
			function( $subscription ) {
				$user  = $subscription->get_user();
				$email = $user instanceof \WP_User ? $user->user_email : 'guest';
				return [
					'id'           => $subscription->get_id(),
					'date_created' => $subscription->get_date_created()->__toString(),
					'amount'       => $subscription->get_total(),
					'user_email'   => $email,
				];
			},
			$subscriptions
		);

		WP_CLI\Utils\format_items( $format, $subscriptions, [ 'id', 'amount', 'user_email', 'date_created' ] );
		WP_CLI::log( count( $subscriptions ) . ' subscriptions found.' );
	}

	/**
	 * Get all subscriptions that had the Stripe cover fee option added in the old way.
	 *
	 * We look at the order notes, and not the subscription meta, because there was a bug where the meta was not stored sometimes.
	 *
	 * @return ?WP_Subscription[] The subscriptions.
	 */
	private function get_all_old_subscriptions() {
		global $wpdb;

		// phpcs:ignore
		$parent_order_ids = $wpdb->get_col(
			"SELECT comment_post_ID FROM {$wpdb->comments} WHERE comment_content LIKE '%transaction fee. The total amount will be updated.'"
		);

		$subscriptions = [];
		$ids           = [];

		foreach ( $parent_order_ids as $parent_order_id ) {
			$subs = wcs_get_subscriptions_for_order( $parent_order_id );
			if ( is_array( $subs ) && ! empty( $subs ) ) {
				$sub = array_shift( $subs );
				if ( ! in_array( $sub->get_id(), $ids, true ) ) {
					$subscriptions[] = $sub;
					$ids[]           = $sub->get_id();
				}
			}
		}

		return $subscriptions;
	}
}
