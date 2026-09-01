<?php
/**
 * Read-only audit of how WooCommerce Memberships memberships are backed, for the
 * Access Control migration.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-flip audit commands over WooCommerce Memberships data. Read-only: these
 * commands never write, so they are safe to run on a live site.
 */
class Memberships_Audit {

	/**
	 * Fallback list of membership statuses that grant content access under
	 * WooCommerce Memberships — used only when the WCM API is unavailable.
	 *
	 * `wcm-pending` is WCM's "Pending Cancellation": still access-granting, and
	 * the membership-side analogue of the `pending-cancel` subscription status.
	 * Prefer get_active_membership_statuses(), which reads the list from WCM
	 * itself so a site filtering `wc_memberships_active_access_membership_statuses`
	 * stays in step.
	 *
	 * @var string[]
	 */
	const ACTIVE_MEMBERSHIP_STATUSES = [ 'wcm-active', 'wcm-complimentary', 'wcm-free_trial', 'wcm-pending' ];

	/**
	 * Posts fetched per query when walking memberships or plans.
	 *
	 * The command runs against production sites whose paid base can reach tens
	 * of thousands of memberships, so the walk is paged rather than fetched in
	 * one unbounded query.
	 *
	 * @var int
	 */
	const QUERY_BATCH_SIZE = 500;

	/**
	 * Query var marking a WP_Query as the batch walk's own, so the keyset-paging
	 * `posts_where` filter constrains that query and nothing else.
	 *
	 * @var string
	 */
	const WALK_QUERY_FLAG = 'newspack_memberships_audit_walk';

	/**
	 * Seconds to pause between batches unless --sleep says otherwise.
	 *
	 * The audit reads the whole membership table on a production site, several
	 * queries per row, as fast as the database will answer. Nothing about the audit
	 * needs that speed — it is an operator running a report, not a request serving a
	 * reader — so the default trades a couple of minutes on a large site for leaving
	 * headroom to the readers using it at the same time. Pass --sleep=0 to opt out.
	 *
	 * @var float
	 */
	const DEFAULT_SLEEP_SECONDS = 2;

	/**
	 * Subscription statuses that grant content access under Access Control.
	 *
	 * Mirrors `WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES`. Held as a
	 * local constant so classify() stays pure and testable; a unit test asserts
	 * the two lists are identical, so drift fails the build rather than a
	 * migration.
	 *
	 * This list is NOT the whole of what grants access at runtime.
	 * `Access_Rules::has_active_subscription()` evaluates it PLUS the
	 * payment-recovery grace, which every caller that builds an evaluation context
	 * defaults to ON — so an `on-hold` subscription carrying a `payment_retry`
	 * date does grant access after the flip. That cannot be expressed by widening
	 * this list, because the distinction is the retry date rather than the status;
	 * it is carried as the `subscription_in_payment_recovery` fact and folded in by
	 * facts_grant_access(), which is what classify() actually asks.
	 *
	 * @var string[]
	 */
	const ACCESS_GRANTING_SUBSCRIPTION_STATUSES = [ 'active', 'pending-cancel' ];

	/**
	 * A live subscription owned by someone other than the membership holder: a
	 * gift purchase. WooCommerce Memberships grants the holder; Access Control
	 * grants the subscription's customer, so at the flip the recipient loses
	 * access and the buyer gains access they never had.
	 */
	const CLASS_GIFT = 'gift';

	/**
	 * Holder ≠ subscription customer, but the subscription grants access to
	 * nobody post-flip (cancelled, expired, or in payment retry). No
	 * buyer-vs-recipient question to put to the publisher — these belong to the
	 * lapsed/dunning cohorts the parity diff already names.
	 */
	const CLASS_GIFT_INACTIVE = 'gift-inactive';

	/**
	 * A gift booked through WooCommerce Subscriptions Gifting, where the
	 * recipient IS the membership holder. Access Control resolves gifted
	 * subscriptions to their recipient, so these readers keep access at the flip
	 * — granting them anything would double up.
	 */
	const CLASS_GIFT_WCSG = 'gift-wcsg';

	/**
	 * Backed only by a paid one-off order — no subscription at all, so there is
	 * nothing recurring for an Access Control `subscription` rule to key on.
	 */
	const CLASS_ORDER_ONLY = 'order-only';

	/**
	 * Backed only by an order that was never paid (or was refunded, cancelled,
	 * failed). Reported separately because "grant this reader a free
	 * subscription" is the wrong answer for a refunded purchase.
	 */
	const CLASS_ORDER_ONLY_UNPAID = 'order-only-unpaid';

	/**
	 * A seat on a Memberships-for-Teams team. The Teams integration stamps the
	 * *team's* subscription (owned by the team owner) onto every seat's
	 * membership, which would otherwise read as a gift for the whole
	 * organisation. `migrate-teams` handles these; the audit only counts them.
	 */
	const CLASS_TEAM_BACKED = 'team-backed';

	/**
	 * The member owns an access-granting subscription themselves: access carries
	 * over, provided the subscription's product is one the gates accept (which
	 * the access-parity diff, not this command, decides).
	 */
	const CLASS_MEMBER_OWNED = 'member-owned';

	/**
	 * The member owns the linked subscription, but it grants no access post-flip
	 * (cancelled, expired, or in payment retry) — an active membership left
	 * standing over a subscription Access Control will not honour.
	 */
	const CLASS_MEMBER_OWNED_INACTIVE = 'member-owned-inactive';

	/**
	 * `_subscription_id` points at a subscription that cannot be loaded, and no
	 * usable order stands behind it either. The membership looks backed in the
	 * admin but has nothing behind it.
	 */
	const CLASS_SUBSCRIPTION_MISSING = 'subscription-missing';

	/**
	 * No subscription and no loadable order: the comp/legacy cohort the parity
	 * diff already names.
	 */
	const CLASS_NO_PURCHASE_RECORD = 'no-purchase-record';

	/**
	 * The classes reported member-by-member by default. These are the shapes
	 * that are invisible to both the plan→gate translation and the
	 * access-parity diff, and that need per-member evidence to resolve.
	 *
	 * @var string[]
	 */
	const REPORTED_CLASSES = [ self::CLASS_GIFT, self::CLASS_ORDER_ONLY ];

	/**
	 * Classes `--only` accepts. A superset of the default: the extra ones are
	 * real losses too, but common enough (or ambiguous enough) that putting them
	 * in the default report would bury the gift cohort.
	 *
	 * @var string[]
	 */
	const SELECTABLE_CLASSES = [
		self::CLASS_GIFT,
		self::CLASS_ORDER_ONLY,
		self::CLASS_ORDER_ONLY_UNPAID,
		self::CLASS_SUBSCRIPTION_MISSING,
	];

	/**
	 * Every class, in report order.
	 *
	 * @var string[]
	 */
	const ALL_CLASSES = [
		self::CLASS_MEMBER_OWNED,
		self::CLASS_GIFT,
		self::CLASS_ORDER_ONLY,
		self::CLASS_ORDER_ONLY_UNPAID,
		self::CLASS_SUBSCRIPTION_MISSING,
		self::CLASS_GIFT_WCSG,
		self::CLASS_GIFT_INACTIVE,
		self::CLASS_MEMBER_OWNED_INACTIVE,
		self::CLASS_TEAM_BACKED,
		self::CLASS_NO_PURCHASE_RECORD,
	];

	/**
	 * Human-readable gloss per class, for the per-plan summary.
	 *
	 * @var array<string,string>
	 */
	const CLASS_LABELS = [
		self::CLASS_MEMBER_OWNED          => 'member owns the access-granting subscription',
		self::CLASS_GIFT                  => 'gift — access-granting subscription owned by someone else',
		self::CLASS_ORDER_ONLY            => 'backed by a paid one-off order, no subscription',
		self::CLASS_ORDER_ONLY_UNPAID     => 'backed by an unpaid/refunded order, no subscription',
		self::CLASS_SUBSCRIPTION_MISSING  => 'linked subscription no longer exists',
		self::CLASS_GIFT_WCSG             => 'gifted via Subscriptions Gifting — recipient keeps access',
		self::CLASS_GIFT_INACTIVE         => 'subscription owned by someone else, grants no access (lapsed/on-hold)',
		self::CLASS_MEMBER_OWNED_INACTIVE => 'member owns the subscription, grants no access (lapsed/on-hold)',
		self::CLASS_TEAM_BACKED           => 'team seat — handled by migrate-teams',
		self::CLASS_NO_PURCHASE_RECORD    => 'no purchase record (comp/legacy)',
	];

	/**
	 * Audit how active memberships are backed, and flag the ones whose access
	 * Access Control cannot reproduce.
	 *
	 * WooCommerce Memberships grants content access to the membership holder
	 * (the `wc_user_membership` post author). Access Control's `subscription`
	 * access rule grants it to the subscription's *customer*. Two shapes make
	 * those disagree, and neither is visible in the plan→gate translation or in
	 * the access-parity diff (where they hide inside the comp/legacy bucket and
	 * get written off as accepted loss):
	 *
	 * - **gift**: the membership sits on the recipient's account while the
	 *   subscription stays with the buyer. At the flip the reader loses access
	 *   and the buyer gains access they never had.
	 * - **order-only**: the membership is backed by a paid one-off order with no
	 *   subscription behind it.
	 *
	 * Every other membership is counted under a named class, so the per-plan
	 * summary reconciles against the plan's member count rather than leaving a
	 * remainder to guess at.
	 *
	 * This command only reads. Buyer-vs-recipient intent is a publisher
	 * question, so it reports pairs as evidence and decides nothing: no
	 * subscription is re-homed and no access is granted. `--format=ids` emits
	 * the signed-off list for `migrate-manual-members --user-ids-file`, which
	 * grants the replacement access.
	 *
	 * ## OPTIONS
	 *
	 * [--plan-ids=<ids>]
	 * : Comma-delimited membership plan IDs to audit — the plans whose content the new gates cover. Defaults to every published plan (a plan in any other status is audited only when named here).
	 *
	 * [--only=<classes>]
	 * : Comma-delimited classes to report member-by-member: gift, order-only, order-only-unpaid, subscription-missing. Omit the flag for the default gift + order-only report; passing it with no class is an error. Counts for every class are printed regardless — on STDERR in the machine-readable formats.
	 *
	 * [--sleep=<seconds>]
	 * : Seconds to pause between batches of memberships, to keep a full-table walk from monopolising the database on a live site. Pass 0 to run flat out.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. `ids` prints only the flagged user IDs, one per line, deduplicated per user. csv, json and ids keep STDOUT to the data alone and send the class counts and any warnings to STDERR, so the stream stays parseable when redirected to a file.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack audit-membership-subscriptions
	 *     wp newspack audit-membership-subscriptions --plan-ids=78,91
	 *     wp newspack audit-membership-subscriptions --sleep=0
	 *     wp newspack audit-membership-subscriptions --only=gift --format=csv
	 *     wp newspack audit-membership-subscriptions --only=gift --format=ids
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function audit_membership_subscriptions( $args, $assoc_args ) {
		// Hard error, unlike the Subscriptions check below. Without WooCommerce
		// Memberships the `wcm-*` statuses are not registered post statuses, and
		// WP_Query builds its status clause by iterating get_post_stati() — unknown
		// statuses are dropped, leaving NO status filter at all. Cancelled and expired
		// memberships would then be audited as if they granted access, and long-lapsed
		// readers would land on a list whose stated purpose is granting $0
		// subscriptions. An operator re-running the audit to confirm a flip, after
		// Memberships has been turned off, is a realistic way into exactly that.
		if ( ! function_exists( 'wc_memberships' ) || ! \post_type_exists( 'wc_user_membership' ) ) {
			WP_CLI::error( 'WooCommerce Memberships is not active, so membership statuses cannot be resolved and every membership would be audited as if it granted access. Aborting.' );
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			// Not fatal: a site without Subscriptions has no subscription links to
			// resolve, and its memberships are exactly the order-only / comp cohorts
			// this command still classifies correctly. Memberships that DO carry a
			// `_subscription_id` are a different matter, so the warning names where
			// they end up rather than leaving the operator to infer it.
			WP_CLI::warning( 'WooCommerce Subscriptions is not active — no subscription can be loaded, so a membership carrying a subscription link is classified from its order alone, or as subscription-missing when there is no usable order. Those rows say nothing about whether the subscription still exists.' );
		}

		// Validated rather than absint()-ed: a mistyped --sleep=2s would otherwise
		// silently become 2, and --sleep=-1 would become 0, so an operator asking for
		// a gentler run could get a faster one without being told.
		$sleep_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'sleep', self::DEFAULT_SLEEP_SECONDS );
		if ( ! is_numeric( $sleep_arg ) || (float) $sleep_arg < 0 ) {
			WP_CLI::error( sprintf( 'Invalid --sleep value "%s". Pass a non-negative number of seconds, or 0 to run without pausing.', $sleep_arg ) );
		}
		$sleep_seconds = (float) $sleep_arg;

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		// csv/json/ids are consumed by redirecting STDOUT to a file, and any prose
		// in that file makes it unparseable — so in those formats the narration
		// moves to STDERR rather than being dropped. The progress bar is the one
		// exception: it is redrawn in place and belongs to an interactive run.
		$quiet = 'table' !== $format;

		// Defaulted to null, not '': the difference between "the flag was not passed"
		// and "the flag was passed and names nothing" is the whole point of the check
		// inside, and '' cannot carry it.
		$only_classes = self::parse_only_classes( \WP_CLI\Utils\get_flag_value( $assoc_args, 'only', null ) );
		if ( \is_wp_error( $only_classes ) ) {
			WP_CLI::error( $only_classes->get_error_message() );
		}

		$plan_ids = self::resolve_plan_ids( \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan-ids', null ) );
		if ( \is_wp_error( $plan_ids ) ) {
			WP_CLI::error( $plan_ids->get_error_message() );
		}
		if ( empty( $plan_ids ) ) {
			WP_CLI::error( 'No published membership plans found. Pass --plan-ids to audit specific plans.' );
		}

		$membership_statuses = self::get_active_membership_statuses();

		$rows          = [];
		$total_counts  = array_fill_keys( self::ALL_CLASSES, 0 );
		$audited_plans = 0;

		foreach ( $plan_ids as $plan_id ) {
			$plan = \get_post( $plan_id );
			if ( ! $plan || 'wc_membership_plan' !== $plan->post_type ) {
				WP_CLI::warning( sprintf( 'Plan %d is not a membership plan — skipping.', $plan_id ) );
				continue;
			}
			++$audited_plans;

			$plan_counts = array_fill_keys( self::ALL_CLASSES, 0 );
			$progress    = null;

			$membership_count = self::each_post_id_batch(
				[
					'post_type'   => 'wc_user_membership',
					'post_status' => $membership_statuses,
					'post_parent' => $plan_id,
				],
				function ( $membership_ids, $total ) use ( &$plan_counts, &$total_counts, &$rows, &$progress, $plan, $plan_id, $only_classes, $format, $quiet ) {
					// Long runs over SSH look like a hang without this. It writes to
					// STDOUT, so it must not run for the machine-readable formats, and
					// it needs the total, so it is built on the first batch.
					if ( ! $quiet && ! $progress ) {
						$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Plan %d: %d membership(s)', $plan_id, $total ), $total );
					}

					// Once per batch, not once per membership. Flushing per row forecloses
					// any batch priming, so every read_membership_facts() call then pays a
					// fresh get_post() plus a meta lookup — two queries per membership
					// before WooCommerce loads anything. Priming here collapses that to two
					// queries per batch. Memory stays bounded either way: a batch is
					// QUERY_BATCH_SIZE posts, and the rows collected below are plain arrays
					// that survive the flush.
					\WP_CLI\Utils\wp_clear_object_cache();
					_prime_post_caches( $membership_ids, false, true );

					foreach ( $membership_ids as $membership_id ) {
						$facts = self::read_membership_facts( $membership_id );
						$class = self::classify( $facts );

						++$plan_counts[ $class ];
						++$total_counts[ $class ];

						if ( in_array( $class, $only_classes, true ) ) {
							// The ids format keeps only the member, so skip the evidence
							// lookups (user lookups + the member's own subscriptions) entirely.
							$rows[] = 'ids' === $format
								? [ 'member_id' => (int) $facts['holder_id'] ]
								: self::build_row( $plan, $membership_id, $facts, $class, $format );
						}

						if ( $progress ) {
							$progress->tick();
						}
					}
				},
				// Only this walk paces itself. It is the one that reads the whole
				// membership table with several queries per row; the plan walk below is
				// a handful of rows and pausing it would only make the command feel slow
				// before it has done anything.
				$sleep_seconds
			);

			if ( $progress ) {
				$progress->finish();
			}

			self::print_class_summary(
				sprintf( '── Plan %d: "%s" — %d membership(s) with access ──', $plan_id, $plan->post_title, $membership_count ),
				$plan_counts,
				$only_classes,
				$quiet
			);
		}

		// The cross-plan totals. A multi-plan run otherwise gives per-plan breakdowns
		// and no aggregate, leaving the operator to add up a dozen summaries by hand
		// to answer "how many gift memberships are there" — the question the command
		// exists to answer. Printed only when there is more than one plan to add up.
		if ( $audited_plans > 1 ) {
			self::print_class_summary( sprintf( '── All %d plans ──', $audited_plans ), $total_counts, $only_classes, $quiet );
		}

		// A run where every requested plan was skipped audited nothing; reporting
		// "no gift memberships found" would read as a clean bill of health.
		if ( 0 === $audited_plans ) {
			WP_CLI::error( 'None of the given plan IDs are membership plans — nothing was audited.' );
		}

		$flagged_user_ids = self::flagged_user_ids( $rows );

		// A flagged membership whose holder has no account cannot be granted a $0
		// subscription, so it is left off the ID list — but silently dropping it
		// would let an operator hand the list on believing it covers the whole
		// cohort. The warning goes to STDERR, so a redirected ids/CSV stream stays
		// parseable.
		$rows_without_member = self::count_rows_without_member( $rows );
		if ( $rows_without_member ) {
			WP_CLI::warning(
				sprintf(
					'%d flagged membership(s) have no member account and are not on the ID list — an orphaned membership or a guest purchase. They appear in the table/CSV report and need handling by hand.',
					$rows_without_member
				)
			);
		}

		if ( 'ids' === $format ) {
			foreach ( $flagged_user_ids as $user_id ) {
				WP_CLI::line( (string) $user_id );
			}
			return;
		}

		// The machine-readable formats always emit their document, empty or not —
		// a consumer redirecting to a file needs valid CSV/JSON either way.
		if ( $quiet ) {
			\WP_CLI\Utils\format_items( $format, $rows, self::row_fields() );
			return;
		}

		if ( empty( $rows ) ) {
			WP_CLI::success(
				sprintf(
					'No %s memberships found across %d plan(s).',
					implode( '/', $only_classes ),
					$audited_plans
				)
			);
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::row_fields() );

		WP_CLI::line( '' );
		WP_CLI::line(
			sprintf(
				'%d membership(s) across %d distinct reader(s) reported (classes: %s).',
				count( $rows ),
				count( $flagged_user_ids ),
				implode( ', ', $only_classes )
			)
		);
		WP_CLI::line( 'These readers lose access at the flip. `member_own_access_subscriptions` is evidence, not a verdict: a subscription there only preserves access if the gates accept its product.' );
		WP_CLI::line( 'Next: confirm buyer-vs-recipient intent with the publisher, then re-run with --format=ids for the signed-off list and grant those readers $0 subscriptions with `wp newspack migrate-manual-members --user-ids-file=<path>`.' );
	}

	/**
	 * Print a per-class count breakdown.
	 *
	 * Goes to STDERR in the machine-readable formats rather than being dropped.
	 * The counts are what the report reconciles against the plan's member count,
	 * and the operator who exports the cohort needs them as much as the one
	 * reading a table — but they must not land in a redirected CSV, and
	 * recovering them afterwards would cost a second full walk of the membership
	 * table, which is the expensive part of the run.
	 *
	 * @param string   $heading      Section heading.
	 * @param int[]    $counts       Count per class.
	 * @param string[] $only_classes Classes reported member-by-member, marked in the output.
	 * @param bool     $quiet        Whether STDOUT is carrying machine-readable output.
	 */
	private static function print_class_summary( $heading, array $counts, array $only_classes, $quiet ) {
		$lines = [ $heading ];
		foreach ( self::ALL_CLASSES as $class ) {
			if ( 0 === $counts[ $class ] ) {
				continue;
			}
			$lines[] = sprintf(
				'  %-22s %5d  %s%s',
				$class,
				$counts[ $class ],
				self::class_label( $class ),
				in_array( $class, $only_classes, true ) ? ' ←' : ''
			);
		}
		$lines[] = '';

		foreach ( $lines as $line ) {
			if ( $quiet ) {
				// Not a file operation: STDERR is the CLI process's own stream, which is
				// the whole point of writing there rather than to STDOUT.
				fwrite( STDERR, $line . "\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
			} else {
				WP_CLI::line( $line );
			}
		}
	}

	/**
	 * The gloss for a class, as it is true on this site.
	 *
	 * `subscription-missing` normally means the linked subscription was deleted.
	 * With WooCommerce Subscriptions inactive no subscription can be loaded at
	 * all, so every subscription-linked membership lands there and the stock
	 * label would state as fact something the run cannot know.
	 *
	 * @param string $class One of the CLASS_* constants.
	 *
	 * @return string
	 */
	private static function class_label( $class ) {
		if ( self::CLASS_SUBSCRIPTION_MISSING === $class && ! function_exists( 'wcs_get_subscription' ) ) {
			return 'linked subscription could not be loaded (WooCommerce Subscriptions is not active)';
		}
		return self::CLASS_LABELS[ $class ];
	}

	/**
	 * Walk a post query in batches, handing each batch of IDs to $callback.
	 *
	 * Fetching every row in one query is what the audit must not do: a paid
	 * site's membership list runs to tens of thousands, and this command reads
	 * it on the live site. Paging keeps each query bounded however large the
	 * plan is. The walk seeks on the last ID seen rather than using OFFSET,
	 * because the audit being read-only does not make the site read-only — see
	 * the comment on the seek itself.
	 *
	 * @param array    $args     WP_Query args. Paging, ordering and `fields` are set here.
	 * @param callable $callback Receives ( int[] $post_ids, int $total ) per batch, where
	 *                           $total is the count taken at the start of the walk.
	 * @param float    $sleep    Seconds to pause between batches; 0 runs without pausing.
	 *
	 * @return int The number of posts actually walked.
	 */
	private static function each_post_id_batch( array $args, callable $callback, float $sleep = 0 ) {
		global $wpdb;

		$args = array_merge(
			$args,
			[
				'fields'              => 'ids',
				'posts_per_page'      => self::QUERY_BATCH_SIZE,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				self::WALK_QUERY_FLAG => true,
			]
		);

		// Keyset paging, not OFFSET. The audit is read-only but the site is not: a
		// membership that leaves the audited status set mid-run — WooCommerce
		// Memberships' expiry cron, a cancellation, a failed renewal — shifts every
		// later offset by one and silently skips a row. Each skipped row is a reader
		// who may lose access at the flip and never appears on the comp-grant list,
		// and the run gives no signal it happened: the count still looks about right.
		// Seeking on the last ID seen is stable by construction, and drops the
		// deep-offset cost on the large tables this walk exists for.
		//
		// Scoped to the walk's own query by WALK_QUERY_FLAG. The filter stays
		// registered while the callback runs, and the callback drives WooCommerce:
		// WooCommerce Subscriptions answers "which subscriptions does this user
		// have" with a WP_Query on CPT-storage sites and then writes the result to
		// the customer's `_wcs_subscription_ids_cache` user meta. An unscoped seek
		// clause would truncate that query to IDs above the last membership seen and
		// persist the truncated list, which no renewal or status change rewrites —
		// so a read-only audit would silently corrupt reader data it never wrote to.
		$last_id      = 0;
		$where_filter = function ( $where, $query ) use ( &$last_id, $wpdb ) {
			if ( $last_id > 0 && $query->get( self::WALK_QUERY_FLAG ) ) {
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last_id );
			}
			return $where;
		};
		add_filter( 'posts_where', $where_filter, 10, 2 );

		$total = 0;
		$seen  = 0;
		$first = true;

		try {
			while ( true ) {
				// Only the first query needs a count; the rest would pay for a full
				// count of the matching set on every page.
				$query = new \WP_Query( array_merge( $args, [ 'no_found_rows' => ! $first ] ) );

				if ( $first ) {
					$total = (int) $query->found_posts;
					$first = false;
				}
				if ( empty( $query->posts ) ) {
					break;
				}

				$last_id = (int) end( $query->posts );
				$seen   += count( $query->posts );
				$callback( $query->posts, $total );

				// A short batch is the last one; asking for the next page would be a
				// wasted query on every run whose count divides evenly.
				if ( count( $query->posts ) < self::QUERY_BATCH_SIZE ) {
					break;
				}

				// Between batches only, so a run that fits in one batch never pauses and
				// the last batch never pauses on the way out. usleep() takes whole
				// microseconds, which lets --sleep=0.5 mean what it says.
				if ( $sleep > 0 ) {
					usleep( (int) round( $sleep * 1000000 ) );
				}
			}
		} finally {
			remove_filter( 'posts_where', $where_filter );
		}

		// The number actually walked, not the opening count: memberships can leave the
		// set while the walk runs, and reporting the count from before that happened
		// would claim coverage the run does not have.
		return $seen;
	}

	/**
	 * Report columns, in order.
	 *
	 * @return string[]
	 */
	private static function row_fields() {
		return [ 'class', 'plan_id', 'plan', 'membership_id', 'member_id', 'member_email', 'buyer_id', 'buyer_email', 'record', 'record_status', 'products', 'member_own_access_subscriptions' ];
	}

	/**
	 * Classify a membership by how it is backed.
	 *
	 * Pure: everything it needs is in $facts, so the classification table can be
	 * exercised without WooCommerce.
	 *
	 * @param array $facts {
	 *     Facts read off the membership.
	 *
	 *     @type int         $holder_id                Membership holder (post author).
	 *     @type int         $team_id                  Owning Teams team, 0 if not a team seat.
	 *     @type int         $subscription_id          Linked subscription ID, 0 if unlinked.
	 *     @type int|null    $subscription_customer_id Subscription customer, null if it could not be loaded.
	 *     @type string|null $subscription_status      Subscription status, prefixed or not.
	 *     @type bool        $subscription_in_payment_recovery Whether the subscription is on-hold with a
	 *                                                 retry scheduled. Grants access through the runtime's
	 *                                                 payment-recovery grace, so it decides gift vs
	 *                                                 gift-inactive for the on-hold shape.
	 *     @type int|null    $wcsg_recipient_id        Subscriptions Gifting recipient, null when not a gifted subscription.
	 *     @type int         $order_id                 Linked order ID, 0 if unlinked.
	 *     @type int|null    $order_customer_id        Order customer, null if it could not be loaded.
	 *     @type string      $order_status             Order status, '' when no order loaded.
	 *     @type bool        $order_is_paid            Whether the order reached a paid status.
	 *     @type string      $buyer_email              Billing email of a guest buyer, '' when the buyer has an account.
	 *     @type int[]       $products                 Product IDs on the backing record.
	 * }
	 *
	 * @return string One of the CLASS_* constants.
	 */
	public static function classify( array $facts ) {
		$holder_id = (int) ( $facts['holder_id'] ?? 0 );

		// Teams first: the Teams integration stamps the team's subscription (owned
		// by the team owner) onto every seat's membership, so without this every
		// seat on a team would read as a gift.
		if ( ! empty( $facts['team_id'] ) ) {
			return self::CLASS_TEAM_BACKED;
		}

		if ( ! empty( $facts['subscription_id'] ) ) {
			$customer_id = $facts['subscription_customer_id'] ?? null;
			if ( null !== $customer_id ) {
				$grants_access = self::facts_grant_access( $facts );

				// Access Control resolves a gifted subscription to its recipient, so a
				// Subscriptions Gifting gift already carries over — it is not a residual.
				// Only while it still grants access, though: the runtime checks the
				// status BEFORE the gifting rule, so a dead gift carries over nothing
				// and the holder is losing access like any other lapsed member.
				$wcsg_recipient_id = $facts['wcsg_recipient_id'] ?? null;
				if ( $grants_access && $holder_id && null !== $wcsg_recipient_id && (int) $wcsg_recipient_id === $holder_id ) {
					return self::CLASS_GIFT_WCSG;
				}

				if ( ! $holder_id || (int) $customer_id !== $holder_id ) {
					// The ! $holder_id half catches an orphaned membership over a guest
					// purchase, where both IDs are 0: comparing them finds a match and
					// files a membership with no member as "the member owns their
					// access", hiding it inside the covered bucket.
					return $grants_access ? self::CLASS_GIFT : self::CLASS_GIFT_INACTIVE;
				}

				// The holder IS the subscription's customer, but they gifted it to
				// someone else. Access Control excludes a gifted-away subscription for
				// the buyer and grants it only to the recipient, so counting this as
				// "owns their access" would mark the holder covered and cost them their
				// access at the flip — with no row anywhere to catch it, since
				// member-owned is only ever an aggregate count. The sibling teams
				// migration makes the same check for the same reason.
				if ( $grants_access && $holder_id && null !== $wcsg_recipient_id && (int) $wcsg_recipient_id !== $holder_id ) {
					return self::CLASS_GIFT;
				}

				return $grants_access ? self::CLASS_MEMBER_OWNED : self::CLASS_MEMBER_OWNED_INACTIVE;
			}

			// A dangling subscription link falls through to the order below: WCM
			// commonly sets both, and the order still carries usable evidence.
			if ( empty( $facts['order_id'] ) || null === ( $facts['order_customer_id'] ?? null ) ) {
				return self::CLASS_SUBSCRIPTION_MISSING;
			}
		}

		if ( ! empty( $facts['order_id'] ) && null !== ( $facts['order_customer_id'] ?? null ) ) {
			// An unpaid or refunded order is not a purchase to preserve: granting a
			// $0 subscription off it would hand out access nobody paid for.
			return ! empty( $facts['order_is_paid'] ) ? self::CLASS_ORDER_ONLY : self::CLASS_ORDER_ONLY_UNPAID;
		}

		return self::CLASS_NO_PURCHASE_RECORD;
	}

	/**
	 * The flagged members' user IDs, deduplicated in first-seen order.
	 *
	 * One line per user, not per membership: this list is handed to
	 * `migrate-manual-members`, and a reader holding two flagged memberships
	 * would otherwise be granted two $0 subscriptions.
	 *
	 * @param array[] $rows Report rows.
	 *
	 * @return int[]
	 */
	public static function flagged_user_ids( array $rows ) {
		// Keyed rather than scanned: an in_array() per row is quadratic, and the gift
		// cohort on an institutional site is exactly where this list gets long.
		// array_keys() preserves first-seen order, which the report relies on.
		$seen = [];
		foreach ( $rows as $row ) {
			$user_id = (int) ( $row['member_id'] ?? 0 );
			if ( $user_id ) {
				$seen[ $user_id ] = true;
			}
		}
		return array_keys( $seen );
	}

	/**
	 * How many flagged rows have no member account, and so cannot be on the ID
	 * list — an orphaned membership, or one over a guest purchase.
	 *
	 * @param array[] $rows Report rows.
	 *
	 * @return int
	 */
	public static function count_rows_without_member( array $rows ) {
		$without = 0;
		foreach ( $rows as $row ) {
			if ( ! (int) ( $row['member_id'] ?? 0 ) ) {
				++$without;
			}
		}
		return $without;
	}

	/**
	 * Parse the --only flag into the classes to report member-by-member.
	 *
	 * Strict in both directions: an unrecognised class aborts rather than
	 * narrowing the report, and so does a --only that is present but parses to
	 * nothing (`--only=`, `--only=,,`, or `--only="$CLASSES"` with the variable
	 * unset). Either way an empty gift list reads as "this site has no gift
	 * problem", and the ID list the command produces is bare user IDs that
	 * record nothing about which classes built them.
	 *
	 * @param string|null $only Comma-delimited class list; null when --only was not passed.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function parse_only_classes( $only ) {
		if ( null === $only ) {
			return self::REPORTED_CLASSES;
		}

		$only = trim( (string) $only );

		$classes = [];
		foreach ( explode( ',', $only ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			if ( ! in_array( $token, self::SELECTABLE_CLASSES, true ) ) {
				return new \WP_Error(
					'newspack_memberships_audit_unknown_class',
					sprintf( '"%s" is not a reportable class. Use one or more of: %s.', $token, implode( ', ', self::SELECTABLE_CLASSES ) )
				);
			}
			if ( ! in_array( $token, $classes, true ) ) {
				$classes[] = $token;
			}
		}

		if ( empty( $classes ) ) {
			return new \WP_Error(
				'newspack_memberships_audit_empty_only',
				sprintf( '--only was passed but names no class. Drop it for the default report, or pass one or more of: %s.', implode( ', ', self::SELECTABLE_CLASSES ) )
			);
		}

		return $classes;
	}

	/**
	 * Whether a subscription status grants content access under Access Control,
	 * accepting both the `wc-` prefixed form (raw post rows) and the unprefixed
	 * form (WC_Subscription::get_status()).
	 *
	 * @param string $status Subscription status.
	 *
	 * @return bool
	 */
	private static function grants_access( $status ) {
		$status = (string) $status;
		if ( str_starts_with( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		return in_array( $status, self::ACCESS_GRANTING_SUBSCRIPTION_STATUSES, true );
	}

	/**
	 * Whether a membership's subscription grants content access after the flip.
	 *
	 * The status list plus the payment-recovery grace, which is what
	 * `Access_Rules::has_active_subscription()` evaluates and what every caller
	 * building an evaluation context defaults to ON. Asking the status alone would
	 * file an on-hold-in-recovery gift as inactive and drop the reader from the
	 * report, even though at the flip the buyer gains access through the grace
	 * window and the recipient loses it — the exact buyer-vs-recipient question
	 * this command exists to raise.
	 *
	 * @param array $facts Membership facts from read_membership_facts().
	 *
	 * @return bool
	 */
	private static function facts_grant_access( array $facts ) {
		if ( ! empty( $facts['subscription_in_payment_recovery'] ) ) {
			return true;
		}
		return self::grants_access( $facts['subscription_status'] ?? '' );
	}

	/**
	 * Membership statuses that currently grant access, read from WooCommerce
	 * Memberships itself so a site filtering
	 * `wc_memberships_active_access_membership_statuses` is audited on its own
	 * terms. Falls back to the constant when the API is unavailable.
	 *
	 * @return string[] `wcm-` prefixed statuses.
	 */
	private static function get_active_membership_statuses() {
		if ( function_exists( 'wc_memberships' ) ) {
			$memberships = \wc_memberships();
			$instance    = $memberships && method_exists( $memberships, 'get_user_memberships_instance' )
				? $memberships->get_user_memberships_instance()
				: null;
			if ( $instance && method_exists( $instance, 'get_active_access_membership_statuses' ) ) {
				return self::prefix_membership_statuses( (array) $instance->get_active_access_membership_statuses() );
			}
		}
		return self::ACTIVE_MEMBERSHIP_STATUSES;
	}

	/**
	 * Normalise WCM's membership statuses to the `wcm-` prefixed post statuses
	 * `get_posts()` expects. WCM returns them unprefixed, but the filter sites
	 * use to extend the list may hand back either form.
	 *
	 * An empty list falls back to the constant: it would otherwise match no
	 * memberships at all, and an audit that examines nothing reports a clean
	 * bill of health.
	 *
	 * @param string[] $statuses Statuses as WCM reports them.
	 *
	 * @return string[]
	 */
	public static function prefix_membership_statuses( array $statuses ) {
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return self::ACTIVE_MEMBERSHIP_STATUSES;
		}
		return array_values(
			array_unique(
				array_map(
					function ( $status ) {
						return 0 === strpos( $status, 'wcm-' ) ? $status : 'wcm-' . $status;
					},
					$statuses
				)
			)
		);
	}

	/**
	 * Read the backing facts off a membership.
	 *
	 * Resolution goes through the WooCommerce data stores rather than SQL, so it
	 * is correct on HPOS sites (where subscriptions and orders live in their own
	 * tables) as well as legacy post-storage ones. The membership itself is a
	 * CPT under both — WooCommerce Memberships does not participate in HPOS.
	 *
	 * @param int $membership_id Membership post ID.
	 *
	 * @return array Facts, as documented on classify().
	 */
	private static function read_membership_facts( $membership_id ) {
		$membership      = \get_post( $membership_id );
		$holder_id       = $membership ? (int) $membership->post_author : 0;
		$subscription_id = (int) \get_post_meta( $membership_id, '_subscription_id', true );
		$order_id        = (int) \get_post_meta( $membership_id, '_order_id', true );

		$facts = [
			'holder_id'                        => $holder_id,
			'team_id'                          => (int) \get_post_meta( $membership_id, '_team_id', true ),
			'subscription_id'                  => $subscription_id,
			'subscription_customer_id'         => null,
			'subscription_status'              => '',
			// Whether the subscription is on-hold with a retry scheduled. Held
			// separately from the status because that is the whole distinction: the
			// runtime's payment-recovery grace turns exactly this shape into access.
			'subscription_in_payment_recovery' => false,
			'wcsg_recipient_id'                => null,
			'order_id'                         => $order_id,
			'order_customer_id'                => null,
			'order_status'                     => '',
			'order_is_paid'                    => false,
			'buyer_email'                      => '',
			'products'                         => [],
		];

		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				$facts['subscription_customer_id'] = (int) $subscription->get_user_id();
				$facts['subscription_status']      = $subscription->get_status();
				$facts['subscription_in_payment_recovery'] = class_exists( 'Newspack\WooCommerce_Connection' )
					&& \Newspack\WooCommerce_Connection::is_subscription_in_payment_recovery( $subscription );
				$facts['wcsg_recipient_id']        = self::get_wcsg_recipient_id( $subscription );
				$facts['products']                 = self::get_product_ids( $subscription );
				if ( ! $facts['subscription_customer_id'] && method_exists( $subscription, 'get_billing_email' ) ) {
					// Guest purchase: the billing address is the only identity the
					// publisher can recognise the buyer by.
					$facts['buyer_email'] = $subscription->get_billing_email();
				}
				return $facts;
			}
		}

		if ( $order_id ) {
			$order = \wc_get_order( $order_id );
			if ( $order ) {
				$facts['order_customer_id'] = (int) $order->get_customer_id();
				$facts['order_status']      = $order->get_status();
				$facts['order_is_paid']     = self::order_is_paid( $order );
				$facts['products']          = self::get_product_ids( $order );
				if ( ! $facts['order_customer_id'] ) {
					// Guest checkout: the billing address is the only identity the
					// publisher can recognise the buyer by.
					$facts['buyer_email'] = $order->get_billing_email();
				}
			}
		}

		return $facts;
	}

	/**
	 * Whether an order reached a status WooCommerce considers paid.
	 *
	 * @param \WC_Abstract_Order $order The order.
	 *
	 * @return bool
	 */
	private static function order_is_paid( $order ) {
		if ( method_exists( $order, 'is_paid' ) ) {
			return (bool) $order->is_paid();
		}
		return function_exists( 'wc_get_is_paid_statuses' )
			&& in_array( $order->get_status(), \wc_get_is_paid_statuses(), true );
	}

	/**
	 * The WooCommerce Subscriptions Gifting recipient of a subscription, when
	 * that plugin is active and the subscription is a gift.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return int|null Recipient user ID, or null when not a gifted subscription.
	 */
	private static function get_wcsg_recipient_id( $subscription ) {
		if ( ! class_exists( 'WCS_Gifting' ) || ! \WCS_Gifting::is_gifted_subscription( $subscription ) ) {
			return null;
		}
		return (int) \WCS_Gifting::get_recipient_user( $subscription );
	}

	/**
	 * Product IDs on an order or subscription's line items.
	 *
	 * Variations are reported alongside their parent: a gate's product rules can
	 * name either, so a column showing only the parent would read as "not
	 * covered" against a gate configured with the variation.
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 *
	 * @return int[]
	 */
	private static function get_product_ids( $order ) {
		$product_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$candidates = [ (int) $item->get_product_id() ];
			if ( method_exists( $item, 'get_variation_id' ) ) {
				$candidates[] = (int) $item->get_variation_id();
			}
			foreach ( $candidates as $product_id ) {
				if ( $product_id && ! in_array( $product_id, $product_ids, true ) ) {
					$product_ids[] = $product_id;
				}
			}
		}
		return $product_ids;
	}

	/**
	 * Build a report row for a flagged membership.
	 *
	 * @param \WP_Post $plan          The membership plan.
	 * @param int      $membership_id Membership post ID.
	 * @param array    $facts         Facts from read_membership_facts().
	 * @param string   $class         The membership's class.
	 * @param string   $format        Output format (placeholders differ for machine formats).
	 *
	 * @return array
	 */
	private static function build_row( $plan, $membership_id, $facts, $class, $format ) {
		$order_backed = in_array( $class, [ self::CLASS_ORDER_ONLY, self::CLASS_ORDER_ONLY_UNPAID ], true );
		$member_id    = (int) $facts['holder_id'];
		$buyer_id     = $order_backed
			? (int) $facts['order_customer_id']
			: (int) $facts['subscription_customer_id'];
		$empty        = 'table' === $format ? '—' : '';

		$buyer_email = self::get_user_email( $buyer_id, $empty );
		if ( ! $buyer_id && ! empty( $facts['buyer_email'] ) ) {
			$buyer_email = $facts['buyer_email'] . ' (guest)';
		}

		return [
			'class'                           => $class,
			'plan_id'                         => (int) $plan->ID,
			'plan'                            => $plan->post_title,
			'membership_id'                   => (int) $membership_id,
			'member_id'                       => $member_id,
			'member_email'                    => self::get_user_email( $member_id, $empty ),
			// On an order-backed row the buyer is the holder unless the one-off was
			// itself a gift; reporting it either way keeps the columns comparable.
			'buyer_id'                        => $buyer_id,
			'buyer_email'                     => $buyer_email,
			'record'                          => $order_backed
				? 'order ' . (int) $facts['order_id']
				: 'subscription ' . (int) $facts['subscription_id'],
			// An on-hold subscription is on this list only because the
			// payment-recovery grace still grants access on it, while the
			// gift-inactive cohort holds on-hold rows that were correctly left off.
			// The bare status cannot tell those apart, so it is annotated.
			'record_status'                   => $order_backed
				? (string) $facts['order_status']
				: self::describe_subscription_status( $facts ),
			'products'                        => implode( ' ', $facts['products'] ),
			'member_own_access_subscriptions' => self::describe_own_access_subscriptions( $member_id, $empty ),
		];
	}

	/**
	 * The subscription status as the report should read it: the raw status, plus
	 * a note when it is the payment-recovery grace rather than the status that
	 * keeps the row on the list.
	 *
	 * @param array $facts Facts from read_membership_facts().
	 *
	 * @return string
	 */
	public static function describe_subscription_status( array $facts ) {
		$status = (string) ( $facts['subscription_status'] ?? '' );
		if ( '' !== $status && ! self::grants_access( $status ) && ! empty( $facts['subscription_in_payment_recovery'] ) ) {
			return $status . ' (in payment recovery)';
		}
		return $status;
	}

	/**
	 * Describe the subscriptions that grant this member access in their own
	 * right, as `<id>(<product ids>)` — evidence for the sign-off conversation.
	 *
	 * Asks Access Control's own resolver rather than re-deriving the rule, so
	 * the column means exactly what the runtime will do: a subscription the
	 * member owns but gifted away does not count, and one gifted TO them does.
	 * Re-deriving it here would print reassurance for a reader the gates will
	 * lock out — the direction that costs a reader their access.
	 *
	 * Deliberately not a verdict on whether the member keeps access: that
	 * depends on whether the gates accept those products, which the
	 * access-parity diff decides. A recurring donation is the common case of an
	 * access-granting subscription that unlocks no content.
	 *
	 * @param int    $user_id The member.
	 * @param string $empty   Placeholder for "none".
	 *
	 * @return string Space-separated descriptors, or the placeholder.
	 */
	private static function describe_own_access_subscriptions( $user_id, $empty ) {
		if ( ! $user_id || ! class_exists( 'Newspack\WooCommerce_Connection' ) ) {
			return $empty;
		}

		$descriptors = [];
		// The third argument is $include_payment_recovery, and it must be true here:
		// its own default is false, but Access_Rules::has_active_subscription() passes
		// the evaluation context's payment_recovery_grace, which defaults to ON. Asking
		// with the grace off would under-state coverage and put a member on the
		// comp-grant list for access they are going to keep.
		foreach ( \Newspack\WooCommerce_Connection::get_active_subscriptions_for_user( $user_id, [], true ) as $subscription_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			$product_ids   = self::get_product_ids( $subscription );
			$descriptors[] = sprintf( '%d(%s)', $subscription_id, implode( ',', $product_ids ) );
		}

		return empty( $descriptors ) ? $empty : implode( ' ', $descriptors );
	}

	/**
	 * A user's email, or a placeholder when the account is gone.
	 *
	 * @param int    $user_id User ID.
	 * @param string $empty   Placeholder for "no user".
	 *
	 * @return string
	 */
	private static function get_user_email( $user_id, $empty ) {
		if ( ! $user_id ) {
			return $empty;
		}
		$user = \get_userdata( $user_id );
		return $user ? $user->user_email : '(user not found)';
	}

	/**
	 * Resolve --plan-ids, defaulting to every published plan.
	 *
	 * @param string|null $plan_ids_csv Comma-delimited plan IDs; null when --plan-ids was not passed.
	 *
	 * @return int[]|\WP_Error
	 */
	public static function resolve_plan_ids( $plan_ids_csv ) {
		if ( null === $plan_ids_csv ) {
			$all_plan_ids = [];
			self::each_post_id_batch(
				[
					'post_type'   => 'wc_membership_plan',
					'post_status' => 'publish',
				],
				function ( $plan_ids ) use ( &$all_plan_ids ) {
					$all_plan_ids = array_merge( $all_plan_ids, $plan_ids );
				}
			);
			return $all_plan_ids;
		}

		$plan_ids_csv = trim( (string) $plan_ids_csv );
		$plan_ids     = [];
		foreach ( preg_split( '/[\s,]+/', $plan_ids_csv ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( ! ctype_digit( $token ) || 0 === (int) $token ) {
				return new \WP_Error(
					'newspack_memberships_audit_plan_id',
					sprintf( '"%s" is not a valid plan ID — fix the --plan-ids input.', $token )
				);
			}
			$plan_ids[] = (int) $token;
		}

		// Same strictness as --only: a flag that is present but names nothing must
		// not quietly become "audit everything".
		if ( empty( $plan_ids ) ) {
			return new \WP_Error(
				'newspack_memberships_audit_plan_id',
				'--plan-ids was passed but names no plan. Drop it to audit every published plan.'
			);
		}

		return array_values( array_unique( $plan_ids ) );
	}
}
