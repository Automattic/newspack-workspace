<?php
/**
 * WooCommerce Subscriptions Integration CLI commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Woocommerce_Subscriptions as WooCommerce_Subscriptions_Integration;
use Newspack\WooCommerce_Connection;
use Newspack\On_Hold_Duration;
use Newspack\Card_Expiry_Warning;
use Newspack\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions Integration CLI commands.
 */
class WooCommerce_Subscriptions {
	/**
	 * Product statuses the gate product picker offers (mirrors the default statuses
	 * `Access_Rules::get_subscription_products_options` -> `wc_get_products` lists). A
	 * subscription line item on a product outside this set is not gate-selectable, so
	 * Access Control can never reference it. Single source of truth shared by the audit
	 * classifier and the repair target check so the two can't drift.
	 *
	 * @var string[]
	 */
	const SELECTABLE_PRODUCT_STATUSES = [ 'publish', 'private', 'draft', 'pending' ];

	/**
	 * Product types the gate product picker offers. A repair target outside this set can
	 * never be referenced by a gate (and a non-`product` post such as a variation would
	 * also throw in WC_Order_Item_Product::set_product_id()).
	 *
	 * @var string[]
	 */
	const SELECTABLE_PRODUCT_TYPES = [ 'subscription', 'variable-subscription' ];

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
	 * Audit active subscriptions whose line-item product Access Control can never match,
	 * and optionally repair them from an explicit operator-supplied product mapping.
	 *
	 * Access Control's paid-access rule grants access on an active subscription to one of
	 * the products configured on a gate. Two field data shapes break that link, so a reader
	 * with an active subscription silently loses access when WooCommerce Memberships is
	 * switched off:
	 *
	 *   - Variant A (orphaned line item): the line item carries no product reference (the
	 *     product was hard-deleted, or the subscription was created by hand), or the
	 *     subscription has no line items at all. AC can never match it.
	 *   - Variant B (non-gate-selectable product): the line item points at a product the gate
	 *     picker can never offer — the wrong type (only subscription / variable-subscription
	 *     are selectable) or a status outside the picker's allowlist (e.g. trashed or
	 *     auto-draft). No gate can be configured with it.
	 *
	 * With no --map the command audits only (read-only): it prints one row per at-risk
	 * subscription with a best-guess intended product derived from the line-item name. The
	 * guess is evidence only — the tool never repairs from its own guess. Pass --map to
	 * repair the subscriptions named explicitly; repairs are a dry-run unless --live is given.
	 *
	 * ## OPTIONS
	 *
	 * [--map=<pairs>]
	 * : Comma-separated `<subscription_id>:<product_id>` pairs to repair. Each re-attaches
	 *   (variant A) or swaps onto (variant B) the given live product. Only the subscriptions
	 *   named here are ever modified. Example: --map=51:1234,73:500
	 *
	 * [--live]
	 * : Apply the --map repairs. Without this flag repairs run as a dry-run and write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack audit-subscription-products
	 *     wp newspack audit-subscription-products --map=51:1234,73:500
	 *     wp newspack audit-subscription-products --map=51:1234,73:500 --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function audit_subscription_products( $args, $assoc_args ) {
		// Gate on WC Subscriptions being active — the actual precondition. This is a
		// read-only data-integrity audit; it does not need Reader Activation (so it can be
		// run as migration prep before RAS is toggled on), unlike the expiration-path
		// commands that gate on is_enabled().
		if ( ! WooCommerce_Subscriptions_Integration::is_active() || ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
			return;
		}

		$live_products = self::get_live_subscription_products();
		$rows          = self::audit_active_subscriptions( $live_products );

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No active subscriptions with a missing or non-gate-selectable line-item product were found.' );
		} else {
			WP_CLI::line( sprintf( '%d active subscription(s) have a line-item product Access Control cannot match:', count( $rows ) ) );
			WP_CLI::line( '' );
			$table = array_map(
				function( $row ) {
					return [
						'subscription' => $row['subscription_id'],
						'user'         => $row['user'],
						'variant'      => $row['variant'],
						'guess'        => null !== $row['guess_product_id']
							? sprintf( '%s (#%d)', $row['guess_product_name'], $row['guess_product_id'] )
							: '(no match)',
						'evidence'     => $row['evidence'],
					];
				},
				$rows
			);
			\WP_CLI\Utils\format_items( 'table', $table, [ 'subscription', 'user', 'variant', 'guess', 'evidence' ] );
		}

		$map = self::parse_map_argument( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'map', '' ) );
		if ( empty( $map ) ) {
			return;
		}

		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		foreach ( $map as $subscription_id => $product_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				WP_CLI::warning( sprintf( 'Subscription %d not found — skipping.', $subscription_id ) );
				continue;
			}
			$result = self::repair_subscription_product( $subscription, $product_id, $dry_run );
			if ( ! $result['ok'] ) {
				WP_CLI::warning( sprintf( 'Subscription %d: %s', $subscription_id, $result['message'] ) );
				continue;
			}
			WP_CLI::success(
				sprintf(
					'Subscription %d (variant %s): %s line-item product %s -> %d.',
					$subscription_id,
					$result['variant'],
					$result['applied'] ? 'set' : 'would set',
					0 === $result['old_product_id'] ? '(none)' : (string) $result['old_product_id'],
					$result['new_product_id']
				)
			);
		}
	}

	/**
	 * Build audit rows for the at-risk subscriptions in a given set.
	 *
	 * A subscription is at risk when it has at least one broken line item (missing or
	 * trashed product) and no line item pointing at a live product — i.e. Access Control
	 * has no product on the subscription it could ever match against a gate.
	 *
	 * @param array $subscriptions Subscriptions to inspect (WC_Subscription objects).
	 * @param array $live_products Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @return array List of audit rows.
	 */
	public static function build_audit_rows( array $subscriptions, array $live_products ): array {
		$rows = [];
		foreach ( $subscriptions as $subscription ) {
			$finding = self::classify_subscription_product_link( $subscription );
			if ( null === $finding ) {
				continue;
			}
			$first_broken = $finding['broken'][0];
			$guess        = self::guess_product_by_name( $first_broken['name'], $live_products );
			$variants     = array_values( array_unique( wp_list_pluck( $finding['broken'], 'variant' ) ) );
			sort( $variants );
			$rows[] = [
				'subscription_id'    => (int) $subscription->get_id(),
				'user'               => self::describe_user( (int) $subscription->get_customer_id() ),
				'variant'            => implode( ', ', $variants ),
				'guess_product_id'   => null !== $guess ? $guess['id'] : null,
				'guess_product_name' => null !== $guess ? $guess['name'] : null,
				'evidence'           => implode( ' ', wp_list_pluck( $finding['broken'], 'evidence' ) ),
			];
		}
		return $rows;
	}

	/**
	 * Re-attach (variant A) or swap onto (variant B) a live product for a single subscription.
	 *
	 * Executes only the explicit mapping the operator passed — never a guess. The swap
	 * target must be a gate-selectable subscription product (a gate can only ever reference
	 * one of those), and the subscription must be one the audit flagged. Refuses
	 * subscriptions with more than one broken line item, and the no-line-items case, so the
	 * operator resolves the ambiguity by hand. This edits billing-relevant data, so the
	 * caller logs exactly what changed on which subscription.
	 *
	 * @param \WC_Subscription $subscription The subscription to repair.
	 * @param int              $product_id   The live product ID to attach.
	 * @param bool             $dry_run      When true, report what would change without writing.
	 * @return array Result: ok, applied, subscription_id, variant, old_product_id, new_product_id, message.
	 */
	public static function repair_subscription_product( $subscription, int $product_id, bool $dry_run ): array {
		$result = [
			'ok'              => false,
			'applied'         => false,
			'subscription_id' => (int) $subscription->get_id(),
			'variant'         => '',
			'old_product_id'  => 0,
			'new_product_id'  => $product_id,
			'message'         => '',
		];

		// The swap target must be a product a gate can actually reference. Anything the
		// picker would not list (wrong type — simple, variation, grouped — or a non-listed
		// status) leaves the reader just as unmatchable, so reject it rather than report a
		// hollow success. This also blocks a variation ID, whose non-`product` post type
		// would otherwise throw in set_product_id() and abort the batch.
		$target = wc_get_product( $product_id );
		if ( ! $target ) {
			$result['message'] = sprintf( 'Mapped product #%d does not exist — mapping rejected.', $product_id );
			return $result;
		}
		if ( ! self::is_selectable_product( $target ) ) {
			$result['message'] = sprintf( 'Mapped product #%d is not a gate-selectable subscription product (type: %s, status: %s) — map onto a listed subscription/variable-subscription product.', $product_id, $target->get_type(), $target->get_status() );
			return $result;
		}

		$finding = self::classify_subscription_product_link( $subscription );
		if ( null === $finding ) {
			$result['message'] = 'Subscription is not flagged by the audit (no missing/non-selectable line-item product) — nothing to repair.';
			return $result;
		}
		if ( count( $finding['broken'] ) > 1 ) {
			$result['message'] = 'Subscription has more than one broken line item — repair it manually to avoid an ambiguous mapping.';
			return $result;
		}

		$broken = $finding['broken'][0];
		$item   = $broken['item'];
		if ( null === $item ) {
			$result['message'] = 'Subscription has no line item to re-point — add a subscription product to it manually.';
			return $result;
		}
		$result['variant']        = $broken['variant'];
		$result['old_product_id'] = (int) $item->get_product_id();

		if ( ! $dry_run ) {
			// Re-point only the product reference Access Control matches on. The line-item
			// name and stored totals are deliberately left untouched — the reader keeps the
			// price they signed up for, and calculate_totals() is intentionally not called.
			$item->set_product_id( $product_id );
			$item->set_variation_id( 0 );
			$item->save();
			$subscription->save();
			$result['applied'] = true;
		}
		$result['ok'] = true;
		return $result;
	}

	/**
	 * Parse the --map argument into an explicit `subscription_id => product_id` map.
	 *
	 * Only well-formed numeric `<sub>:<product>` pairs are kept; blanks and malformed
	 * tokens are dropped so a typo can never silently repair the wrong subscription.
	 *
	 * @param string $raw Comma-separated `<subscription_id>:<product_id>` pairs.
	 * @return array Map of subscription ID => product ID.
	 */
	public static function parse_map_argument( string $raw ): array {
		$map = [];
		foreach ( explode( ',', (string) $raw ) as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair || false === strpos( $pair, ':' ) ) {
				continue;
			}
			list( $subscription_id, $product_id ) = array_map( 'trim', explode( ':', $pair, 2 ) );
			if ( ! ctype_digit( $subscription_id ) || ! ctype_digit( $product_id ) ) {
				continue;
			}
			$map[ (int) $subscription_id ] = (int) $product_id;
		}
		return $map;
	}

	/**
	 * Classify a subscription's line items against the Access Control paid-access rule.
	 *
	 * Returns null when the subscription is out of scope (not active) or healthy (has any
	 * line item on a gate-selectable product). Otherwise returns the broken line items, each
	 * tagged with its variant (A: no line items / no or deleted product; B: product exists
	 * but is not gate-selectable — wrong type or a non-listed status, e.g. trashed), evidence,
	 * and the WC_Order_Item_Product so a repair can re-point it (null for the no-line-items case).
	 *
	 * Keys on the line item's parent `product_id`, deliberately ignoring `variation_id`:
	 * gates are only ever configured with a parent product ID (the picker,
	 * `Access_Rules::get_subscription_products_options`, offers `subscription` /
	 * `variable-subscription` parents, never variations), and `WC_Subscription::has_product()`
	 * matches a gate's parent ID against the line item's `product_id`. So it is the parent's
	 * liveness — not the specific variation's — that decides whether Access Control can match.
	 *
	 * @param object $subscription The subscription to classify.
	 * @return array|null `[ 'broken' => [ [ 'item', 'name', 'variant', 'evidence' ], ... ] ]` or null.
	 */
	private static function classify_subscription_product_link( $subscription ): ?array {
		if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
			return null;
		}
		$items = $subscription->get_items();
		if ( empty( $items ) ) {
			// A subscription with no line items is as unmatchable as an orphaned one.
			return [
				'broken' => [
					[
						'item'     => null,
						'name'     => '',
						'variant'  => 'A',
						'evidence' => 'Subscription has no line items.',
					],
				],
			];
		}
		$broken           = [];
		$has_live_product = false;
		foreach ( $items as $item ) {
			$product_id = (int) $item->get_product_id();
			$name       = $item->get_name();
			if ( 0 === $product_id ) {
				$broken[] = [
					'item'     => $item,
					'name'     => $name,
					'variant'  => 'A',
					'evidence' => 'Line item carries no product ID.',
				];
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$broken[] = [
					'item'     => $item,
					'name'     => $name,
					'variant'  => 'A',
					'evidence' => sprintf( 'Line-item product #%d no longer exists.', $product_id ),
				];
				continue;
			}
			if ( ! self::is_selectable_product( $product ) ) {
				$broken[] = [
					'item'     => $item,
					'name'     => $name,
					'variant'  => 'B',
					'evidence' => sprintf( 'Line-item product #%d ("%s") is not gate-selectable (type: %s, status: %s).', $product_id, $product->get_name(), $product->get_type(), $product->get_status() ),
				];
				continue;
			}
			$has_live_product = true;
		}
		if ( empty( $broken ) || $has_live_product ) {
			return null;
		}
		return [ 'broken' => $broken ];
	}

	/**
	 * Best-guess the intended product for a broken line item by matching its name against
	 * the live products. Exact (case-insensitive) name match only — a loose match would be
	 * misleading, and the guess is evidence, never an input to a repair.
	 *
	 * @param string $item_name     The broken line item's name.
	 * @param array  $live_products Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @return array|null The matched `[ 'id' => int, 'name' => string ]`, or null when none matches.
	 */
	private static function guess_product_by_name( string $item_name, array $live_products ): ?array {
		$needle = strtolower( trim( $item_name ) );
		if ( '' === $needle ) {
			return null;
		}
		foreach ( $live_products as $product ) {
			if ( strtolower( trim( (string) $product['name'] ) ) === $needle ) {
				return [
					'id'   => (int) $product['id'],
					'name' => $product['name'],
				];
			}
		}
		return null;
	}

	/**
	 * Fetch the gate-selectable subscription products, for guess-matching.
	 *
	 * Mirrors `Access_Rules::get_subscription_products_options`: the same product types and
	 * statuses the gate product picker lists (via the shared allowlist constants).
	 *
	 * @return array List of `[ 'id' => int, 'name' => string ]`.
	 */
	private static function get_live_subscription_products(): array {
		$products = wc_get_products(
			[
				'type'   => self::SELECTABLE_PRODUCT_TYPES,
				'status' => self::SELECTABLE_PRODUCT_STATUSES,
				'limit'  => -1,
			]
		);
		$live = [];
		foreach ( $products as $product ) {
			$live[] = [
				'id'   => $product->get_id(),
				'name' => $product->get_name(),
			];
		}
		return $live;
	}

	/**
	 * Whether a product is one the gate picker would list — the same type + status allowlist
	 * as `get_live_subscription_products()`, so the repair target check and the audit's
	 * live-product set can't drift.
	 *
	 * @param \WC_Product $product The product to test.
	 * @return bool
	 */
	private static function is_selectable_product( $product ): bool {
		return in_array( $product->get_type(), self::SELECTABLE_PRODUCT_TYPES, true )
			&& in_array( $product->get_status(), self::SELECTABLE_PRODUCT_STATUSES, true );
	}

	/**
	 * Audit every active-status subscription, one page at a time.
	 *
	 * Paginates and classifies each page as it is fetched, keeping only the (small) at-risk
	 * row set rather than holding every WC_Subscription object in memory — so a large store
	 * doesn't OOM. Terminates on the first short (non-full) page.
	 *
	 * @param array $live_products Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @return array List of audit rows.
	 */
	private static function audit_active_subscriptions( array $live_products ): array {
		$per_page = 100;
		$paged    = 1;
		$rows     = [];
		do {
			$batch = wcs_get_subscriptions(
				[
					'subscription_status'    => WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES,
					'subscriptions_per_page' => $per_page,
					'paged'                  => $paged,
				]
			);
			$batch_size = count( $batch );
			$rows       = array_merge( $rows, self::build_audit_rows( $batch, $live_products ) );
			++$paged;
		} while ( $batch_size === $per_page );
		return $rows;
	}

	/**
	 * Describe a subscription's owner for the audit table.
	 *
	 * @param int $user_id The customer/user ID.
	 * @return string A human-readable owner label.
	 */
	private static function describe_user( int $user_id ): string {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '(guest)';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return sprintf( '#%d (deleted)', $user_id );
		}
		return sprintf( '%s (#%d)', $user->user_email, $user_id );
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
