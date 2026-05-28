<?php
/**
 * WooCommerce Subscriptions Integration CLI commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Woocommerce_Subscriptions as WooCommerce_Subscriptions_Integration;
use Newspack\On_Hold_Duration;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions Integration CLI commands.
 */
class WooCommerce_Subscriptions {
	/**
	 * Flag for live mode.
	 *
	 * @var bool
	 */
	private static $live = false;

	/**
	 * Flag for verbose output.
	 *
	 * @var bool
	 */
	private static $verbose = false;

	/**
	 * Subscription ids to process.
	 *
	 * @var bool|array
	 */
	private static $ids = false;

	/**
	 * Migrate status of on-hold WooCommerce subscriptions that have failed all payment retries to expired.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Run the command in live mode, updating the subscriptions.
	 *
	 * [--verbose]
	 * : Produce more output.
	 *
	 * [--ids]
	 * : Comma-separated list of subscription IDs. If provided, only ubscriptions with these IDs will be processed.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 *
	 * @return void
	 */
	public function migrate_expired_subscriptions( $args, $assoc_args ) {
		WP_CLI::line( '' );
		if ( ! WooCommerce_Subscriptions_Integration::is_enabled() ) {
			WP_CLI::error( 'WooCommerce Subscriptions Integration is not enabled.' );
			WP_CLI::line( '' );
			return;
		}
		self::$ids     = isset( $assoc_args['ids'] ) ? explode( ',', $assoc_args['ids'] ) : false;
		self::$live    = isset( $assoc_args['live'] ) ? true : false;
		self::$verbose = isset( $assoc_args['verbose'] ) ? true : false;
		$scheduled     = 0;
		$updated       = 0;
		$trashed       = 0;
		$page          = 1;
		$subscriptions = self::get_subscriptions( $page );
		if ( empty( $subscriptions ) ) {
			WP_CLI::success( 'No on-hold subscriptions to process.' );
			WP_CLI::line( '' );
			return;
		}
		WP_CLI::line( 'Processing subscriptions in ' . ( self::$live ? 'live' : 'dry run' ) . ' mode...' );
		WP_CLI::line( '' );
		while ( ! empty( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				$id = $subscription->get_id();
				if ( self::$verbose ) {
					WP_CLI::line( 'Processing subscription ' . $id . '...' );
				}
				// A pending retry indicates the subscription is awaiting payment retry.
				if ( $subscription->get_date( 'payment_retry' ) > 0 ) {
					if ( self::$verbose ) {
						WP_CLI::line( 'Subscription is awaiting payment retry. Moving to next subscription...' );
						WP_CLI::line( '' );
					}
					continue;
				}
				$last_order = $subscription->get_last_order(
					'all',
					[ 'renewal' ],
					[
						'completed',
						'processing',
						'refunded',
					]
				);
				if ( ! $last_order ) {
					$last_order = $subscription->get_parent();
					// If the last order is the parent order and has a failed status, trash the subscription.
					if ( $last_order && 'failed' === $last_order->get_status() ) {
						if ( self::$verbose ) {
							WP_CLI::line( 'Subscription parent order failed. Flagging for trash...' );
							WP_CLI::line( '' );
						}
						if ( self::$live ) {
							// Flag the update so we don't break wcs_get_subscriptions pagination.
							$subscription->update_meta_data( '_newspack_cli_end_date', $subscription->get_date( 'next_payment' ) );
							$subscription->update_meta_data( '_newspack_cli_to_status', 'trash' );
							$subscription->save();
						}
						++$trashed;
						continue;
					}
				}
				if ( $subscription->is_manual() ) {
					$end_date = $subscription->get_date( 'next_payment' );
					$should_expire = wcs_date_to_time( $end_date ) + ( On_Hold_Duration::get_on_hold_duration() * DAY_IN_SECONDS ) < time();
					// If the manual subscription is within the on-hold duration, schedule expiration.
					if ( ! $should_expire ) {
						if ( self::$verbose ) {
							WP_CLI::line( 'Manual subscription is within the on-hold duration. Scheduling expiration...' );
						}
						if ( self::$live ) {
							On_Hold_Duration::maybe_schedule_expiration( $subscription );
						}
						++$scheduled;
					}
				} else {
					$last_retry       = \WCS_Retry_Manager::store()->get_last_retry_for_order( wcs_get_objects_property( $last_order, 'id' ) );
					$end_date         = $last_retry ? $last_retry->get_date() : $subscription->get_date( 'next_payment' );
					$on_hold_duration = On_Hold_Duration::get_on_hold_duration() * DAY_IN_SECONDS;
					$should_expire    = wcs_date_to_time( $end_date ) + $on_hold_duration < time();
					if ( ! $should_expire ) {
						// If there have been retries, schedule the final retry.
						if ( $last_retry ) {
							if ( self::$verbose ) {
								WP_CLI::line( 'Retry date is within the on-hold duration. Scheduling final retry...' );
							}
							if ( self::$live ) {
								// Retry rules can only be applied when payment attempt flag is set.
								add_filter( 'wcs_is_scheduled_payment_attempt', '__return_true' );
								\WCS_Retry_Manager::maybe_apply_retry_rule( $subscription, $last_order );
								remove_filter( 'wcs_is_scheduled_payment_attempt', '__return_true' );
								if ( 0 === $subscription->get_date( 'payment_retry' ) ) {
									if ( self::$verbose ) {
										WP_CLI::line( 'Failed to schedule payment retry. Scheduling subscription expiration...' );
									}
									On_Hold_Duration::schedule_expiration( $subscription->get_id(), wcs_date_to_time( $end_date ) + $on_hold_duration );
									$subscription->update_meta_data( '_newspack_cli_expiration_scheduled', true );
									$subscription->save();
								} else {
									$subscription->add_order_note(
										__( 'Final payment retry scheduled by Newspack CLI command.', 'newspack-plugin' )
									);
									$subscription->update_meta_data( '_newspack_cli_retry_scheduled', true );
									$subscription->save();
								}
							}
						} else {
							// If there have been no retries, schedule expiration.
							if ( self::$verbose ) {
								WP_CLI::line( 'No retries found. Scheduling subscription expiration...' );
							}
							if ( self::$live ) {
								On_Hold_Duration::schedule_expiration( $subscription->get_id(), $subscription->get_time( 'next_payment' ) + $on_hold_duration );
								$subscription->update_meta_data( '_newspack_cli_expiration_scheduled', true );
								$subscription->save();
							}
						}
						++$scheduled;
					}
				}
				// Expire any subscriptinos that have passed the on-hold duration.
				if ( $should_expire ) {
					if ( self::$verbose ) {
						WP_CLI::line( 'Flagging subscription for expiration...' );
					}
					if ( self::$live ) {
						// Flag the update so we don't break wcs_get_subscriptions pagination.
						$subscription->update_meta_data( '_newspack_cli_end_date', $end_date );
						$subscription->update_meta_data( '_newspack_cli_to_status', 'expired' );
						$subscription->save();
					}
					++$updated;
				}
				if ( self::$verbose ) {
					WP_CLI::line( 'Finished processing subscription ' . $id );
					WP_CLI::line( '' );
				}
			}
			$subscriptions = self::get_subscriptions( ++$page );
		}
		// Update flagged subscriptions.
		$flagged_subscriptions = self::get_flagged_subscriptions();

		if ( self::$verbose ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Processing flagged subscriptions:' );
		}
		while ( ! empty( $flagged_subscriptions ) ) {
			foreach ( $flagged_subscriptions as $flagged_subscription ) {
				if ( self::$live ) {
					$end_date  = $flagged_subscription->get_meta( '_newspack_cli_end_date' );
					$to_status = $flagged_subscription->get_meta( '_newspack_cli_to_status' );
					$flagged_subscription->update_status( $to_status, __( 'Subscription status updated by Newspack CLI command.', 'newspack-plugin' ) );
					$flagged_subscription->delete_meta_data( '_newspack_cli_end_date' );
					$flagged_subscription->delete_meta_data( '_newspack_cli_to_status' );
					$flagged_subscription->update_meta_data( '_newspack_cli_status_updated', true );
					$flagged_subscription->set_end_date( $end_date );
					$flagged_subscription->save();
					if ( self::$verbose ) {
						WP_CLI::line( 'Updated subscription ' . $flagged_subscription->get_id() . ' to ' . $to_status );
					}
				}
			}
			$flagged_subscriptions = self::get_flagged_subscriptions();
		}
		WP_CLI::success( 'Finished processing subscriptions. ' . $updated . ' subscriptions updated. ' . $scheduled . ' retries scheduled. ' . $trashed . ' subscriptions trashed.' );
		if ( ! self::$live ) {
			WP_CLI::warning( 'Dry run. Use --live flag to process live subscriptions.' );
		}
		WP_CLI::line( '' );
	}

	/**
	 * Get subscriptions to process.
	 *
	 * @param int $page Page number.
	 *
	 * @return array
	 */
	private static function get_subscriptions( $page = 1 ) {
		$subscriptions = [];
		if ( false !== self::$ids ) {
			while ( ! empty( self::$ids ) ) {
				$id = array_shift( self::$ids );
				if ( ! is_numeric( $id ) ) {
					continue;
				}
				$subscription = wcs_get_subscription( $id );
				if ( $subscription && 'on-hold' === $subscription->get_status() ) {
					$subscriptions[] = $subscription;
				}
			}
		} else {
			$subscriptions = wcs_get_subscriptions(
				[
					'paged'                  => $page,
					'subscriptions_per_page' => 50,
					'subscription_status'    => 'on-hold',
				]
			);
		}
		return $subscriptions;
	}

	/**
	 * Get flagged subscriptions to update.
	 *
	 * @return array
	 */
	private static function get_flagged_subscriptions() {
		$subscriptions = wcs_get_subscriptions(
			[
				'subscriptions_per_page' => 50,
				'subscription_status'    => 'on-hold',
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_newspack_cli_to_status',
						'compare' => 'EXISTS',
					],
				],
			]
		);
		return $subscriptions;
	}
}
