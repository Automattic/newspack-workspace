<?php
/**
 * Card Expiry Warning Email.
 *
 * Sends a warning email when a reader's saved credit card is about to expire
 * on an active WooCommerce Subscription. Runs a daily cron scan to find
 * expiring CC tokens and notifies the subscription owner.
 *
 * Publisher-respect — first-deploy seed:
 *
 *   On the very first scheduled scan after install (detected via the
 *   absence of `newspack_card_expiry_warning_seeded` option), this class
 *   runs a SEED pass instead of a normal scan: it iterates the same
 *   in-window (subscription, token) pairs the normal scan would have
 *   sent to, marks each as already-warned via a per-token SEEDED meta
 *   entry (see SEEDED_META_PREFIX), and writes the seeded option flag
 *   — all WITHOUT sending. On subsequent scheduled runs, the normal
 *   scan proceeds.
 *
 *   This protects publishers from a Day 0 mass-email burst. Sites
 *   that DO want to send the deferred warnings (publisher-initiated
 *   explicit action) can run:
 *
 *     wp newspack card-expiry-warning-backfill
 *
 *   The CLI command passes a $bypass_idempotency flag to
 *   maybe_send_warning() so the per-token SEEDED meta doesn't block
 *   the send. The SENT meta (SENT_META_PREFIX) still blocks even
 *   under bypass, so CLI re-runs on the same window are silently
 *   idempotent — see is_already_processed() for the gating logic.
 *   See Newspack\CLI\WooCommerce_Subscriptions for the command.
 *
 * Publisher-respect — per-pass send cap:
 *
 *   `scan_expiring_cards` caps ACTUAL SENDS per cron tick (filterable
 *   via `newspack_card_expiry_warning_limit_per_pass`, default 100) so
 *   a migration day or unusual burst doesn't dump thousands of emails
 *   into the publisher's mail provider in one go. The cap applies to
 *   real sends only — already-processed pairs (SEEDED or SENT) skip
 *   via the idempotency gate without counting toward it. A site
 *   exceeding the cap on a given day sees remaining warnings roll
 *   into subsequent cron runs.
 *
 *   Discovery memory is bounded by the window size (date-range ×
 *   subscription-density), not by a SQL LIMIT — the legacy SQL LIMIT
 *   caused starvation (deterministic ORDER BY token_id ASC made the
 *   same first-N tokens surface daily; once marked, the unprocessed
 *   remainder never got reached). See `scan_expiring_cards` for the
 *   send-cap implementation.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Card Expiry Warning class.
 */
class Card_Expiry_Warning {

	/**
	 * Email type identifier used in the Newspack email config system.
	 */
	const EMAIL_TYPE = 'card-expiry-warning';

	/**
	 * Cron hook name for the daily expiry scan.
	 */
	const CRON_HOOK = 'newspack_card_expiry_warning_scan';

	/**
	 * Per-token meta prefix for the seed pass marker.
	 *
	 * Written by `seed_in_window_pairs()` to record that a (subscription,
	 * token) pair was in-window at the first-deploy seed but was NOT sent
	 * — see the class docblock's "Publisher-respect — first-deploy seed"
	 * section. The CLI backfill bypasses the SEEDED gate (operator opt-in
	 * to release deferred warnings) but NOT the SENT gate (see
	 * SENT_META_PREFIX).
	 *
	 * Per-token suffix (not a single per-subscription key) so a
	 * subscription with multiple in-window CC tokens has independent
	 * suppression state per token. The earlier single-key shape collapsed
	 * all tokens onto one meta value and lost suppression for all but the
	 * last-iterated token — see NPPD-1568.
	 *
	 * Full meta key = `self::SEEDED_META_PREFIX . $token->get_id()`.
	 */
	const SEEDED_META_PREFIX = '_newspack_card_expiry_warning_seeded_';

	/**
	 * Per-token meta prefix for the actual-send marker.
	 *
	 * Written after a successful `Emails::send_email()` call. Always
	 * blocks re-sends — even when `$bypass_idempotency=true`. This makes
	 * the CLI backfill silently idempotent across operator re-runs on
	 * the same window (NPPD-1568): a second invocation hits the SENT
	 * gate for every pair the first invocation completed.
	 *
	 * Invariant: at any moment, a (subscription, token) pair has at most
	 * ONE of {SEEDED, SENT}, never both. On a CLI-driven release of a
	 * seeded pair, the SEEDED meta is deleted as SENT is written.
	 *
	 * Full meta key = `self::SENT_META_PREFIX . $token->get_id()`.
	 */
	const SENT_META_PREFIX = '_newspack_card_expiry_warning_sent_';

	/**
	 * How many times to attempt persisting the SENT marker after a
	 * successful send before giving up. The mail is already accepted at
	 * that point, so a bounded immediate retry narrows the window where
	 * the marker is missing (which would let a later pass re-send) for
	 * the common transient-failure case, without any new durable state.
	 */
	const SENT_MARKER_SAVE_ATTEMPTS = 3;

	/**
	 * Option flagging that the first-deploy seed pass has run.
	 *
	 * Stored with autoload=false so it doesn't sit in alloptions on
	 * every pageload.
	 */
	const SEEDED_OPTION = 'newspack_card_expiry_warning_seeded';

	/**
	 * Default per-pass cap on ACTUAL SENDS per cron tick.
	 *
	 * Filterable via `newspack_card_expiry_warning_limit_per_pass`.
	 * Applied in the foreach loop of `scan_expiring_cards()`, not at
	 * the SQL level — see the "Publisher-respect — per-pass send cap"
	 * section of the class docblock for the starvation-avoidance
	 * rationale.
	 */
	const LIMIT_PER_PASS_DEFAULT = 100;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Register the deactivation cleanup outside the is_enabled() guard
		// so a cron event that was scheduled on an earlier request (when
		// WC Subs + RA were both enabled) still gets cleared on plugin
		// deactivation, even if WCS or RA was disabled in between.
		add_action( 'newspack_deactivation', [ __CLASS__, 'unschedule_cron' ] );

		if ( ! WooCommerce_Subscriptions::is_enabled() ) {
			return;
		}

		add_filter( 'newspack_email_configs', [ __CLASS__, 'add_email_config' ] );
		add_action( 'init', [ __CLASS__, 'schedule_cron' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'scan_expiring_cards' ] );
		add_action( 'woocommerce_subscription_payment_method_updated', [ __CLASS__, 'clear_sent_flag' ] );
	}

	/**
	 * Unschedule the cron event on plugin deactivation.
	 */
	public static function unschedule_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Get the number of days before expiry to send the warning.
	 *
	 * @return int Days before card expiry.
	 */
	public static function get_days_before_expiry(): int {
		/**
		 * Filters the number of days before card expiry to send the warning email.
		 *
		 * @param int $days Default 14.
		 */
		return max( 1, (int) apply_filters( 'newspack_card_expiry_warning_days', 14 ) );
	}

	/**
	 * Get the per-pass cap on actual sends.
	 *
	 * Applied in `scan_expiring_cards()`'s foreach loop — counts only
	 * actual sends (already-processed pairs skip via the idempotency
	 * gate and don't consume the cap). Sites exceeding the cap on a
	 * given day see remaining warnings roll into subsequent cron runs.
	 *
	 * NOT applied at the SQL level — that shape was reverted in the
	 * Copilot review on #155 because ORDER BY token_id ASC + LIMIT N
	 * meant the same first-N token_ids surfaced each day, and once
	 * those N were marked the unprocessed remainder starved.
	 *
	 * @return int Max sends per pass.
	 */
	public static function get_limit_per_pass(): int {
		/**
		 * Filters the per-pass cap on ACTUAL SENDS per cron tick for
		 * the card-expiry warning scan. Applied in the foreach loop of
		 * `scan_expiring_cards()` (and in the WP-CLI backfill's loop),
		 * NOT at SQL discovery — already-processed pairs skip via the
		 * idempotency gate without consuming the cap.
		 *
		 * @param int $limit Default 100.
		 */
		return max( 1, (int) apply_filters( 'newspack_card_expiry_warning_limit_per_pass', self::LIMIT_PER_PASS_DEFAULT ) );
	}

	/**
	 * Register the email configuration.
	 *
	 * The four UI-metadata fields (`recommended`, `recipient`, `chip`,
	 * `trigger_description`) are declared explicitly. Slice 1's
	 * `Emails::apply_config_defaults()` would auto-fill defaults for
	 * `recipient` and derive `chip` from `category`, but explicit
	 * declaration keeps this provider's UI surface visible at the
	 * registration site rather than buried in inheritance.
	 *
	 * @param array $configs Existing email configs.
	 * @return array Modified email configs.
	 */
	public static function add_email_config( $configs ) {
		$configs[ self::EMAIL_TYPE ] = [
			'name'                   => self::EMAIL_TYPE,
			'category'               => 'reader-revenue',
			'chip'                   => 'reader-revenue',
			'recipient'              => 'reader',
			'recommended'            => true,
			'label'                  => __( 'Card expiry warning', 'newspack-plugin' ),
			'description'            => __( "Email sent when a reader's saved payment method is about to expire.", 'newspack-plugin' ),
			'trigger_description'    => __( "Sent when a reader's saved payment method is about to expire on an active subscription.", 'newspack-plugin' ),
			'template'               => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/card-expiry-warning.php',
			'editor_notice'          => __( 'This email will be sent to readers when their saved credit card is about to expire on an active subscription.', 'newspack-plugin' ),
			'from_email'             => Reader_Revenue_Emails::get_from_email(),
			'available_placeholders' => [
				[
					'label'    => __( 'the customer billing first name', 'newspack-plugin' ),
					'template' => '*BILLING_FIRST_NAME*',
				],
				[
					'label'    => __( 'the last four digits of the expiring card', 'newspack-plugin' ),
					'template' => '*CARD_LAST_4*',
				],
				[
					'label'    => __( 'the card expiry date (MM/YYYY)', 'newspack-plugin' ),
					'template' => '*EXPIRY_DATE*',
				],
				[
					'label'    => __( 'the next renewal date', 'newspack-plugin' ),
					'template' => '*RENEWAL_DATE*',
				],
				[
					'label'    => __( 'link to update payment method', 'newspack-plugin' ),
					'template' => '*UPDATE_PAYMENT_URL*',
				],
				[
					'label'    => __(
						'the contact email to your site (same as the "From" email address)',
						'newspack-plugin'
					),
					'template' => '*CONTACT_EMAIL*',
				],
				[
					'label'    => __( 'the site title', 'newspack-plugin' ),
					'template' => '*SITE_TITLE*',
				],
				[
					'label'    => __( 'the site url', 'newspack-plugin' ),
					'template' => '*SITE_URL*',
				],
			],
		];
		return $configs;
	}

	/**
	 * Schedule the daily cron event if not already scheduled.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Defer the first run by 24h so publishers get an opt-in
			// window after install to review the email template / flip
			// the email post to draft before the seed pass writes meta.
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Scan for expiring credit cards and send warning emails.
	 *
	 * On the first scheduled run after install (SEEDED_OPTION absent),
	 * runs a seed pass instead — see the seed_in_window_pairs() docblock
	 * and the class-level docblock for the publisher-respect rationale.
	 *
	 * The per-pass cap from `get_limit_per_pass()` applies to ACTUAL
	 * SENDS, not to discovery. Discovery returns all in-window pairs
	 * (memory bounded by `newspack_card_expiry_warning_days`); the
	 * foreach below breaks once `$sent` reaches the cap. Applying the
	 * cap at the SQL-discovery level (the legacy shape) would have
	 * caused starvation: ORDER BY token_id ASC made the same first N
	 * token_ids surface each day, so once those N were all marked
	 * SEEDED or SENT, every subsequent scan would no-op and never reach
	 * the unprocessed remainder. Caught in Copilot review on #155.
	 */
	public static function scan_expiring_cards() {
		if ( ! Emails::can_send_email( self::EMAIL_TYPE ) ) {
			return;
		}

		if ( ! get_option( self::SEEDED_OPTION ) ) {
			self::seed_in_window_pairs();
			return;
		}

		// Discovery uses PHP_INT_MAX (effectively no SQL LIMIT) so every
		// in-window pair surfaces — already-processed pairs skip via the
		// idempotency gate in maybe_send_warning, and only actual sends
		// count toward the per-pass cap below.
		$pairs = self::get_in_window_pairs(
			self::get_days_before_expiry(),
			PHP_INT_MAX
		);
		$cap   = self::get_limit_per_pass();
		$sent  = 0;
		foreach ( $pairs as $pair ) {
			if ( $sent >= $cap ) {
				break;
			}
			// Per-pair try/catch so a single throwing pair (e.g. an SMTP
			// filter rejecting a malformed address, a third-party WC hook
			// that throws on save) doesn't abort the rest of the pass and
			// skip every later pair until tomorrow's cron.
			try {
				if ( self::maybe_send_warning( $pair['subscription'], $pair['token'] ) ) {
					++$sent;
				}
			} catch ( \Throwable $e ) {
				Logger::log(
					sprintf(
						'Card expiry warning send failed for subscription %d: %s',
						$pair['subscription']->get_id(),
						$e->getMessage()
					),
					'NEWSPACK-CARD-EXPIRY',
					'error'
				);
				continue;
			}
		}
	}

	/**
	 * First-deploy seed pass.
	 *
	 * Iterates every currently-in-window (subscription, token) pair the
	 * normal scan would have sent to, marks each as already-warned via
	 * a per-token SEEDED meta entry, and writes the SEEDED_OPTION flag —
	 * WITHOUT sending anything. Logs the result via Newspack\Logger.
	 *
	 * Sites that DO want to send the deferred warnings should run the
	 * WP-CLI backfill (see class docblock).
	 */
	private static function seed_in_window_pairs() {
		// Use PHP_INT_MAX (effectively no SQL LIMIT) so the seed marks
		// EVERY currently-in-window pair, not just the per-pass cap. The
		// per-pass cap exists to bound steady-state cron memory; the seed
		// runs once per install and MUST cover the full window or the
		// un-seeded remainder leaks into the next cron's normal-scan
		// branch — exactly the Day-0 burst the seed is built to prevent.
		$pairs = self::get_in_window_pairs(
			self::get_days_before_expiry(),
			PHP_INT_MAX
		);
		$count    = 0;
		$failures = 0;
		foreach ( $pairs as $pair ) {
			$token      = $pair['token'];
			$token_id   = $token->get_id();
			$expiry_key = $token_id . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();
			// Per-pair try/catch mirrors scan_expiring_cards: a single
			// throwing save (a third-party WC hook, a transient DB error)
			// must not abort the seed and leave SEEDED_OPTION unwritten —
			// that would re-enter seed mode on the next cron and keep
			// skipping normal sends indefinitely. A pair that fails to seed
			// here simply isn't suppressed, so the next normal scan treats
			// it as unprocessed and sends ONE warning (bounded, acceptable)
			// rather than the whole site re-seeding forever.
			try {
				// Per-token meta key (NPPD-1568): a subscription with multiple
				// in-window CC tokens gets independent suppression state per
				// token. The earlier single-key shape collapsed all tokens
				// onto one value and lost suppression for all but the last
				// iterated token.
				$pair['subscription']->update_meta_data( self::SEEDED_META_PREFIX . $token_id, $expiry_key );
				$pair['subscription']->save();
				++$count;
			} catch ( \Throwable $e ) {
				++$failures;
				Logger::log(
					sprintf(
						'Card expiry warning seed failed for subscription %d (token %d): %s',
						$pair['subscription']->get_id(),
						$token_id,
						$e->getMessage()
					),
					'NEWSPACK-CARD-EXPIRY',
					'error'
				);
				continue;
			}
		}
		// autoload=false so this option doesn't sit in alloptions on every
		// pageload. Written unconditionally even if some pairs failed: the
		// seed is "best effort, once" — re-running it (by leaving the flag
		// unset) would re-enter seed mode and starve normal sends, the very
		// bug this guards against.
		update_option( self::SEEDED_OPTION, '1', false );

		$message = sprintf(
			'Card expiry warning first-deploy seed: marked %d (subscription, token) pair(s) as already-warned without sending%s. Run `wp newspack card-expiry-warning-backfill` to send the deferred warnings.',
			$count,
			$failures > 0 ? sprintf( ' (%d pair(s) failed to seed and may receive one normal warning)', $failures ) : ''
		);

		// Logger::log is gated by NEWSPACK_LOG_LEVEL (off on most
		// production sites). Also fire newspack_log so the event is
		// visible to Newspack Manager and any other listeners on the
		// action — the seed is a significant one-time event and must
		// be diagnostically discoverable.
		Logger::log( $message, 'NEWSPACK-CARD-EXPIRY', $failures > 0 ? 'error' : 'warning' );
		Logger::newspack_log(
			'card_expiry_warning_seeded',
			$message,
			[
				'pair_count'   => $count,
				'failed_count' => $failures,
			],
			$failures > 0 ? 'error' : 'warning'
		);
	}

	/**
	 * Discover (subscription, token) pairs currently in the warning window.
	 *
	 * Returns at most `$limit` pairs (cap applied at the SQL level via
	 * get_expiring_cc_tokens). A site exceeding `$limit` will see
	 * remaining warnings roll into subsequent cron runs — sustained-load
	 * is fine; this only affects bursts and migrations.
	 *
	 * Public because the WP-CLI backfill needs to iterate the same set
	 * without duplicating the discovery logic.
	 *
	 * @param int $days  Window in days.
	 * @param int $limit Max tokens to consider.
	 * @return array<int, array{subscription: \WC_Subscription, token: \WC_Payment_Token_CC}>
	 */
	public static function get_in_window_pairs( int $days, int $limit ): array {
		if ( ! class_exists( 'WCS_Payment_Tokens' ) ) {
			return [];
		}
		$tokens = self::get_expiring_cc_tokens( $days, $limit );
		if ( empty( $tokens ) ) {
			return [];
		}
		$pairs = [];
		foreach ( $tokens as $token ) {
			$subscription_ids = \WCS_Payment_Tokens::get_subscriptions_from_token( $token );
			foreach ( $subscription_ids as $subscription_id ) {
				$subscription = WooCommerce_Subscriptions::sanitize_subscription( $subscription_id );
				if ( ! $subscription || 'active' !== $subscription->get_status() ) {
					continue;
				}
				$pairs[] = [
					'subscription' => $subscription,
					'token'        => $token,
				];
			}
		}
		return $pairs;
	}

	/**
	 * Find CC tokens expiring within the given number of days, capped at $limit.
	 *
	 * Direct DB query on woocommerce_payment_tokenmeta joined to
	 * woocommerce_payment_tokens to filter at the DB level. The
	 * `LIMIT %d` is applied at the SQL level so we don't pull
	 * unbounded rows into PHP memory on a migration or burst day.
	 *
	 * @param int $days  Number of days in the warning window.
	 * @param int $limit Max number of token rows to return.
	 * @return \WC_Payment_Token_CC[] Array of expiring CC token objects (length <= $limit).
	 */
	private static function get_expiring_cc_tokens( int $days, int $limit ): array {
		global $wpdb;

		$today  = gmdate( 'Y-m-d' );
		$cutoff = gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );

		// A card with expiry MM/YYYY is valid through the last day of that month.
		// Find tokens whose last-valid-day falls between today and $cutoff.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// expiry_year normalization: WC_Payment_Token_CC::set_expiry_year
		// does not zero-pad (unlike set_expiry_month), so a gateway that
		// stores a 2-digit year ('26') would produce a year-0026 date via
		// STR_TO_DATE and silently fall outside the BETWEEN window — those
		// readers would never be warned. Normalize 2-digit years to 20YY in
		// the CASE below instead of excluding them, and REGEXP-guard the
		// column so non-numeric / wrong-length garbage is still skipped
		// rather than fed to STR_TO_DATE.
		// ORDER BY token_id ASC for deterministic ordering across cron
		// runs (without it, MySQL is free to return any LIMIT-sized
		// subset and seeding/normal-scan handoffs become unstable on
		// sites that exceed the per-pass cap).
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.token_id
				FROM {$wpdb->prefix}woocommerce_payment_tokens t
				INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta em
					ON em.payment_token_id = t.token_id AND em.meta_key = 'expiry_month'
				INNER JOIN {$wpdb->prefix}woocommerce_payment_tokenmeta ey
					ON ey.payment_token_id = t.token_id AND ey.meta_key = 'expiry_year'
				WHERE t.type = 'CC'
					AND ey.meta_value REGEXP '^[0-9]{2}([0-9]{2})?$'
					AND LAST_DAY(
						STR_TO_DATE(
							CONCAT(
								CASE WHEN CHAR_LENGTH( ey.meta_value ) = 2
									THEN CONCAT( '20', ey.meta_value )
									ELSE ey.meta_value
								END,
								'-', em.meta_value, '-01'
							),
							'%%Y-%%m-%%d'
						)
					) BETWEEN %s AND %s
				ORDER BY t.token_id ASC
				LIMIT %d",
				$today,
				$cutoff,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $token_ids ) ) {
			return [];
		}

		$tokens = [];
		foreach ( $token_ids as $token_id ) {
			$token = \WC_Payment_Tokens::get( (int) $token_id );
			if ( $token instanceof \WC_Payment_Token_CC ) {
				$tokens[] = $token;
			}
		}
		return $tokens;
	}

	/**
	 * Whether this (subscription, token, expiry) tuple has already been
	 * processed and should be skipped.
	 *
	 * Two-prefix schema (see SEEDED_META_PREFIX + SENT_META_PREFIX):
	 *
	 *   - SENT_META always blocks. Even with `$bypass_idempotency=true`,
	 *     a real prior send is never re-sent. This is what makes the CLI
	 *     backfill silently idempotent across operator re-runs.
	 *   - SEEDED_META blocks unless `$bypass_idempotency=true`. The seed
	 *     pass writes it without sending; the CLI bypass is the explicit
	 *     publisher opt-in to release the deferred warning.
	 *
	 * Value-match (`=== $expiry_key`) is intentional, not just key-
	 * existence. A token's `expiry_month`/`expiry_year` meta can change
	 * in place — e.g., a Stripe Card Account Updater webhook reissues
	 * the same `token_id` with a new expiry — and the value-match
	 * invalidates the stale mark so the next expiry cycle gets warned.
	 * Replacing this with `metadata_exists()` would silently block the
	 * new warning. Do NOT simplify to existence-only.
	 *
	 * @internal Public so the WP-CLI backfill's --dry-run path can
	 *           preview accurately (skip pairs that wouldn't actually
	 *           send). Not part of the stable public API — external
	 *           callers should not depend on this signature.
	 *
	 * @param \WC_Subscription $subscription       The subscription.
	 * @param int              $token_id           The CC token id.
	 * @param string           $expiry_key         `token_id:MM/YYYY`.
	 * @param bool             $bypass_idempotency When true, ignore the SEEDED gate (SENT still blocks).
	 * @return bool True if already processed (skip), false if proceed.
	 */
	public static function is_already_processed( $subscription, int $token_id, string $expiry_key, bool $bypass_idempotency = false ): bool {
		if ( $subscription->get_meta( self::SENT_META_PREFIX . $token_id, true ) === $expiry_key ) {
			return true;
		}
		if ( ! $bypass_idempotency && $subscription->get_meta( self::SEEDED_META_PREFIX . $token_id, true ) === $expiry_key ) {
			return true;
		}
		return false;
	}

	/**
	 * Send the expiry warning for a subscription.
	 *
	 * Idempotency: gated by `is_already_processed()`, which checks
	 * per-token SEEDED + SENT meta with value-match against the current
	 * expiry tuple. See that method's docblock for the schema rationale.
	 *
	 * The WP-CLI backfill passes `$bypass_idempotency=true` to release
	 * seed-suppressed warnings — but SENT still blocks, so a re-run of
	 * the CLI on the same window is a no-op against the prior send.
	 *
	 * @internal Public only so the WP-CLI backfill in
	 *           `Newspack\CLI\WooCommerce_Subscriptions::card_expiry_warning_backfill()`
	 *           can pass the bypass flag. Not part of the stable public
	 *           API — external callers should not depend on this
	 *           signature.
	 *
	 * @param \WC_Subscription     $subscription       The subscription.
	 * @param \WC_Payment_Token_CC $token              The expiring CC token.
	 * @param bool                 $bypass_idempotency When true, skip the
	 *                                                 SEEDED gate. The SENT
	 *                                                 gate still blocks.
	 * @return bool Whether the email was sent.
	 */
	public static function maybe_send_warning( $subscription, $token, bool $bypass_idempotency = false ): bool {
		$token_id   = $token->get_id();
		$expiry_key = $token_id . ':' . $token->get_expiry_month() . '/' . $token->get_expiry_year();

		if ( self::is_already_processed( $subscription, $token_id, $expiry_key, $bypass_idempotency ) ) {
			return false;
		}

		$customer = $subscription->get_user();
		if ( ! $customer ) {
			return false;
		}

		$update_url   = \wc_get_account_endpoint_url( 'payment-methods' );
		$next_payment = $subscription->get_date( 'next_payment' );
		// Use wp_date() — not date_i18n() — so the GMT timestamp from
		// WC_Subscription::get_time() is correctly converted into the
		// site's timezone for display. date_i18n() carries a legacy
		// quirk that misinterprets GMT timestamps for sites whose
		// timezone straddles the UTC date boundary.
		$renewal_date = $next_payment
			? wp_date( get_option( 'date_format', 'F j, Y' ), $subscription->get_time( 'next_payment' ) )
			: __( 'your next renewal', 'newspack-plugin' );

		$first_name = $subscription->get_billing_first_name();
		if ( '' === $first_name ) {
			$first_name = $customer->first_name;
		}

		$placeholders = [
			[
				'template' => '*BILLING_FIRST_NAME*',
				'value'    => esc_html( $first_name ),
			],
			[
				'template' => '*CARD_LAST_4*',
				'value'    => esc_html( $token->get_last4() ),
			],
			[
				'template' => '*EXPIRY_DATE*',
				'value'    => esc_html( $token->get_expiry_month() . '/' . $token->get_expiry_year() ),
			],
			[
				'template' => '*RENEWAL_DATE*',
				'value'    => esc_html( $renewal_date ),
			],
			[
				'template' => '*UPDATE_PAYMENT_URL*',
				'value'    => esc_url( $update_url ),
			],
		];

		// The earlier guard only confirms a user exists, not that the
		// subscription carries a billing email. Fall back to the account
		// email when billing is empty/invalid; if neither is usable, skip
		// this pair with a log entry rather than firing a send that fails
		// and leaves the pair to retry forever.
		$recipient = $subscription->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			$recipient = $customer->user_email;
		}
		if ( ! is_email( $recipient ) ) {
			Logger::log(
				sprintf(
					'Card expiry warning skipped for subscription %d: no valid billing or account email.',
					$subscription->get_id()
				),
				'NEWSPACK-CARD-EXPIRY',
				'warning'
			);
			return false;
		}

		$sent = Emails::send_email(
			self::EMAIL_TYPE,
			$recipient,
			$placeholders
		);

		if ( $sent ) {
			// Promote SEEDED → SENT: delete the seed mark first so the
			// invariant holds (at most one of {SEEDED, SENT} per token).
			// `delete_meta_data` is a no-op when the key is absent, so
			// this is safe whether the pair was previously seeded or not.
			$subscription->delete_meta_data( self::SEEDED_META_PREFIX . $token_id );
			$subscription->update_meta_data( self::SENT_META_PREFIX . $token_id, $expiry_key );

			// Persist the SENT marker with a bounded immediate retry. The
			// mail is already accepted by Emails::send_email() at this
			// point. We deliberately do NOT mark-before-send: for a
			// card-expiry warning a rare over-send is far less harmful than
			// a silent miss (no warning → failed renewal → involuntary
			// churn), so over-send is the correct failure direction. Most
			// save() failures are transient (a momentary lock or DB blip),
			// so retrying shrinks the window where the marker is missing —
			// which would otherwise let a later pass re-send — to almost
			// nothing, with no new durable state. The full two-phase fix
			// (pending-claim → send → confirm, with stale claims surfaced
			// for reconciliation) is left as a follow-up.
			$save = self::save_subscription_with_retry( $subscription );
			if ( ! $save['saved'] ) {
				// Still report success so the caller counts this against the
				// per-pass cap — otherwise a save failure would let the cap
				// be bypassed AND re-attempt the same address. The marker
				// not landing means a later pass could re-send; logged at
				// error level so the rare case is diagnosable.
				Logger::log(
					sprintf(
						'Card expiry warning sent for subscription %d but persisting the SENT marker failed after %d attempts: %s. The warning may re-send on a later pass.',
						$subscription->get_id(),
						self::SENT_MARKER_SAVE_ATTEMPTS,
						$save['last_error']
					),
					'NEWSPACK-CARD-EXPIRY',
					'error'
				);
			}
		}
		return (bool) $sent;
	}

	/**
	 * Persist a subscription with a bounded immediate retry, swallowing
	 * throwables. Used after a successful send to land the SENT marker:
	 * the mail is already accepted, so a transient save() failure (a
	 * momentary lock, a DB blip) is worth retrying immediately to narrow
	 * the window where the marker is missing — which would otherwise let
	 * a later pass re-send. Bounded by SENT_MARKER_SAVE_ATTEMPTS; never
	 * throws.
	 *
	 * @param \WC_Subscription|object $subscription Subscription to save.
	 * @return array{saved: bool, last_error: string} Whether the save
	 *               landed, and the last error message if it didn't.
	 */
	private static function save_subscription_with_retry( $subscription ): array {
		$last_error = '';
		for ( $attempt = 1; $attempt <= self::SENT_MARKER_SAVE_ATTEMPTS; $attempt++ ) {
			try {
				$subscription->save();
				return [
					'saved'      => true,
					'last_error' => '',
				];
			} catch ( \Throwable $e ) {
				$last_error = $e->getMessage();
			}
		}
		return [
			'saved'      => false,
			'last_error' => $last_error,
		];
	}

	/**
	 * Clear the sent flag when the payment method is updated on a subscription.
	 *
	 * Hooked to 'woocommerce_subscription_payment_method_updated'. Clears
	 * BOTH SEEDED and SENT per-token entries on the subscription via
	 * `get_meta_data()` iteration — WC CRUD pattern, composes correctly
	 * with WC's meta cache and the `woocommerce_after_save_subscription_meta`
	 * hook chain. (LIKE-query on `wp_postmeta` would be faster on huge
	 * meta tables but skirts WC's CRUD layer.)
	 *
	 * `$changed` guard prevents firing `save()` (and the hooks it
	 * triggers) when no per-token meta matched.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 */
	public static function clear_sent_flag( $subscription ) {
		$changed = false;
		foreach ( $subscription->get_meta_data() as $meta ) {
			$key = $meta->key;
			if ( 0 === strpos( $key, self::SEEDED_META_PREFIX ) || 0 === strpos( $key, self::SENT_META_PREFIX ) ) {
				$subscription->delete_meta_data( $key );
				$changed = true;
			}
		}
		if ( $changed ) {
			$subscription->save();
		}
	}
}
