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
use Newspack\Card_Expiry_Warning;
use Newspack\Emails;

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
	 * Backfill card-expiry warning emails for subscriptions currently in
	 * the warning window.
	 *
	 * Companion to the first-deploy seed mechanism in
	 * `Newspack\Card_Expiry_Warning::scan_expiring_cards()`. The seed
	 * marks every currently-in-window (subscription, token) pair as
	 * already-warned WITHOUT sending — protecting publishers from a
	 * Day 0 burst — and the seed log entry references this command as
	 * the explicit opt-in path to actually send those deferred warnings.
	 *
	 * Calls `Card_Expiry_Warning::maybe_send_warning(..., true)` so the
	 * seeded SENT_META doesn't block the send.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If passed, print what would be sent without actually sending. No
	 *   confirmation prompt; safe to re-run.
	 *
	 * [--limit=<n>]
	 * : Cap sends per invocation. Default: no cap. The cron path's
	 *   per-pass cap (`newspack_card_expiry_warning_limit_per_pass`,
	 *   default 100) bounds the number of SENDS per cron pass on
	 *   migration / burst days — it does NOT bound discovery, which runs
	 *   unbounded (PHP_INT_MAX, no SQL LIMIT) and filters already-processed
	 *   pairs via the idempotency gate. This command is a
	 *   publisher-initiated explicit action where no cap is the expected
	 *   default.
	 *
	 * [--days=<n>]
	 * : Window in days. Defaults to the value of
	 *   `Card_Expiry_Warning::get_days_before_expiry()` (14 unless
	 *   filtered via `newspack_card_expiry_warning_days`).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt. Auto-handled by WP_CLI::confirm.
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function card_expiry_warning_backfill( $args, $assoc_args ) {
		if ( ! WooCommerce_Subscriptions_Integration::is_enabled() ) {
			WP_CLI::error( 'WooCommerce Subscriptions Integration is not enabled.' );
			return;
		}
		if ( ! class_exists( '\\Newspack\\Card_Expiry_Warning' ) ) {
			WP_CLI::error( 'Card_Expiry_Warning class is not loaded.' );
			return;
		}

		// Gate the prompt on the send-precondition so the operator
		// doesn't confirm "send to N readers" only to discover the email
		// post is in draft and nothing actually went out. Skip the
		// guard for --dry-run so publishers can still preview what
		// would send even with the email unpublished.
		$is_dry_run = ! empty( $assoc_args['dry-run'] );
		if ( ! $is_dry_run && ! Emails::can_send_email( Card_Expiry_Warning::EMAIL_TYPE ) ) {
			WP_CLI::error(
				'The card-expiry-warning email is not currently sendable. The email post may be in draft status, or Newspack Newsletters is not active. Publish the email and try again.'
			);
			return;
		}
		$days = isset( $assoc_args['days'] )
			? max( 1, (int) $assoc_args['days'] )
			: Card_Expiry_Warning::get_days_before_expiry();

		// --limit caps ACTUAL SENDS per invocation, not SQL discovery —
		// applied in the foreach loop below after the idempotency gate.
		// Applying it as a SQL LIMIT (the legacy shape) would cause the
		// same starvation as scan_expiring_cards had: ORDER BY token_id
		// ASC + LIMIT N means the same first-N tokens surface each run,
		// and once those N are gated (SENT, unattached, etc.) every
		// subsequent run no-ops and the unprocessed remainder is never
		// reached. Caught in Copilot review on #155.
		$limit = isset( $assoc_args['limit'] )
			? max( 1, (int) $assoc_args['limit'] )
			: PHP_INT_MAX;

		// Discovery uses PHP_INT_MAX (no SQL LIMIT) — already-processed
		// pairs filter out in the loop via is_already_processed, and
		// only actual sends count toward $limit.
		$pairs = Card_Expiry_Warning::get_in_window_pairs( $days, PHP_INT_MAX );

		// Filter to the pairs that would actually send (skip pairs the
		// idempotency gate would block, even with bypass=true — i.e.,
		// pairs with SENT meta from a prior real send). This makes the
		// --dry-run output accurate (no false-positive "Would send to"
		// reports for pairs that wouldn't fire) and gives the confirm
		// prompt's count the same meaning as the post-run "Sent N" total.
		$pairs = array_values(
			array_filter(
				$pairs,
				function ( $pair ) {
					$token      = $pair['token'];
					$token_id   = $token->get_id();
					$expiry_key = $token_id . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();
					return ! Card_Expiry_Warning::is_already_processed( $pair['subscription'], $token_id, $expiry_key, true );
				}
			)
		);
		$count = count( $pairs );

		if ( 0 === $count ) {
			WP_CLI::success( 'No (subscription, token) pairs in the warning window that would send. (Already-processed pairs are filtered out.)' );
			return;
		}

		// Confirmation gate (dry-run skips because no harmful action).
		// $assoc_args is passed so `--yes` is auto-handled by WP_CLI.
		// $count above already reflects only the pairs that WOULD send;
		// the prompt is honest about scope.
		if ( ! $is_dry_run ) {
			$prompt_count = min( $count, $limit );
			WP_CLI::confirm(
				sprintf( 'This will send card-expiry warning emails to %d reader(s). Continue?', $prompt_count ),
				$assoc_args
			);
		}

		$sent     = 0;
		$failures = 0;
		foreach ( $pairs as $pair ) {
			if ( $sent >= $limit ) {
				break;
			}
			$subscription = $pair['subscription'];
			$token        = $pair['token'];
			$line         = sprintf(
				'%s %s (sub #%d, card ...%s, expires %s/%s)',
				$is_dry_run ? 'Would send to' : 'Sent to',
				$subscription->get_billing_email(),
				$subscription->get_id(),
				$token->get_last4(),
				$token->get_expiry_month(),
				$token->get_expiry_year()
			);
			if ( $is_dry_run ) {
				WP_CLI::log( $line );
				++$sent;
				continue;
			}
			// Isolate per-pair failures: one throwing pair (a bad address, a
			// third-party hook throwing on save) must not abort the backfill
			// and block every later valid pair across operator re-runs.
			try {
				if ( Card_Expiry_Warning::maybe_send_warning( $subscription, $token, true ) ) {
					WP_CLI::log( $line );
					++$sent;
				}
			} catch ( \Throwable $e ) {
				++$failures;
				WP_CLI::warning(
					sprintf(
						'Failed for sub #%d (card ...%s): %s',
						$subscription->get_id(),
						$token->get_last4(),
						$e->getMessage()
					)
				);
			}
		}

		$summary = sprintf(
			'%s %d email(s).',
			$is_dry_run ? 'Would send' : 'Sent',
			$sent
		);

		// Exit non-zero when any pair failed so cron/automation wrappers
		// notice a partial backfill instead of treating it as a clean run.
		// WP_CLI::error halts with a non-zero status.
		if ( $failures > 0 ) {
			WP_CLI::error( sprintf( '%s %d pair(s) failed — see warnings above.', $summary, $failures ) );
		}

		WP_CLI::success( $summary );
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
