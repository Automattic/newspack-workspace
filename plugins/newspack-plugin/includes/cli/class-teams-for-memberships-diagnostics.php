<?php
/**
 * WC Memberships for Teams diagnostics CLI command.
 *
 * Detects (and optionally repairs) data inconsistencies introduced by SkyVerge
 * WC Memberships for Teams when a subscription's line items lose their team
 * dispatch meta. See https://linear.app/a8c/issue/NPPM-2741 for background.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WC Memberships for Teams diagnostics CLI command.
 */
class Teams_For_Memberships_Diagnostics {

	/**
	 * Whether to actually apply fixes.
	 *
	 * @var bool
	 */
	private static $fix = false;

	/**
	 * Optional team ID to scope checks to.
	 *
	 * @var int|null
	 */
	private static $team_id = null;

	/**
	 * Accumulated issue counts per check.
	 *
	 * @var array<string,int>
	 */
	private static $counts = [];

	/**
	 * Whether the user has explicitly suppressed the fix confirmation prompt.
	 *
	 * @var bool
	 */
	private static $skip_confirm = false;

	/**
	 * Whether the fix confirmation prompt still needs to fire on the next fix.
	 *
	 * @var bool
	 */
	private static $needs_fix_confirmation = false;

	/**
	 * Cached list of team posts. Invalidated after any destructive check.
	 *
	 * @var \WP_Post[]|null
	 */
	private static $all_teams_cache = null;

	/**
	 * Cached map of team_id => [ [ membership_id, sub_id ], ... ].
	 *
	 * @var array<int,array<int,array{membership_id:int,sub_id:?int}>>|null
	 */
	private static $team_memberships_map = null;

	/**
	 * Scans for WC Memberships for Teams data inconsistencies and (with --fix) repairs them.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Apply repairs. Without this flag the command is read-only.
	 *
	 * [--team-id=<id>]
	 * : Scope all checks to a single team post ID.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation before applying fixes. Requires --fix.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack teams-for-memberships diagnostics
	 *     wp newspack teams-for-memberships diagnostics --fix --yes
	 *     wp newspack teams-for-memberships diagnostics --team-id=1002618
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 * @return void
	 */
	public function diagnostics( $args, $assoc_args ) {
		if ( ! class_exists( 'WC_Memberships_For_Teams_Loader' ) ) {
			WP_CLI::error( 'WC Memberships for Teams is not active on this site.' );
		}

		self::$fix                    = isset( $assoc_args['fix'] );
		self::$team_id                = isset( $assoc_args['team-id'] ) ? (int) $assoc_args['team-id'] : null;
		self::$counts                 = [];
		self::$skip_confirm           = isset( $assoc_args['yes'] );
		self::$needs_fix_confirmation = self::$fix;
		self::$all_teams_cache        = null;
		self::$team_memberships_map   = null;

		$mode = self::$fix ? 'FIX' : 'read-only';
		WP_CLI::line( sprintf( 'Running WC Memberships for Teams diagnostics (%s mode).', $mode ) );
		if ( self::$team_id ) {
			WP_CLI::line( sprintf( 'Scoped to team #%d.', self::$team_id ) );
		}
		WP_CLI::line( '' );

		// Run each check in isolation so a failure in one does not hide results from the others
		// or skip the summary footer.
		foreach (
			[
				'check_duplicate_teams',
				'check_teams_missing_subscription_id',
				'check_memberships_missing_subscription_id',
				'check_team_owner_subscription_mismatch',
				'check_subscription_line_items_missing_team_meta',
			] as $check
		) {
			try {
				self::$check();
			} catch ( \Throwable $e ) {
				WP_CLI::warning( sprintf( '%s threw: %s', $check, $e->getMessage() ) );
			}
		}

		$total = array_sum( self::$counts );
		WP_CLI::line( '' );
		if ( 0 === $total ) {
			WP_CLI::success( 'No issues found.' );
			return;
		}
		WP_CLI::line( sprintf( '%d issue(s) found:', $total ) );
		foreach ( self::$counts as $label => $count ) {
			WP_CLI::line( sprintf( '  - %s: %d', $label, $count ) );
		}
		if ( ! self::$fix ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Re-run with --fix to apply repairs (checks 1, 2, 3 and 5). Check 4 (owner mismatch) must be resolved manually.' );
		}
	}

	/**
	 * Check 1: duplicate teams with the same title + post_author.
	 *
	 * These appear when SkyVerge Teams creates a replacement team on renewal
	 * because the subscription's line item lost its dispatch meta.
	 *
	 * @return void
	 */
	private static function check_duplicate_teams() {
		WP_CLI::line( 'Check 1: duplicate teams (same title + same post_author)' );

		if ( self::$team_id ) {
			// A naive `get_all_teams()` here returns the single requested row and Check 1
			// becomes a no-op. Widen the search to every team sharing the target team's
			// title + author so `--team-id` can still surface a known-duplicated team.
			$target = get_post( self::$team_id );
			if ( ! $target || 'wc_memberships_team' !== $target->post_type ) {
				WP_CLI::warning( '  SKIP: --team-id does not point to a wc_memberships_team post.' );
				self::$counts['Duplicate teams'] = 0;
				return;
			}
			$teams = get_posts(
				[
					'post_type'      => 'wc_memberships_team',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'author'         => $target->post_author,
					'title'          => $target->post_title,
				]
			);
		} else {
			$teams = self::get_all_teams();
		}

		$buckets = [];
		foreach ( $teams as $team ) {
			$key = $team->post_author . '|' . $team->post_title;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = [];
			}
			$buckets[ $key ][] = $team;
		}

		$duplicate_sets = array_filter(
			$buckets,
			function ( $bucket ) {
				return count( $bucket ) > 1;
			}
		);

		if ( empty( $duplicate_sets ) ) {
			WP_CLI::line( '  OK: no duplicates.' );
			return;
		}

		$issue_count = 0;
		foreach ( $duplicate_sets as $bucket ) {
			// Oldest team with a _subscription_id wins. If none has one, fall back to oldest by post_date.
			usort(
				$bucket,
				function ( $a, $b ) {
					return strcmp( $a->post_date, $b->post_date );
				}
			);
			$original  = null;
			$duplicates = [];
			foreach ( $bucket as $team ) {
				$sub_id = get_post_meta( $team->ID, '_subscription_id', true );
				if ( null === $original && ! empty( $sub_id ) ) {
					$original = $team;
					continue;
				}
				$duplicates[] = $team;
			}
			if ( null === $original ) {
				$original   = array_shift( $bucket );
				$duplicates = $bucket;
			}

			foreach ( $duplicates as $dup ) {
				$issue_count++;
				WP_CLI::line(
					sprintf(
						'  ISSUE: duplicate team #%d ("%s", author %d) – original #%d',
						$dup->ID,
						$dup->post_title,
						$dup->post_author,
						$original->ID
					)
				);
				if ( self::$fix ) {
					self::fix_duplicate_team( $original, $dup );
				}
			}
		}

		self::$counts['Duplicate teams'] = $issue_count;
	}

	/**
	 * Merge a duplicate team into its original.
	 *
	 * @param \WP_Post $original Original team post.
	 * @param \WP_Post $duplicate Duplicate team post.
	 * @return void
	 */
	private static function fix_duplicate_team( $original, $duplicate ) {
		if ( ! function_exists( 'wc_memberships_for_teams_get_team' ) ) {
			WP_CLI::warning( '    SKIP: wc_memberships_for_teams_get_team() unavailable.' );
			return;
		}
		self::ensure_fix_confirmed();
		$original_team  = wc_memberships_for_teams_get_team( $original->ID );
		$duplicate_team = wc_memberships_for_teams_get_team( $duplicate->ID );
		if ( ! $original_team || ! $duplicate_team ) {
			WP_CLI::warning( '    SKIP: could not load team objects.' );
			return;
		}

		// Allow assigning users to the 'owner' role via Team::add_member().
		$role_filter = function ( $roles ) {
			$roles['owner'] = 'Owner';
			return $roles;
		};
		add_filter( 'wc_memberships_for_teams_team_member_roles', $role_filter );

		try {
			foreach ( $duplicate_team->get_members() as $member ) {
				WP_CLI::line( sprintf( '    MOVE member %s (role %s) to original team #%d', $member->get_email(), $member->get_role(), $original_team->get_id() ) );
				try {
					$original_team->add_member( $member->get_id(), $member->get_role() );
				} catch ( \Throwable $e ) {
					WP_CLI::warning( '    ' . $e->getMessage() );
				}
			}
		} finally {
			remove_filter( 'wc_memberships_for_teams_team_member_roles', $role_filter );
		}

		// Point any remaining user_memberships still referencing the duplicate back at the original.
		$map = self::get_team_memberships_map();
		foreach ( $map[ $duplicate->ID ] ?? [] as $row ) {
			WP_CLI::line( sprintf( '    RELINK membership #%d to team #%d', $row['membership_id'], $original->ID ) );
			update_post_meta( $row['membership_id'], '_team_id', $original->ID );
		}

		// Bring over the newer _order_id if needed.
		$dup_order_id      = get_post_meta( $duplicate->ID, '_order_id', true );
		$original_order_id = get_post_meta( $original->ID, '_order_id', true );
		if ( $dup_order_id && $dup_order_id !== $original_order_id ) {
			WP_CLI::line( sprintf( '    COPY _order_id %d to original team #%d', $dup_order_id, $original->ID ) );
			update_post_meta( $original->ID, '_order_id', $dup_order_id );
		}

		// Finally delete the now-empty duplicate.
		$remaining_members = $duplicate_team->get_member_ids();
		if ( empty( $remaining_members ) ) {
			WP_CLI::line( sprintf( '    DELETE duplicate team #%d', $duplicate->ID ) );
			wp_delete_post( $duplicate->ID, true );
		} else {
			WP_CLI::warning( sprintf( '    KEEP duplicate team #%d: %d members could not be migrated.', $duplicate->ID, count( $remaining_members ) ) );
		}

		// Team list and membership->team map are stale after this fix; force a fresh read for later checks.
		self::invalidate_caches();
	}

	/**
	 * Check 2: teams without a _subscription_id.
	 *
	 * @return void
	 */
	private static function check_teams_missing_subscription_id() {
		WP_CLI::line( 'Check 2: teams missing _subscription_id' );
		$teams = self::get_all_teams();

		$issue_count = 0;
		foreach ( $teams as $team ) {
			$sub_id = get_post_meta( $team->ID, '_subscription_id', true );
			if ( ! empty( $sub_id ) ) {
				continue;
			}
			$issue_count++;
			WP_CLI::line( sprintf( '  ISSUE: team #%d ("%s") has no _subscription_id', $team->ID, $team->post_title ) );
			if ( self::$fix ) {
				self::fix_team_missing_subscription_id( $team );
			}
		}
		if ( 0 === $issue_count ) {
			WP_CLI::line( '  OK: all teams have _subscription_id.' );
		}
		self::$counts['Teams missing _subscription_id'] = $issue_count;
	}

	/**
	 * Try to recover a team's subscription link.
	 *
	 * @param \WP_Post $team Team post.
	 * @return void
	 */
	private static function fix_team_missing_subscription_id( $team ) {
		self::ensure_fix_confirmed();
		// Candidate 1: any user_membership on this team that carries its own _subscription_id.
		// Reads come from a single pre-built map so this loop is O(1) per team instead of
		// doing an unbounded get_posts() + per-row get_post_meta() for each flagged team.
		$map               = self::get_team_memberships_map();
		$candidate_sub_ids = [];
		foreach ( $map[ $team->ID ] ?? [] as $row ) {
			if ( null !== $row['sub_id'] ) {
				$candidate_sub_ids[ $row['sub_id'] ] = true;
			}
		}

		// Candidate 2: an active subscription owned by the team owner that includes the team's product.
		// `wcs_get_users_subscriptions` returns every status, so filter to live subscriptions – cancelled or
		// expired subs would give us a wrong link and either mis-repair or block fix via the "multiple candidates" branch.
		$active_statuses = [ 'active', 'pending-cancel' ];
		$member_id       = (int) get_post_meta( $team->ID, '_member_id', true );
		$product_id      = (int) get_post_meta( $team->ID, '_product_id', true );
		if ( $member_id && $product_id && function_exists( 'wcs_get_users_subscriptions' ) ) {
			$user_subs = wcs_get_users_subscriptions( $member_id );
			foreach ( $user_subs as $sub ) {
				if ( ! in_array( $sub->get_status(), $active_statuses, true ) ) {
					continue;
				}
				foreach ( $sub->get_items() as $sub_item ) {
					if ( (int) $sub_item->get_product_id() === $product_id ) {
						$candidate_sub_ids[ (int) $sub->get_id() ] = true;
					}
				}
			}
		}

		$candidate_ids = array_keys( $candidate_sub_ids );
		if ( 1 === count( $candidate_ids ) ) {
			WP_CLI::line( sprintf( '    SET team #%d _subscription_id = %d', $team->ID, $candidate_ids[0] ) );
			update_post_meta( $team->ID, '_subscription_id', $candidate_ids[0] );
			return;
		}
		if ( count( $candidate_ids ) > 1 ) {
			WP_CLI::warning( sprintf( '    SKIP team #%d: multiple candidate subscriptions (%s) – resolve manually.', $team->ID, implode( ', ', $candidate_ids ) ) );
			return;
		}
		WP_CLI::warning( sprintf( '    SKIP team #%d: no candidate subscription found.', $team->ID ) );
	}

	/**
	 * Check 3: user_memberships missing _subscription_id but whose team has one.
	 *
	 * @return void
	 */
	private static function check_memberships_missing_subscription_id() {
		WP_CLI::line( 'Check 3: memberships missing _subscription_id but recoverable via team' );
		global $wpdb;

		// Pull `team._subscription_id` via a join in the same query so we don't issue per-row
		// get_post_meta() calls for every flagged membership. The inner join on pm_team_sub
		// also naturally filters out Check 2 cases (teams that have no _subscription_id either).
		$base_sql = "SELECT p.ID AS membership_id, pm_team.meta_value AS team_id, pm_team_sub.meta_value AS team_sub_id
			FROM $wpdb->posts p
			JOIN $wpdb->postmeta pm_team ON pm_team.post_id = p.ID AND pm_team.meta_key = '_team_id'
			LEFT JOIN $wpdb->postmeta pm_sub ON pm_sub.post_id = p.ID AND pm_sub.meta_key = '_subscription_id'
			JOIN $wpdb->postmeta pm_team_sub ON pm_team_sub.post_id = pm_team.meta_value AND pm_team_sub.meta_key = '_subscription_id'
			WHERE p.post_type = 'wc_user_membership'
			AND ( pm_sub.meta_value IS NULL OR pm_sub.meta_value = '' )
			AND pm_team_sub.meta_value <> ''";

		if ( self::$team_id ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( $base_sql . ' AND pm_team.meta_value = %d', self::$team_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		} else {
			$rows = $wpdb->get_results( $base_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		$issue_count = 0;
		foreach ( $rows as $row ) {
			$issue_count++;
			WP_CLI::line(
				sprintf(
					'  ISSUE: membership #%d has no _subscription_id (team #%d has #%s)',
					$row->membership_id,
					$row->team_id,
					$row->team_sub_id
				)
			);
			if ( self::$fix ) {
				self::ensure_fix_confirmed();
				WP_CLI::line( sprintf( '    SET membership #%d _subscription_id = %s', $row->membership_id, $row->team_sub_id ) );
				update_post_meta( (int) $row->membership_id, '_subscription_id', $row->team_sub_id );
			}
		}

		if ( 0 === $issue_count ) {
			WP_CLI::line( '  OK: all memberships with a team have _subscription_id.' );
		}
		self::$counts['Memberships missing _subscription_id'] = $issue_count;
	}

	/**
	 * Check 4: team _member_id differs from linked subscription _customer_user.
	 *
	 * Not auto-fixable: a human must decide whether to transfer the team or the subscription.
	 *
	 * @return void
	 */
	private static function check_team_owner_subscription_mismatch() {
		WP_CLI::line( 'Check 4: team owner vs subscription customer mismatch (manual resolution)' );
		$teams = self::get_all_teams();

		$issue_count = 0;
		foreach ( $teams as $team ) {
			$member_id       = (int) get_post_meta( $team->ID, '_member_id', true );
			$subscription_id = (int) get_post_meta( $team->ID, '_subscription_id', true );
			if ( ! $member_id || ! $subscription_id ) {
				continue;
			}
			$customer_user = (int) get_post_meta( $subscription_id, '_customer_user', true );
			if ( ! $customer_user || $customer_user === $member_id ) {
				continue;
			}
			$issue_count++;
			$team_owner    = get_user_by( 'id', $member_id );
			$sub_customer  = get_user_by( 'id', $customer_user );
			WP_CLI::line(
				sprintf(
					'  ISSUE: team #%d owner=%d (%s) but subscription #%d customer=%d (%s)',
					$team->ID,
					$member_id,
					$team_owner ? $team_owner->user_email : '?',
					$subscription_id,
					$customer_user,
					$sub_customer ? $sub_customer->user_email : '?'
				)
			);
		}
		if ( 0 === $issue_count ) {
			WP_CLI::line( '  OK: no owner/customer mismatches.' );
		}
		self::$counts['Team/subscription owner mismatch'] = $issue_count;
	}

	/**
	 * Check 5: subscription line items with missing or stale team dispatch meta.
	 *
	 * These are the upstream cause of duplicate-team incidents. On renewal, SkyVerge
	 * Teams reads `_wc_memberships_for_teams_team_id` from the line item to decide
	 * which team to touch. Two ways this can go wrong:
	 *
	 * 1. Missing: an admin replaced the line item in wp-admin, stripping the meta.
	 *    Next renewal will fall through to the 'create' branch and spawn a duplicate.
	 * 2. Stale: after a duplicate team was created, Teams plugin wrote the duplicate's
	 *    id onto the item meta. The meta is "present" but points at the wrong team –
	 *    renewals will keep touching the orphaned duplicate instead of the original.
	 *
	 * @return void
	 */
	private static function check_subscription_line_items_missing_team_meta() {
		WP_CLI::line( 'Check 5: subscription line items with missing or stale team dispatch meta' );
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::warning( '  SKIP: wcs_get_subscription() unavailable.' );
			self::$counts['Subscription items with missing/stale team meta'] = 0;
			return;
		}

		$teams = self::get_all_teams();

		// Build a map of subscription_id -> team_id for every team that has one.
		$sub_to_team = [];
		foreach ( $teams as $team ) {
			$sub_id = (int) get_post_meta( $team->ID, '_subscription_id', true );
			if ( $sub_id ) {
				$sub_to_team[ $sub_id ] = (int) $team->ID;
			}
		}

		$issue_count = 0;
		foreach ( $sub_to_team as $subscription_id => $team_id ) {
			$subscription = wcs_get_subscription( $subscription_id );
			if ( ! $subscription ) {
				continue;
			}
			$team_product_id = (int) get_post_meta( $team_id, '_product_id', true );
			foreach ( $subscription->get_items() as $item ) {
				if ( (int) $item->get_product_id() !== $team_product_id ) {
					continue;
				}
				$item_team_id = (int) wc_get_order_item_meta( $item->get_id(), '_wc_memberships_for_teams_team_id', true );
				if ( $item_team_id === $team_id ) {
					continue;
				}

				$issue_count++;
				if ( 0 === $item_team_id ) {
					$problem = sprintf( 'missing team id (expected %d)', $team_id );
				} else {
					$problem = sprintf( 'stale team id %d (expected %d, the team actually linked to this subscription)', $item_team_id, $team_id );
				}
				WP_CLI::line(
					sprintf(
						'  ISSUE: subscription #%d item #%d %s',
						$subscription_id,
						$item->get_id(),
						$problem
					)
				);
				if ( self::$fix ) {
					self::ensure_fix_confirmed();
					WP_CLI::line( sprintf( '    SET item #%d _wc_memberships_for_teams_team_id = %d', $item->get_id(), $team_id ) );
					wc_update_order_item_meta( $item->get_id(), '_wc_memberships_for_teams_team_id', $team_id );
					wc_update_order_item_meta( $item->get_id(), '_wc_memberships_for_teams_team_renewal', true );
				}
			}
		}

		if ( 0 === $issue_count ) {
			WP_CLI::line( '  OK: all team-linked subscription items carry the correct dispatch meta.' );
		}
		self::$counts['Subscription items with missing/stale team meta'] = $issue_count;
	}

	/**
	 * Load all team posts, optionally scoped to --team-id.
	 *
	 * Cached per run – every check needs the same list, so re-querying five
	 * times is wasteful. Destructive fixes that mutate the team set call
	 * `invalidate_caches()` to force the next read to hit the DB.
	 *
	 * @return \WP_Post[]
	 */
	private static function get_all_teams() {
		if ( null !== self::$all_teams_cache ) {
			return self::$all_teams_cache;
		}
		$query_args = [
			'post_type'      => 'wc_memberships_team',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		];
		if ( self::$team_id ) {
			$query_args['p'] = self::$team_id;
		}
		self::$all_teams_cache = get_posts( $query_args );
		return self::$all_teams_cache;
	}

	/**
	 * Build (and cache) a map of team_id => list of memberships pointing at it.
	 *
	 * Single $wpdb query, run once per execution. Replaces N × unbounded
	 * `get_posts( meta_query=_team_id )` lookups in Check 1's relink path and
	 * Check 2's Candidate-1 lookup.
	 *
	 * @return array<int,array<int,array{membership_id:int,sub_id:?int}>>
	 */
	private static function get_team_memberships_map() {
		if ( null !== self::$team_memberships_map ) {
			return self::$team_memberships_map;
		}
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm_team.meta_value AS team_id, p.ID AS membership_id, pm_sub.meta_value AS sub_id
			FROM $wpdb->posts p
			JOIN $wpdb->postmeta pm_team ON pm_team.post_id = p.ID AND pm_team.meta_key = '_team_id'
			LEFT JOIN $wpdb->postmeta pm_sub ON pm_sub.post_id = p.ID AND pm_sub.meta_key = '_subscription_id'
			WHERE p.post_type = 'wc_user_membership'"
		);
		$map = [];
		foreach ( $rows as $row ) {
			$team_id = (int) $row->team_id;
			if ( ! isset( $map[ $team_id ] ) ) {
				$map[ $team_id ] = [];
			}
			$map[ $team_id ][] = [
				'membership_id' => (int) $row->membership_id,
				'sub_id'        => empty( $row->sub_id ) ? null : (int) $row->sub_id,
			];
		}
		self::$team_memberships_map = $map;
		return $map;
	}

	/**
	 * Drop cached lookups that may have been invalidated by a destructive fix.
	 *
	 * @return void
	 */
	private static function invalidate_caches() {
		self::$all_teams_cache      = null;
		self::$team_memberships_map = null;
	}

	/**
	 * Prompt for confirmation the first time a fix is about to be applied.
	 *
	 * Deferred (rather than upfront in `diagnostics()`) so that a `--fix` run
	 * which turns up zero issues exits cleanly without prompting.
	 *
	 * @return void
	 */
	private static function ensure_fix_confirmed() {
		if ( ! self::$needs_fix_confirmation || self::$skip_confirm ) {
			self::$needs_fix_confirmation = false;
			return;
		}
		WP_CLI::confirm( 'Apply fixes to the database? This will modify team, membership, subscription and order-item records.' );
		self::$needs_fix_confirmation = false;
	}
}
