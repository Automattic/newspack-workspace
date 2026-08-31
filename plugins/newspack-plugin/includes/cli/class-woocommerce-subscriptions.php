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
use Newspack\Content_Gate;
use Newspack\Access_Rules;
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
		// Update flagged subscriptions. Live only: the loop terminates by clearing each
		// subscription's flag meta, and that write only happens in live mode — a dry run over
		// a subscription still flagged by an interrupted live run would re-query the same set
		// forever while doing nothing.
		if ( self::$live ) {
			$flagged_subscriptions = self::get_flagged_subscriptions();

			if ( self::$verbose ) {
				WP_CLI::line( '' );
				WP_CLI::line( 'Processing flagged subscriptions:' );
			}
			while ( ! empty( $flagged_subscriptions ) ) {
				foreach ( $flagged_subscriptions as $flagged_subscription ) {
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
				$flagged_subscriptions = self::get_flagged_subscriptions();
			}
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
	 * A product ID already persisted on an access surface is the exception to variant B:
	 * both a gate's paid-access rule and a group/row/stack block's inline
	 * `newspackAccessControlRules` attribute store raw product IDs, and
	 * `Access_Rules::has_active_subscription()` never re-validates them, so a trashed product
	 * either one still lists keeps granting access. Those subscriptions are matched by Access
	 * Control today, so they are reported separately as fragile — tagged variant G in the
	 * table (granted: matched by a persisted reference, while the picker can no longer offer
	 * the product for a fresh configuration) — and are refused by --map, since re-pointing
	 * them would move the subscription off the very ID the surface matches on and revoke
	 * access.
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
	 *   Malformed and duplicate pairs are reported, never silently dropped, and the command
	 *   exits non-zero if any mapping was rejected.
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

		$live_products      = self::get_live_subscription_products();
		$access_product_ids = self::get_access_referenced_product_ids();
		$audit              = self::audit_active_subscriptions( $live_products, $access_product_ids );
		$rows               = $audit['rows'];

		$at_risk = self::filter_rows_by_status( $rows, 'at_risk' );
		$fragile = self::filter_rows_by_status( $rows, 'access_referenced' );

		if ( empty( $at_risk ) ) {
			// Reports what was scanned rather than asserting nothing exists: a subscription
			// leaving active status mid-scan can still shift later offset windows, so the
			// wording must not read as a guarantee of completeness.
			WP_CLI::success( sprintf( 'Scanned %d active subscription(s); none flagged with a missing or non-gate-selectable line-item product.', $audit['scanned'] ) );
		} else {
			WP_CLI::line( sprintf( '%d active subscription(s) have a line-item product Access Control cannot match:', count( $at_risk ) ) );
			WP_CLI::line( '' );
			self::render_audit_table( $at_risk );
		}

		if ( ! empty( $fragile ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '%d active subscription(s) are on a non-gate-selectable product that a gate or block still references. Access Control matches these today, so they are NOT at risk and --map refuses them. They are fragile: the product picker can no longer offer that product, so re-saving the gate or block would drop it.', count( $fragile ) ) );
			WP_CLI::line( '' );
			self::render_audit_table( $fragile );
		}

		$raw_map = \WP_CLI\Utils\get_flag_value( $assoc_args, 'map', '' );
		// WP-CLI hands a bare `--map` (or `--no-map`) through as a boolean. Name the actual
		// mistake — stringifying it would warn about a "1"/"0" token the operator never typed.
		if ( ! is_string( $raw_map ) ) {
			WP_CLI::error( '--map requires a value in <subscription_id>:<product_id> form.' );
			return;
		}
		if ( '' === trim( $raw_map ) ) {
			return;
		}

		$parsed = self::parse_map_argument_verbose( $raw_map );
		foreach ( $parsed['rejected'] as $token ) {
			WP_CLI::warning( sprintf( 'Ignoring malformed --map token "%s" — expected <subscription_id>:<product_id>.', $token ) );
		}
		$map = $parsed['map'];
		if ( empty( $map ) ) {
			// Erroring (rather than returning quietly) so a mistyped --map can never read as
			// "the repair ran and there was nothing to do".
			WP_CLI::error( '--map was supplied but no well-formed <subscription_id>:<product_id> pair could be parsed from it — nothing was repaired.' );
			return;
		}

		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );
		WP_CLI::line( '' );
		if ( $dry_run ) {
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		$repaired = 0;
		$rejected = count( $parsed['rejected'] );
		foreach ( $map as $subscription_id => $product_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				WP_CLI::warning( sprintf( 'Subscription %d not found — skipping.', $subscription_id ) );
				++$rejected;
				continue;
			}
			// Isolate per-mapping failures the same way card_expiry_warning_backfill does: a
			// third-party hook throwing on the order-save path must not abort the batch and
			// leave the remaining mappings neither applied nor reported.
			try {
				$result = self::repair_subscription_product( $subscription, $product_id, $dry_run, $access_product_ids );
			} catch ( \Throwable $e ) {
				++$rejected;
				WP_CLI::warning( sprintf( 'Subscription %d: repair threw — %s', $subscription_id, $e->getMessage() ) );
				continue;
			}
			if ( ! $result['ok'] ) {
				++$rejected;
				WP_CLI::warning( sprintf( 'Subscription %d: %s', $subscription_id, $result['message'] ) );
				continue;
			}
			++$repaired;
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

		$summary = sprintf(
			'%s %d subscription(s), rejected %d mapping(s).',
			$dry_run ? 'Would repair' : 'Repaired',
			$repaired,
			$rejected
		);
		// Exit non-zero on any rejection so automation notices a partial batch, matching
		// card_expiry_warning_backfill. WP_CLI::error halts with a non-zero status.
		if ( $rejected > 0 ) {
			WP_CLI::error( sprintf( '%s See warnings above.', $summary ) );
		}
		WP_CLI::success( $summary );
	}

	/**
	 * Render a set of audit rows as a WP-CLI table.
	 *
	 * @param array $rows Audit rows as returned by `build_audit_rows()`.
	 */
	private static function render_audit_table( array $rows ): void {
		$table = array_map(
			function( $row ) {
				return [
					'subscription' => $row['subscription_id'],
					'user'         => $row['user'],
					'variant'      => $row['variant'],
					'guess'        => self::format_guess( $row ),
					'evidence'     => $row['evidence'],
				];
			},
			$rows
		);
		\WP_CLI\Utils\format_items( 'table', $table, [ 'subscription', 'user', 'variant', 'guess', 'evidence' ] );
	}

	/**
	 * Format an audit row's name-match guess for the table.
	 *
	 * The guess is the only actionable column — an operator reads it and pastes the ID into
	 * --map — so more than one product of that name is surfaced as ambiguous with every
	 * candidate listed, rather than resolved silently to whichever matched first.
	 *
	 * @param array $row An audit row.
	 * @return string The guess cell.
	 */
	private static function format_guess( array $row ): string {
		$ids = $row['guess_product_ids'];
		if ( empty( $ids ) ) {
			return '(no match)';
		}
		if ( 1 === count( $ids ) ) {
			return sprintf( '%s (#%d)', $row['guess_product_name'], $ids[0] );
		}
		return sprintf(
			'%s (#%s — ambiguous)',
			$row['guess_product_name'],
			implode( ', #', $ids )
		);
	}

	/**
	 * Build audit rows for the flagged subscriptions in a given set.
	 *
	 * A subscription is at risk (`status` = `at_risk`) when it has at least one broken line
	 * item (missing or non-gate-selectable product) and no line item Access Control could
	 * match — neither a gate-selectable product nor a product ID a gate or block already
	 * references.
	 *
	 * A subscription kept matchable only by a product ID persisted on a gate or a block-level
	 * access rule is reported with `status` = `access_referenced`: Access Control matches it
	 * today, so it is not at risk, but no gate or block can be re-configured with that product.
	 *
	 * @param array $subscriptions      Subscriptions to inspect (WC_Subscription objects).
	 * @param array $live_products      Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @param array $access_product_ids Product IDs persisted on gates/blocks as `product_id => [ reference_label, ... ]`.
	 * @return array List of audit rows.
	 */
	public static function build_audit_rows( array $subscriptions, array $live_products, array $access_product_ids = [] ): array {
		$rows = [];
		foreach ( $subscriptions as $subscription ) {
			$finding = self::classify_subscription_product_link( $subscription, $access_product_ids );
			if ( null === $finding ) {
				continue;
			}
			$flagged  = $finding['at_risk'] ? $finding['broken'] : $finding['access_referenced'];
			$guesses  = self::guess_products_by_name( $flagged[0]['name'], $live_products );
			$variants = array_values( array_unique( wp_list_pluck( $flagged, 'variant' ) ) );
			sort( $variants );
			$rows[] = [
				'subscription_id'    => (int) $subscription->get_id(),
				'status'             => $finding['at_risk'] ? 'at_risk' : 'access_referenced',
				'user'               => self::describe_user( (int) $subscription->get_customer_id() ),
				'variant'            => implode( ', ', $variants ),
				'guess_product_ids'  => wp_list_pluck( $guesses, 'id' ),
				'guess_product_name' => ! empty( $guesses ) ? $guesses[0]['name'] : null,
				'evidence'           => implode( ' ', wp_list_pluck( $flagged, 'evidence' ) ),
			];
		}
		return $rows;
	}

	/**
	 * Filter audit rows down to a single status.
	 *
	 * @param array  $rows   Audit rows.
	 * @param string $status The `status` value to keep.
	 * @return array The matching rows, re-indexed.
	 */
	private static function filter_rows_by_status( array $rows, string $status ): array {
		return array_values(
			array_filter(
				$rows,
				function( $row ) use ( $status ) {
					return $status === $row['status'];
				}
			)
		);
	}

	/**
	 * Re-attach (variant A) or swap onto (variant B) a live product for a single subscription.
	 *
	 * Executes only the explicit mapping the operator passed — never a guess. The swap
	 * target must be a gate-selectable simple `subscription` product (a gate can only ever
	 * reference a selectable product, and a `variable-subscription` target would pair a
	 * variable parent with no variation — a shape purchases never record), and the
	 * subscription must be one the audit flagged as at risk. Refuses subscriptions with more
	 * than one broken line item, the no-line-items case, line items carrying a variation ID,
	 * and subscriptions a gate or block still matches, so the operator resolves those by hand. This edits billing-relevant data, so an order note records the
	 * prior product ID and the caller logs exactly what changed.
	 *
	 * @param \WC_Subscription $subscription       The subscription to repair.
	 * @param int              $product_id         The live product ID to attach.
	 * @param bool             $dry_run            When true, report what would change without writing.
	 * @param array            $access_product_ids Product IDs persisted on gates/blocks as `product_id => [ reference_label, ... ]`.
	 * @return array Result: ok, applied, subscription_id, variant, old_product_id, new_product_id, message.
	 */
	public static function repair_subscription_product( \WC_Subscription $subscription, int $product_id, bool $dry_run, array $access_product_ids = [] ): array {
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
			$result['message'] = sprintf( 'Mapped product #%d is not a gate-selectable subscription product (type: %s, status: %s) — map onto a listed subscription product.', $product_id, $target->get_type(), $target->get_status() );
			return $result;
		}
		// A variable-subscription target would mint a line item pairing a variable parent
		// with variation_id = 0 — a shape normal purchase flow never records (buying a
		// variable subscription always stores its variation), and one the variation-first
		// consumers (tier-switch lookup, teams renewal match) don't expect. The audit and
		// guess still surface variable products as evidence; repairing onto one is a manual
		// job where the operator picks the variation deliberately.
		if ( 'variable-subscription' === $target->get_type() ) {
			$result['message'] = sprintf( 'Mapped product #%d is a variable subscription — attaching its parent without a variation creates a line-item shape purchases never produce. Repair this subscription manually, choosing the variation deliberately.', $product_id );
			return $result;
		}

		$finding = self::classify_subscription_product_link( $subscription, $access_product_ids );
		if ( null === $finding ) {
			$result['message'] = 'Subscription is not flagged by the audit (no missing/non-selectable line-item product) — nothing to repair.';
			return $result;
		}
		if ( ! $finding['at_risk'] ) {
			// A gate or block still lists the line item's product ID, and Access Control
			// matches stored IDs without re-validating them — so this reader has access today
			// and re-pointing the line item would take it away.
			$result['message'] = sprintf(
				'Subscription is matched by Access Control today — its line-item product is still referenced by %s. Repairing would move it off the ID that reference matches on and revoke access; update the gate or block instead.',
				implode( ', ', $finding['access_referenced'][0]['references'] )
			);
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
		$old_variation_id = (int) $item->get_variation_id();
		if ( $old_variation_id > 0 ) {
			// The variation ID is the only surviving record of which variation the reader
			// bought, and it is read by the team-renewal match, the membership-expiry
			// safeguard and the tier switch lookup. Clearing it silently breaks those
			// linkages; keeping it alongside a new parent resolves the item to a variation of
			// the previous product. Neither is safe to decide here, so refuse.
			$result['message'] = sprintf( 'Line item carries variation ID %d — repair it manually so the variation link is resolved deliberately.', $old_variation_id );
			return $result;
		}
		$result['variant']        = $broken['variant'];
		$result['old_product_id'] = (int) $item->get_product_id();

		if ( ! $dry_run ) {
			// Re-point only the product reference Access Control matches on. The line-item
			// name and stored totals are deliberately left untouched — the reader keeps the
			// price they signed up for, and calculate_totals() is intentionally not called.
			$item->set_product_id( $product_id );
			$item->save();
			// Record the prior value in the data itself: a --live run is otherwise only
			// traceable through terminal scrollback, and support needs to be able to
			// reconstruct or reverse a mapping months later.
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: previous product ID or "none", 2: new product ID. */
					__( 'Newspack CLI: subscription line-item product re-pointed from %1$s to %2$d to restore Access Control matching.', 'newspack-plugin' ),
					0 === $result['old_product_id'] ? __( 'none', 'newspack-plugin' ) : (string) $result['old_product_id'],
					$product_id
				)
			);
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
		return self::parse_map_argument_verbose( $raw )['map'];
	}

	/**
	 * Parse the --map argument, keeping the tokens that were discarded.
	 *
	 * The caller warns about each discarded token: silently dropping them would let
	 * `--map=51-1234` print nothing and exit 0, which reads as "the repair ran and there was
	 * nothing to do". A later pair for an already-mapped subscription is also reported,
	 * since the map is keyed by subscription ID and the last one would otherwise win
	 * unannounced.
	 *
	 * @param string $raw Comma-separated `<subscription_id>:<product_id>` pairs.
	 * @return array `[ 'map' => [ subscription_id => product_id ], 'rejected' => string[] ]`.
	 */
	public static function parse_map_argument_verbose( string $raw ): array {
		$map      = [];
		$rejected = [];
		foreach ( explode( ',', $raw ) as $pair ) {
			$pair = trim( $pair );
			if ( '' === $pair ) {
				continue;
			}
			if ( false === strpos( $pair, ':' ) ) {
				$rejected[] = $pair;
				continue;
			}
			list( $subscription_id, $product_id ) = array_map( 'trim', explode( ':', $pair, 2 ) );
			if ( ! ctype_digit( $subscription_id ) || ! ctype_digit( $product_id ) ) {
				$rejected[] = $pair;
				continue;
			}
			if ( isset( $map[ (int) $subscription_id ] ) ) {
				$rejected[] = $pair;
				continue;
			}
			$map[ (int) $subscription_id ] = (int) $product_id;
		}
		return [
			'map'      => $map,
			'rejected' => $rejected,
		];
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
	 * Line items on a product ID that a gate or block already references are collected
	 * separately and clear the `at_risk` flag. Both surfaces persist raw product IDs and never
	 * re-validate them, so `WC_Subscription::has_product()` still matches such an item and the
	 * reader has access today — the picker merely can't offer that product for a new
	 * configuration. Testing picker-selectability alone would both over-report the at-risk
	 * population and let a repair move a working subscription off the ID its gate/block matches on.
	 *
	 * Liveness keys on the line item's parent `product_id`: the picker
	 * (`Access_Rules::get_subscription_products_options`) offers `subscription` /
	 * `variable-subscription` parents, never variations, so it is the parent's liveness — not
	 * the specific variation's — that decides whether a fresh gate can be configured to match.
	 * The access-referenced check is wider: `WC_Subscription::has_product()` compares a stored
	 * rule value against both `product_id` and `variation_id`, so an already-persisted rule
	 * holding either ID keeps granting access and both are honored here.
	 *
	 * @param \WC_Subscription $subscription       The subscription to classify.
	 * @param array            $access_product_ids Product IDs persisted on gates/blocks as `product_id => [ reference_label, ... ]`.
	 * @return array|null `[ 'at_risk' => bool, 'broken' => [ ... ], 'access_referenced' => [ ... ] ]` or null.
	 */
	private static function classify_subscription_product_link( \WC_Subscription $subscription, array $access_product_ids = [] ): ?array {
		if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
			return null;
		}
		$items = $subscription->get_items();
		if ( empty( $items ) ) {
			// A subscription with no line items is as unmatchable as an orphaned one.
			return [
				'at_risk'           => true,
				'access_referenced' => [],
				'broken'            => [
					[
						'item'     => null,
						'name'     => '',
						'variant'  => 'A',
						'evidence' => 'Subscription has no line items.',
					],
				],
			];
		}
		$broken            = [];
		$access_referenced = [];
		$has_live_product  = false;
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
			if ( $product && self::is_selectable_product( $product ) ) {
				$has_live_product = true;
				continue;
			}
			// A gate or block that already lists an ID this line item carries keeps matching it
			// whatever happened to the product afterwards, so the reader has access — checked
			// before the missing / non-selectable branches because it holds for a hard-deleted
			// product too. `WC_Subscription::has_product()` compares a stored rule value against
			// BOTH the line item's product_id and its variation_id, so a legacy or hand-edited
			// rule storing a variation ID grants access exactly as a parent ID does.
			$variation_id  = (int) $item->get_variation_id();
			$referenced_id = 0;
			if ( isset( $access_product_ids[ $product_id ] ) ) {
				$referenced_id = $product_id;
			} elseif ( $variation_id > 0 && isset( $access_product_ids[ $variation_id ] ) ) {
				$referenced_id = $variation_id;
			}
			if ( $referenced_id > 0 ) {
				$access_referenced[] = [
					'item'       => $item,
					'name'       => $name,
					'variant'    => 'G',
					'references' => $access_product_ids[ $referenced_id ],
					'evidence'   => sprintf(
						'Line-item product #%d is not gate-selectable but %s is still referenced by %s, so Access Control matches it today.',
						$product_id,
						$referenced_id === $product_id ? 'it' : sprintf( 'its variation #%d', $referenced_id ),
						implode( ', ', $access_product_ids[ $referenced_id ] )
					),
				];
				continue;
			}
			if ( ! $product ) {
				$broken[] = [
					'item'     => $item,
					'name'     => $name,
					'variant'  => 'A',
					'evidence' => sprintf( 'Line-item product #%d no longer exists.', $product_id ),
				];
				continue;
			}
			$broken[] = [
				'item'     => $item,
				'name'     => $name,
				'variant'  => 'B',
				'evidence' => sprintf( 'Line-item product #%d ("%s") is not gate-selectable (type: %s, status: %s).', $product_id, $product->get_name(), $product->get_type(), $product->get_status() ),
			];
		}
		if ( $has_live_product || ( empty( $broken ) && empty( $access_referenced ) ) ) {
			return null;
		}
		return [
			// Only genuinely unmatchable subscriptions are at risk: one kept matchable by a
			// gate- or block-referenced product has access today and must never be repaired.
			'at_risk'           => empty( $access_referenced ),
			'broken'            => $broken,
			'access_referenced' => $access_referenced,
		];
	}

	/**
	 * Best-guess the intended product(s) for a broken line item by matching its name against
	 * the live products. Exact (case-insensitive) name match only — a loose match would be
	 * misleading, and the guess is evidence, never an input to a repair.
	 *
	 * Every match is returned, not just the first: a retired product typically keeps its name
	 * while its replacement is created alongside it, so duplicates are the norm here rather
	 * than an oddity, and the operator must see the ambiguity before picking an ID for --map.
	 *
	 * @param string $item_name     The broken line item's name.
	 * @param array  $live_products Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @return array Matches as `[ 'id' => int, 'name' => string ]`, empty when none matches.
	 */
	private static function guess_products_by_name( string $item_name, array $live_products ): array {
		$needle = strtolower( trim( $item_name ) );
		if ( '' === $needle ) {
			return [];
		}
		$matches = [];
		foreach ( $live_products as $product ) {
			if ( strtolower( trim( (string) $product['name'] ) ) === $needle ) {
				$matches[] = [
					'id'   => (int) $product['id'],
					'name' => $product['name'],
				];
			}
		}
		return $matches;
	}

	/**
	 * Collect the subscription product IDs Access Control still matches on, from every surface
	 * that persists them: content gates and block-level access rules.
	 *
	 * Access Control matches a subscription against the raw product IDs saved on a surface;
	 * `Access_Rules::has_active_subscription()` passes them straight to
	 * `WC_Subscription::has_product()` with no status or type check. So a stored ID keeps
	 * granting access even once the product is trashed or deleted, which is precisely the case
	 * the audit must not mistake for "Access Control can never match this". Both surfaces have
	 * to be swept, or a subscription kept matchable only by the un-swept one is over-reported
	 * as at risk and a repair would move it off the very ID that surface matches on.
	 *
	 * @return array Product ID => list of human reference labels (e.g. `gate #12`, `block on post #45`).
	 */
	private static function get_access_referenced_product_ids(): array {
		$referenced = [];
		foreach ( [ self::get_gate_referenced_product_ids(), self::get_block_referenced_product_ids() ] as $surface ) {
			foreach ( $surface as $product_id => $labels ) {
				foreach ( $labels as $label ) {
					if ( ! in_array( $label, $referenced[ $product_id ] ?? [], true ) ) {
						$referenced[ $product_id ][] = $label;
					}
				}
			}
		}
		return $referenced;
	}

	/**
	 * Collect subscription product IDs persisted on published, actively-enforcing content gates.
	 *
	 * Only published gates with an active paid-access section are considered — a gate that
	 * isn't enforcing grants nobody access.
	 *
	 * @return array Product ID => list of `gate #<id>` labels.
	 */
	private static function get_gate_referenced_product_ids(): array {
		if ( ! class_exists( '\Newspack\Content_Gate' ) ) {
			return [];
		}
		$gate_ids = get_posts(
			[
				'post_type'      => Content_Gate::GATE_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Content-gate CPT; config-scale.
				'fields'         => 'ids',
			]
		);
		$referenced = [];
		foreach ( $gate_ids as $gate_id ) {
			$settings = Content_Gate::get_custom_access_settings( $gate_id );
			if ( empty( $settings['active'] ) || empty( $settings['access_rules'] ) ) {
				continue;
			}
			$label = sprintf( 'gate #%d', (int) $gate_id );
			foreach ( self::subscription_ids_from_access_rules( $settings['access_rules'] ) as $product_id ) {
				if ( ! in_array( $label, $referenced[ $product_id ] ?? [], true ) ) {
					$referenced[ $product_id ][] = $label;
				}
			}
		}
		return $referenced;
	}

	/**
	 * Collect subscription product IDs persisted in block-level access rules across post content.
	 *
	 * Group/row/stack blocks carry the same paid-access rule shape inline as the
	 * `newspackAccessControlRules` attribute (custom mode), and `Block_Visibility` evaluates it
	 * through the same `Access_Rules` engine gates use — so a product ID a block still lists
	 * grants access exactly as a gate's does. Gate-mode blocks reference gate IDs instead, whose
	 * products are already found by the gate scan, so only custom-mode rules add anything here.
	 *
	 * Narrowed with a `post_content LIKE` on the attribute name before parsing: block rules live
	 * in post content, not meta, and parsing every published post would be needlessly heavy.
	 * The per-post object cache is cleared as we go so a large content set doesn't accumulate.
	 *
	 * @return array Product ID => list of `block on post #<id>` labels.
	 */
	private static function get_block_referenced_product_ids(): array {
		if ( ! class_exists( '\Newspack\Content_Gate' ) ) {
			return [];
		}
		global $wpdb;
		// A one-shot content LIKE for a manually-run audit: narrows to posts carrying the
		// attribute before parsing, with nothing worth caching for the life of a single CLI run.
		// No post_status filter: `Block_Visibility::filter_render_block()` applies none — a
		// custom-visibility block on a private, scheduled or draft post runs the same
		// `Access_Rules` match whenever that post renders (and a trashed post can be
		// restored). Missing such a reference would both list the subscription as at-risk
		// and disarm the --map refusal that protects it; over-collecting only ever refuses
		// a repair, per this method's invariant.
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
				'%' . $wpdb->esc_like( 'newspackAccessControlRules' ) . '%'
			)
		);
		$referenced = [];
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$label = sprintf( 'block on post #%d', (int) $post_id );
				foreach ( self::block_referenced_product_ids( parse_blocks( $post->post_content ) ) as $product_id ) {
					if ( ! in_array( $label, $referenced[ $product_id ] ?? [], true ) ) {
						$referenced[ $product_id ][] = $label;
					}
				}
			}
			// Parsing walks the full post; drop it from the cache so a wide content set doesn't
			// accumulate every post object in memory over the sweep. Targeted deletes only:
			// `clean_post_cache()` also fires purge hooks and bumps the shared posts
			// last-changed key — invalidating every cached WP_Query site-wide once per matched
			// post, from a read-only audit against the live persistent cache. Same reason
			// `clean_object_term_cache()` is not called: it bumps the terms last-changed key,
			// and nothing in this loop populates term caches to begin with.
			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
		}
		return $referenced;
	}

	/**
	 * Recursively pull subscription product IDs out of a block tree's access-rule attributes.
	 *
	 * Mirrors `Block_Visibility`'s own gate: only custom-mode blocks whose custom-access section
	 * is active contribute; gate-mode and inactive blocks grant nobody access on this surface.
	 * Any block carrying the attribute is inspected regardless of block name — the tool errs
	 * toward over-collecting a referenced ID (which only ever makes it refuse a repair), never
	 * toward missing one (which would revoke access).
	 *
	 * @param array $blocks Parsed blocks as returned by `parse_blocks()`.
	 * @return int[] Subscription product IDs referenced by active custom-mode rules in the tree.
	 */
	private static function block_referenced_product_ids( array $blocks ): array {
		$product_ids = [];
		foreach ( $blocks as $block ) {
			$attrs = $block['attrs'] ?? [];
			// Gate-mode blocks reference gate IDs (covered by the gate scan), not products.
			if ( 'gate' !== ( $attrs['newspackAccessControlMode'] ?? 'gate' ) ) {
				$rules = $attrs['newspackAccessControlRules'] ?? [];
				// The block parser can yield a stdClass for object-typed attributes after a
				// JSON round-trip, matching Block_Visibility's own defensive cast.
				$rules  = is_object( $rules ) ? (array) $rules : $rules;
				$custom = is_array( $rules ) ? ( $rules['custom_access'] ?? [] ) : [];
				if ( ! empty( $custom['active'] ) && ! empty( $custom['access_rules'] ) ) {
					$product_ids = array_merge( $product_ids, self::subscription_ids_from_access_rules( $custom['access_rules'] ) );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$product_ids = array_merge( $product_ids, self::block_referenced_product_ids( $block['innerBlocks'] ) );
			}
		}
		return $product_ids;
	}

	/**
	 * Extract the subscription-rule product IDs from an access-rules structure.
	 *
	 * Normalizes first so both the flat and grouped rule shapes are handled the same way the
	 * runtime `Access_Rules::evaluate_rules()` handles them.
	 *
	 * @param array $access_rules Access rules (flat or grouped) as stored on a gate or block.
	 * @return int[] Positive subscription product IDs, in encounter order (may contain duplicates).
	 */
	private static function subscription_ids_from_access_rules( array $access_rules ): array {
		$product_ids = [];
		foreach ( Access_Rules::normalize_rules( $access_rules ) as $group ) {
			foreach ( (array) $group as $rule ) {
				if ( 'subscription' !== ( $rule['slug'] ?? '' ) ) {
					continue;
				}
				foreach ( (array) ( $rule['value'] ?? [] ) as $product_id ) {
					$product_id = (int) $product_id;
					if ( $product_id > 0 ) {
						$product_ids[] = $product_id;
					}
				}
			}
		}
		return $product_ids;
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
	private static function is_selectable_product( \WC_Product $product ): bool {
		return in_array( $product->get_type(), self::SELECTABLE_PRODUCT_TYPES, true )
			&& in_array( $product->get_status(), self::SELECTABLE_PRODUCT_STATUSES, true );
	}

	/**
	 * Audit every active-status subscription, one page at a time.
	 *
	 * Paginates and classifies each page as it is fetched, keeping only the (small) flagged
	 * row set rather than holding every WC_Subscription object in memory, and clearing the
	 * per-request object cache each page so the subscriptions and products instantiated
	 * along the way don't accumulate either — so a large store doesn't OOM. Terminates on
	 * the first short (non-full) page.
	 *
	 * Pages with `offset`, not `paged`: `wcs_get_subscriptions()` strips `paged` from the
	 * args it forwards to `WC_Order_Query` (it only reaches the has_product_query branch,
	 * which needs a product_id/variation_id arg this call doesn't pass), so a `paged` loop
	 * re-fetches the same first page forever and never terminates.
	 *
	 * @param array $live_products      Live subscription products as `[ 'id' => int, 'name' => string ]`.
	 * @param array $access_product_ids Product IDs persisted on gates/blocks as `product_id => [ reference_label, ... ]`.
	 * @return array `[ 'rows' => audit rows, 'scanned' => subscriptions scanned ]`.
	 */
	private static function audit_active_subscriptions( array $live_products, array $access_product_ids = [] ): array {
		$per_page       = 100;
		$offset         = 0;
		$scanned        = 0;
		$rows           = [];
		$previous_batch = [];
		do {
			$batch = wcs_get_subscriptions(
				[
					'subscription_status'    => WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES,
					'subscriptions_per_page' => $per_page,
					'offset'                 => $offset,
					// The default sort (start_date DESC) has no ID tiebreaker, so rows sharing
					// a creation second — the norm on bulk-imported stores — have no guaranteed
					// order across offset windows and a subscription can slip between pages
					// unnoticed. ID is deterministic, and ASC pins new subscriptions to the
					// unvisited end of the scan instead of shifting every later window.
					'orderby'                => 'ID',
					'order'                  => 'ASC',
				]
			);
			// `wcs_get_subscriptions()` keys its return by subscription ID, so an identical
			// key set means the query stopped advancing. That is what the `paged` shape did,
			// and a third-party `woocommerce_get_subscriptions_query_args` filter dropping
			// `offset` can still produce it. Halt loudly: looping forever would pin a
			// publisher's CLI, and continuing would report a scan that silently stopped short.
			$batch_ids = array_keys( $batch );
			if ( ! empty( $batch_ids ) && $batch_ids === $previous_batch ) {
				WP_CLI::error(
					sprintf(
						'The subscription query stopped advancing at offset %d — it returned the same page twice. Aborting; the audit is incomplete and its results must not be trusted.',
						$offset
					)
				);
			}
			$previous_batch = $batch_ids;
			$batch_size     = count( $batch );
			$scanned       += $batch_size;
			$rows           = array_merge( $rows, self::build_audit_rows( $batch, $live_products, $access_product_ids ) );
			$offset        += $per_page;
			// A long scan is otherwise indistinguishable from a hung one; there is no total
			// to drive a real progress bar, since the query is paginated blind.
			WP_CLI::log( sprintf( 'Scanned %d active subscription(s), %d flagged so far...', $scanned, count( $rows ) ) );
			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( $batch_size === $per_page );
		return [
			'rows'    => $rows,
			'scanned' => $scanned,
		];
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
	 * Pages with `offset`: `wcs_get_subscriptions()` drops `paged` before building the
	 * `WC_Order_Query` args on this code path, so a `paged` loop keeps re-fetching the same
	 * first page and never advances.
	 *
	 * @param int $page Page number (1-based).
	 *
	 * @return array
	 */
	private static function get_subscriptions( $page = 1 ) {
		$per_page      = 50;
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
					'offset'                 => ( max( 1, (int) $page ) - 1 ) * $per_page,
					'subscriptions_per_page' => $per_page,
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
