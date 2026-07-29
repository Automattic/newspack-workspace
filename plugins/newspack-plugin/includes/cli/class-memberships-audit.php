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
	 * Subscription statuses that grant content access under Access Control.
	 *
	 * Mirrors `WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES`, which is
	 * what `Access_Rules::has_active_subscription()` actually evaluates. Held as
	 * a local constant so classify() stays pure and testable; a unit test asserts
	 * the two lists are identical, so drift fails the build rather than a
	 * migration. **`on-hold` is deliberately absent**: a subscription in payment
	 * retry grants nothing post-flip, so counting it as access-granting here
	 * would report a reader as covered who is about to lose access.
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
	 * the signed-off list for whatever grants the replacement access — a
	 * `migrate-manual-members` that can select members by user ID, or operator
	 * scripting on builds where it cannot.
	 *
	 * ## OPTIONS
	 *
	 * [--plan-ids=<ids>]
	 * : Comma-delimited membership plan IDs to audit — the plans whose content the new gates cover. Defaults to every published plan (a plan in any other status is audited only when named here).
	 *
	 * [--only=<classes>]
	 * : Comma-delimited classes to report member-by-member: gift, order-only, order-only-unpaid, subscription-missing. Defaults to gift + order-only. Counts for every class are printed regardless.
	 *
	 * [--format=<format>]
	 * : Output format. `ids` prints only the flagged user IDs, one per line, deduplicated per user.
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
	 *     wp newspack audit-membership-subscriptions --only=gift --format=csv
	 *     wp newspack audit-membership-subscriptions --only=gift --format=ids
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function audit_membership_subscriptions( $args, $assoc_args ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			// Not fatal: a site without Subscriptions has no subscription links to
			// resolve, and its memberships are exactly the order-only / comp cohorts
			// this command still classifies correctly.
			WP_CLI::warning( 'WooCommerce Subscriptions is not active — every membership is audited as if it had no subscription link.' );
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		// Only the table format narrates. csv/json/ids are consumed by redirecting
		// them to a file, and any prose in that file makes it unparseable.
		$quiet = 'table' !== $format;

		$only_classes = self::parse_only_classes( \WP_CLI\Utils\get_flag_value( $assoc_args, 'only', '' ) );
		if ( \is_wp_error( $only_classes ) ) {
			WP_CLI::error( $only_classes->get_error_message() );
		}

		$plan_ids = self::resolve_plan_ids( \WP_CLI\Utils\get_flag_value( $assoc_args, 'plan-ids', '' ) );
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

			$memberships = \get_posts(
				[
					'post_type'      => 'wc_user_membership',
					'post_status'    => $membership_statuses,
					'post_parent'    => $plan_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			);

			$plan_counts = array_fill_keys( self::ALL_CLASSES, 0 );

			// Long runs over SSH look like a hang without this. It writes to STDOUT,
			// so it must not run for the machine-readable formats.
			$progress = $quiet
				? null
				: \WP_CLI\Utils\make_progress_bar( sprintf( 'Plan %d: %d membership(s)', $plan_id, count( $memberships ) ), count( $memberships ) );

			foreach ( $memberships as $membership_id ) {
				// Keep memory bounded across an unbounded membership list; the rows
				// collected below are plain arrays and survive the flush.
				\WP_CLI\Utils\wp_clear_object_cache();

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

			if ( $progress ) {
				$progress->finish();
			}

			if ( ! $quiet ) {
				WP_CLI::line( sprintf( '── Plan %d: "%s" — %d membership(s) with access ──', $plan_id, $plan->post_title, count( $memberships ) ) );
				foreach ( self::ALL_CLASSES as $class ) {
					if ( 0 === $plan_counts[ $class ] ) {
						continue;
					}
					WP_CLI::line(
						sprintf(
							'  %-22s %5d  %s%s',
							$class,
							$plan_counts[ $class ],
							self::CLASS_LABELS[ $class ],
							in_array( $class, $only_classes, true ) ? ' ←' : ''
						)
					);
				}
				WP_CLI::line( '' );
			}
		}

		// A run where every requested plan was skipped audited nothing; reporting
		// "no gift memberships found" would read as a clean bill of health.
		if ( 0 === $audited_plans ) {
			WP_CLI::error( 'None of the given plan IDs are membership plans — nothing was audited.' );
		}

		$flagged_user_ids = self::flagged_user_ids( $rows );

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
		WP_CLI::line( 'Next: confirm buyer-vs-recipient intent with the publisher, then re-run with --format=ids for the signed-off list. Granting those readers $0 subscriptions needs a migrate-manual-members that can select members by user ID (--user-ids/--user-ids-file); run `wp help newspack migrate-manual-members` to check this build, and fall back to operator scripting against the list where it cannot.' );
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
				$grants_access = self::grants_access( $facts['subscription_status'] ?? '' );

				// Access Control resolves a gifted subscription to its recipient, so a
				// Subscriptions Gifting gift already carries over — it is not a residual.
				// Only while it still grants access, though: the runtime checks the
				// status BEFORE the gifting rule, so a dead gift carries over nothing
				// and the holder is losing access like any other lapsed member.
				$wcsg_recipient_id = $facts['wcsg_recipient_id'] ?? null;
				if ( $grants_access && $holder_id && null !== $wcsg_recipient_id && (int) $wcsg_recipient_id === $holder_id ) {
					return self::CLASS_GIFT_WCSG;
				}

				if ( (int) $customer_id !== $holder_id ) {
					return $grants_access ? self::CLASS_GIFT : self::CLASS_GIFT_INACTIVE;
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
		$user_ids = [];
		foreach ( $rows as $row ) {
			$user_id = (int) ( $row['member_id'] ?? 0 );
			if ( $user_id && ! in_array( $user_id, $user_ids, true ) ) {
				$user_ids[] = $user_id;
			}
		}
		return $user_ids;
	}

	/**
	 * Parse the --only flag into the classes to report member-by-member.
	 *
	 * Strict: an unrecognised class aborts rather than narrowing the report,
	 * because an empty gift list reads as "this site has no gift problem".
	 *
	 * @param string $only Comma-delimited class list, '' for the default set.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function parse_only_classes( $only ) {
		$only = trim( (string) $only );
		if ( '' === $only ) {
			return self::REPORTED_CLASSES;
		}

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

		return empty( $classes ) ? self::REPORTED_CLASSES : $classes;
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
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		return in_array( $status, self::ACCESS_GRANTING_SUBSCRIPTION_STATUSES, true );
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
			'holder_id'                => $holder_id,
			'team_id'                  => (int) \get_post_meta( $membership_id, '_team_id', true ),
			'subscription_id'          => $subscription_id,
			'subscription_customer_id' => null,
			'subscription_status'      => '',
			'wcsg_recipient_id'        => null,
			'order_id'                 => $order_id,
			'order_customer_id'        => null,
			'order_status'             => '',
			'order_is_paid'            => false,
			'buyer_email'              => '',
			'products'                 => [],
		];

		if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( $subscription ) {
				$facts['subscription_customer_id'] = (int) $subscription->get_user_id();
				$facts['subscription_status']      = $subscription->get_status();
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
			'record_status'                   => $order_backed
				? (string) $facts['order_status']
				: (string) $facts['subscription_status'],
			'products'                        => implode( ' ', $facts['products'] ),
			'member_own_access_subscriptions' => self::describe_own_access_subscriptions( $member_id, $empty ),
		];
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
		foreach ( \Newspack\WooCommerce_Connection::get_active_subscriptions_for_user( $user_id ) as $subscription_id ) {
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
	 * @param string $plan_ids_csv Comma-delimited plan IDs, '' for all.
	 *
	 * @return int[]|\WP_Error
	 */
	public static function resolve_plan_ids( $plan_ids_csv ) {
		$plan_ids_csv = trim( (string) $plan_ids_csv );
		if ( '' === $plan_ids_csv ) {
			return \get_posts(
				[
					'post_type'      => 'wc_membership_plan',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				]
			);
		}

		$plan_ids = [];
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

		return array_values( array_unique( $plan_ids ) );
	}
}
