<?php
/**
 * Fix WooCommerce Memberships CLI command.
 *
 * Reconciles `wc_user_membership` posts against the user's active subscriptions:
 * relinks orphaned memberships, reactivates expired/cancelled memberships whose
 * subscription is still active, creates missing memberships, and reclaims
 * network-managed memberships when a local subscription exists.
 *
 * Transplanted from newspack-subscription-migrations (#36).
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Fix WooCommerce Memberships CLI command.
 */
class Fix_Memberships {

	/**
	 * Whether to actually apply changes.
	 *
	 * @var bool
	 */
	private static $live = false;

	/**
	 * Whether to produce verbose output.
	 *
	 * @var bool
	 */
	private static $verbose = false;

	/**
	 * Accumulated result lines per outcome.
	 *
	 * @var array{skipped: string[], processed: string[]}
	 */
	private static $command_results = [
		'skipped'   => [],
		'processed' => [],
	];

	/**
	 * Inspect reader memberships and ensure they are associated with the correct active subscriptions.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply changes. Without this flag the command is a dry run.
	 *
	 * [--verbose]
	 * : Produce more output.
	 *
	 * [--limit=<n>]
	 * : Limit the number of records processed. Applies to both passes: at most <n>
	 * customers in the first pass, and at most <n> memberships in the stale end date pass.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack fix-memberships
	 *     wp newspack fix-memberships --live --verbose
	 *     wp newspack fix-memberships --limit=50
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		if ( ! function_exists( 'wc_memberships_get_membership_plan' ) ) {
			WP_CLI::error( 'WooCommerce Memberships plugin is not active.' );
		}
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions plugin is not active.' );
		}

		// Disable membership activation emails (we may flip many memberships back to active).
		add_filter( 'woocommerce_email_enabled_WC_Memberships_User_Membership_Activated_Email', '__return_false' );

		self::$live            = isset( $assoc_args['live'] );
		self::$verbose         = isset( $assoc_args['verbose'] );
		self::$command_results = [
			'skipped'   => [],
			'processed' => [],
		];

		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : false;

		WP_CLI::line( '' );
		if ( self::$live ) {
			WP_CLI::line( 'Live mode - data will be changed.' );
		} else {
			WP_CLI::line( 'Dry run. Use --live flag to run in live mode.' );
		}
		WP_CLI::line( '' );

		$plan_product_pairs = self::collect_plan_product_pairs();
		if ( empty( $plan_product_pairs ) ) {
			WP_CLI::warning( 'No products found linked to any membership plan.' );
			return;
		}

		$is_using_hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' );

		WP_CLI::line( $is_using_hpos ? 'Site is using HPOS.' : 'Site is not using HPOS.' );

		$affected_users = self::query_affected_users( $plan_product_pairs, $is_using_hpos );

		if ( false !== $limit ) {
			WP_CLI::warning( sprintf( 'Results limited to %d per pass.', $limit ) );
			$affected_users = array_slice( $affected_users, 0, $limit );
		}

		WP_CLI::line( sprintf( 'Will process %d customer/plan pairs.', count( $affected_users ) ) );
		WP_CLI::line( '' );

		$site_url = get_option( 'siteurl' );

		foreach ( $affected_users as $result ) {
			self::process_user( $result, $site_url );
		}

		self::fix_stale_end_dates( $is_using_hpos, $limit );
		self::print_summary();
	}

	/**
	 * Collect every (product, plan) pair that grants a membership, including team
	 * products and variations.
	 *
	 * Pairs rather than a product => plan map: the same product may grant more than
	 * one plan, and collapsing that would silently drop a plan from the reconciliation.
	 *
	 * @return array<int,array{product_id:int,plan_id:int}>
	 */
	private static function collect_plan_product_pairs() {
		$plans = get_posts(
			[
				'post_type'      => 'wc_membership_plan',
				'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Operator-run CLI command; unbounded by design.
			]
		);

		$parent_pairs = [];
		foreach ( $plans as $plan ) {
			// Regular plan products.
			$product_ids = get_post_meta( $plan->ID, '_product_ids', true );
			foreach ( is_array( $product_ids ) ? $product_ids : [] as $product_id ) {
				$parent_pairs[] = [
					'product_id' => (int) $product_id,
					'plan_id'    => (int) $plan->ID,
				];
			}

			// Team products (woocommerce-memberships-for-teams stores these via _wc_memberships_for_teams_plan on products).
			$team_products = get_posts(
				[
					'post_type'      => [ 'product', 'product_variation' ],
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page, WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Operator-run CLI command; unbounded by design.
					'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => '_wc_memberships_for_teams_plan',
							'value' => $plan->ID,
						],
					],
				]
			);
			foreach ( $team_products as $product_id ) {
				$parent_pairs[] = [
					'product_id' => (int) $product_id,
					'plan_id'    => (int) $plan->ID,
				];
			}
		}

		// Expand variable products to their variations, which grant the same plan.
		$variation_pairs = [];
		foreach ( $parent_pairs as $pair ) {
			$product = wc_get_product( $pair['product_id'] );
			if ( ! $product || ! ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) ) {
				continue;
			}
			foreach ( $product->get_children() as $variation_id ) {
				$variation_pairs[] = [
					'product_id' => (int) $variation_id,
					'plan_id'    => $pair['plan_id'],
				];
			}
		}

		$pairs  = array_merge( $parent_pairs, $variation_pairs );
		$unique = [];
		foreach ( $pairs as $pair ) {
			$unique[ $pair['product_id'] . ':' . $pair['plan_id'] ] = $pair;
		}
		return array_values( $unique );
	}

	/**
	 * Query customer/plan pairs where the customer has an active subscription granting
	 * the plan but no active membership for it.
	 *
	 * Grouping is per (customer, plan), not per customer: a reader with an active Plan A
	 * membership and a broken Plan B subscription must still surface for Plan B, and a
	 * customer with several broken subscriptions must not collapse into a single row.
	 * Memberships are keyed off `post_parent` (the plan) rather than `_product_id`, so a
	 * membership that lost its product link still counts against the right plan.
	 *
	 * Uses derived tables rather than CTEs: CTEs need MySQL 8.0 / MariaDB 10.2, and this
	 * command has to run on the oldest DB a WooCommerce site may still be on.
	 *
	 * @param array<int,array{product_id:int,plan_id:int}> $plan_product_pairs Product/plan pairs from collect_plan_product_pairs().
	 * @param bool                                         $is_using_hpos Whether HPOS is enabled.
	 * @return array<int,array<string,string|null>>
	 */
	private static function query_affected_users( $plan_product_pairs, $is_using_hpos ) {
		global $wpdb;

		// The ID lists below are GROUP_CONCAT-ed and parsed back in PHP, so a truncated
		// list would silently feed max() a bogus ID. Raise the cap for this connection.
		// On a multi-host DB layer (HyperDB/LudicrousDB) this SET SESSION and the SELECT
		// below may land on different physical read connections, making the bump a no-op;
		// it holds on Atomic's single DB, and with DISTINCT + per-(customer,plan) grouping
		// the concatenated lists are tiny anyway, so this is defensive rather than load-bearing.
		$wpdb->query( 'SET SESSION group_concat_max_len = 1000000' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Product => plan lookup as a derived table. All values are integer-cast. Only the
		// first SELECT of a UNION needs column aliases; the rest inherit them.
		$plan_product_rows = [];
		foreach ( array_values( $plan_product_pairs ) as $index => $pair ) {
			$plan_product_rows[] = 0 === $index
				? sprintf( 'SELECT %d AS product_id, %d AS plan_id', $pair['product_id'], $pair['plan_id'] )
				: sprintf( 'SELECT %d, %d', $pair['product_id'], $pair['plan_id'] );
		}
		$plan_products_query = implode( ' UNION ALL ', $plan_product_rows );

		if ( $is_using_hpos ) {
			$active_subscriptions_query = "
			SELECT subscriptions.customer_id AS customer_user_id,
				pp.plan_id AS plan_id,
				GROUP_CONCAT(DISTINCT subscriptions.id) AS subscription_ids
			FROM {$wpdb->prefix}wc_orders subscriptions
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = subscriptions.id
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
			INNER JOIN ( $plan_products_query ) pp ON pp.product_id = oim.meta_value
			WHERE subscriptions.type = 'shop_subscription'
			AND subscriptions.status = 'wc-active'
			GROUP BY subscriptions.customer_id, pp.plan_id
			";
		} else {
			$active_subscriptions_query = "
			SELECT pm.meta_value AS customer_user_id,
				pp.plan_id AS plan_id,
				GROUP_CONCAT(DISTINCT subscriptions.ID) AS subscription_ids
			FROM {$wpdb->prefix}posts subscriptions
			INNER JOIN {$wpdb->prefix}postmeta pm ON subscriptions.ID = pm.post_id AND pm.meta_key = '_customer_user'
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = subscriptions.ID
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
			INNER JOIN ( $plan_products_query ) pp ON pp.product_id = oim.meta_value
			WHERE subscriptions.post_type = 'shop_subscription'
			AND subscriptions.post_status = 'wc-active'
			GROUP BY pm.meta_value, pp.plan_id
			";
		}

		$active_memberships_query = "
		SELECT memberships.post_author AS customer_user_id,
			memberships.post_parent AS plan_id,
			COUNT(DISTINCT memberships.ID) AS active_memberships_count
		FROM {$wpdb->prefix}posts memberships
		WHERE memberships.post_type = 'wc_user_membership'
		AND memberships.post_status IN ('wcm-active', 'wcm-free_trial')
		GROUP BY memberships.post_author, memberships.post_parent
		";

		$inactive_memberships_query = "
		SELECT memberships.post_author AS customer_user_id,
			memberships.post_parent AS plan_id,
			GROUP_CONCAT(DISTINCT memberships.ID) AS membership_ids
		FROM {$wpdb->prefix}posts memberships
		WHERE memberships.post_type = 'wc_user_membership'
		AND memberships.post_status NOT IN ('wcm-active', 'wcm-free_trial', 'trash')
		GROUP BY memberships.post_author, memberships.post_parent
		";

		$sql_query = "
		SELECT s.customer_user_id,
			s.plan_id,
			s.subscription_ids,
			im.membership_ids
		FROM ( $active_subscriptions_query ) s
		LEFT JOIN ( $active_memberships_query ) m ON s.customer_user_id = m.customer_user_id AND s.plan_id = m.plan_id
		LEFT JOIN ( $inactive_memberships_query ) im ON s.customer_user_id = im.customer_user_id AND s.plan_id = im.plan_id
		WHERE COALESCE(m.active_memberships_count, 0) = 0;
		";

		return $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Process a single affected customer/plan row.
	 *
	 * @param array<string,string|null> $result Row from query_affected_users.
	 * @param string                    $site_url Site URL for log link generation.
	 * @return void
	 */
	private static function process_user( $result, $site_url ) {
		$subscription_ids = array_filter( explode( ',', $result['subscription_ids'] ?? '' ) );
		$membership_ids   = array_filter( explode( ',', $result['membership_ids'] ?? '' ) );

		$row_plan_id = isset( $result['plan_id'] ) ? (int) $result['plan_id'] : 0;
		$user_id     = $result['customer_user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			$log_line = sprintf( 'User #%d not found, skipping.', $user_id );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		// Separate local and network-managed memberships. Network-managed memberships
		// with a local subscription should be reclaimed, not skipped.
		$local_membership_ids   = [];
		$managed_membership_ids = [];
		foreach ( $membership_ids as $mid ) {
			if ( get_post_meta( (int) $mid, '_managed_by_newspack_network', true ) ) {
				$managed_membership_ids[] = $mid;
				if ( self::$verbose ) {
					WP_CLI::line( sprintf( '  Network-managed membership #%d found.', $mid ) );
				}
			} else {
				$local_membership_ids[] = $mid;
			}
		}
		$membership_ids = $local_membership_ids;

		if ( empty( $subscription_ids ) ) {
			$log_line = 'No subscription IDs, skipping.';
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		if ( self::$verbose ) {
			WP_CLI::line( sprintf( 'User: %s', $user->user_email ) );
			WP_CLI::line( sprintf( '    - memberships: %s/wp-admin/edit.php?s=%s&post_type=wc_user_membership', $site_url, $user->user_email ) );
			WP_CLI::line( sprintf( '    - subscriptions: %s/wp-admin/edit.php?s=%s&post_type=shop_subscription', $site_url, $user->user_email ) );
		}

		$subscription_ids              = array_map( 'intval', $subscription_ids );
		$latest_active_subscription_id = max( $subscription_ids );
		$latest_active_subscription    = wcs_get_subscription( $latest_active_subscription_id );

		// wcs_get_subscription() returns false for a subscription that no longer loads (deleted
		// post/order row, or a corrupted one). Every branch below calls methods on it.
		if ( ! $latest_active_subscription instanceof \WC_Subscription ) {
			$log_line = sprintf( 'Subscription (#%d) for user %s could not be loaded, skipping.', $latest_active_subscription_id, $user->user_email );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		// Detect transferred subscription: billing email belongs to a different WP user
		// who already has a membership linked to this subscription. Skip to avoid duplicates.
		if ( self::subscription_was_transferred( $latest_active_subscription, $latest_active_subscription_id, $user, $user_id ) ) {
			return;
		}

		// For team subscriptions, the owner may be a pure payer (no membership) – skip those,
		// but still process owners who DO have a membership for the plan.
		if ( self::is_skippable_team_payer( $latest_active_subscription, $latest_active_subscription_id, $user, $user_id, $membership_ids, $managed_membership_ids ) ) {
			return;
		}

		if ( empty( $membership_ids ) ) {
			self::handle_user_without_local_membership( $user, $user_id, $latest_active_subscription, $latest_active_subscription_id, $managed_membership_ids, $row_plan_id );
		} else {
			self::handle_user_with_inactive_membership( $user, $user_id, $latest_active_subscription, $latest_active_subscription_id, $membership_ids );
		}
		WP_CLI::line( '' );
	}

	/**
	 * Check if the subscription was transferred to a different user (billing email mismatch
	 * and the billing-email user already has a membership linked to the subscription).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $subscription_id Subscription ID.
	 * @param \WP_User         $user Current customer user.
	 * @param int              $user_id Current customer user ID.
	 * @return bool True if the subscription should be skipped as transferred.
	 */
	private static function subscription_was_transferred( $subscription, $subscription_id, $user, $user_id ) {
		global $wpdb;

		$billing_email = $subscription->get_billing_email();
		if ( ! $billing_email || strtolower( $billing_email ) === strtolower( $user->user_email ) ) {
			return false;
		}
		$billing_user = get_user_by( 'email', $billing_email );
		if ( ! $billing_user || (int) $billing_user->ID === (int) $user_id ) {
			return false;
		}
		$billing_user_membership = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->prefix}posts p
				INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_subscription_id'
				WHERE p.post_type = 'wc_user_membership' AND p.post_author = %d AND pm.meta_value = %s
				LIMIT 1",
				$billing_user->ID,
				$subscription_id
			)
		);
		if ( ! $billing_user_membership ) {
			return false;
		}
		$log_line = sprintf(
			'Subscription (#%d) was transferred to user %s (#%d), customer_id still points to %s (#%d). Skipping.',
			$subscription_id,
			$billing_email,
			$billing_user->ID,
			$user->user_email,
			$user_id
		);
		if ( self::$verbose ) {
			WP_CLI::warning( $log_line );
		}
		self::$command_results['skipped'][] = $log_line;
		return true;
	}

	/**
	 * Check whether this is a team subscription owned by someone with no membership
	 * for the plan (a pure payer).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $subscription_id Subscription ID.
	 * @param \WP_User         $user Customer user.
	 * @param int              $user_id Customer user ID.
	 * @param int[]            $membership_ids Local membership IDs.
	 * @param int[]            $managed_membership_ids Network-managed membership IDs.
	 * @return bool
	 */
	private static function is_skippable_team_payer( $subscription, $subscription_id, $user, $user_id, $membership_ids, $managed_membership_ids ) {
		$is_team_subscription = false;
		foreach ( $subscription->get_items() as $item ) {
			if ( wc_get_order_item_meta( $item->get_id(), '_wc_memberships_for_teams_team_id', true ) ) {
				$is_team_subscription = true;
				break;
			}
		}
		if ( ! $is_team_subscription || ! empty( $membership_ids ) || ! empty( $managed_membership_ids ) ) {
			return false;
		}

		// Also check by plan, in case the membership has no _product_id.
		foreach ( $subscription->get_items() as $item ) {
			$plan_id_for_product = self::get_plan_id_for_product( $item->get_product_id() );
			if ( $plan_id_for_product && wc_memberships_get_user_membership( $user_id, $plan_id_for_product ) ) {
				return false;
			}
		}

		$log_line = sprintf( 'Subscription (#%d) is a team subscription and user %s has no membership for the plan, skipping.', $subscription_id, $user->user_email );
		if ( self::$verbose ) {
			WP_CLI::line( $log_line );
		}
		self::$command_results['skipped'][] = $log_line;
		return true;
	}

	/**
	 * Handle a user that has no local (non-managed) membership: reclaim a managed one,
	 * relink an existing membership, or create a new one.
	 *
	 * @param \WP_User         $user User.
	 * @param int              $user_id User ID.
	 * @param \WC_Subscription $latest_active_subscription Latest active subscription.
	 * @param int              $latest_active_subscription_id Latest active subscription ID.
	 * @param int[]            $managed_membership_ids Network-managed membership IDs.
	 * @param int              $row_plan_id Plan ID the affected-users row was grouped by.
	 * @return void
	 */
	private static function handle_user_without_local_membership( $user, $user_id, $latest_active_subscription, $latest_active_subscription_id, $managed_membership_ids, $row_plan_id = 0 ) {
		// Reclaim a network-managed membership if one exists for this local subscription.
		if ( ! empty( $managed_membership_ids ) ) {
			$reclaim_id = null;
			foreach ( $managed_membership_ids as $mid ) {
				$sub_id = get_post_meta( (int) $mid, '_subscription_id', true );
				if ( $sub_id && (int) $sub_id === (int) $latest_active_subscription_id ) {
					$reclaim_id = $mid;
					break;
				}
			}
			if ( ! $reclaim_id ) {
				$reclaim_id = $managed_membership_ids[0];
			}

			// A reclaimed membership may have no local _product_id. Without it the membership
			// stays incompletely linked until a second run, so resolve it from the subscription.
			$reclaim_plan_id    = (int) get_post_field( 'post_parent', $reclaim_id );
			$reclaim_product_id = self::find_subscription_product_id_for_plan( $latest_active_subscription, $reclaim_plan_id );

			$log_line = sprintf( 'Reclaiming network-managed membership (#%d) for local subscription (#%d) for user %s.', $reclaim_id, $latest_active_subscription_id, $user->user_email );
			if ( self::$live ) {
				self::clear_network_managed_meta( (int) $reclaim_id );
				$membership = new \WC_Memberships_Integration_Subscriptions_User_Membership( $reclaim_id );
				$membership->set_subscription_id( $latest_active_subscription_id );
				$membership->set_order_id( $latest_active_subscription->get_parent_id() );
				if ( $reclaim_product_id ) {
					$membership->set_product_id( $reclaim_product_id );
				}
				if ( self::reactivate_if_inactive( $membership ) ) {
					$log_line .= ' Reactivated.';
				}
				WP_CLI::success( $log_line );
			} else {
				$membership = wc_memberships_get_user_membership( $reclaim_id );
				if ( $membership && ! self::is_active_status( $membership->get_status() ) ) {
					$log_line .= ' Would also reactivate.';
				}
				WP_CLI::line( $log_line );
			}
			self::$command_results['processed'][] = $log_line;
			return;
		}

		// Determine the membership plan from the subscription product.
		$subscription_product_ids = array_reduce(
			$latest_active_subscription->get_items(),
			function( $acc, $item ) {
				return array_merge( [ $item->get_product_id() ], $acc );
			},
			[]
		);
		// The affected-users row is already grouped by plan, so its plan ID is authoritative;
		// fall back to deriving it when the row did not carry one.
		$plan_id = $row_plan_id ? $row_plan_id : self::find_plan_id_for_subscription_products( $subscription_product_ids );

		if ( false === $plan_id ) {
			$log_line = sprintf( 'Could not determine plan id for subscription (#%d) items, skipping.', $latest_active_subscription_id );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		// The team-plan fallback reads the plan ID from product meta, which outlives a deleted
		// plan post, so the plan may not load even though we have an ID for it.
		$plan = wc_memberships_get_membership_plan( $plan_id );
		if ( ! $plan ) {
			$log_line = sprintf( 'Membership plan (#%d) for subscription (#%d) could not be loaded, skipping.', $plan_id, $latest_active_subscription_id );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		$plan_product_id_list = self::get_full_plan_product_id_list( $plan, $plan_id );
		$product_ids          = array_values( array_intersect( $subscription_product_ids, $plan_product_id_list ) );
		if ( empty( $product_ids ) ) {
			$log_line = sprintf( 'Could not determine product id for subscription (#%d) and plan (#%d), skipping.', $latest_active_subscription_id, $plan_id );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		$product_id = $product_ids[0];

		// Check if the subscription is linked to a membership that lost its _product_id meta.
		$linked = self::find_membership_linked_to_subscription( $latest_active_subscription_id, $user_id );
		if ( $linked ) {
			self::relink_existing_membership( $linked, $product_id, $latest_active_subscription_id );
			return;
		}

		// Check if the user already has a membership for this plan (e.g. network-managed without a product/sub link).
		$existing_plan_membership = wc_memberships_get_user_membership( $user_id, $plan_id );
		if ( $existing_plan_membership ) {
			self::reclaim_or_relink_plan_membership( $existing_plan_membership, $product_id, $latest_active_subscription, $latest_active_subscription_id, $plan_id, $user );
			return;
		}

		self::flag_zero_value_subscription( $latest_active_subscription, $user );
		self::create_membership( $user, $user_id, $plan_id, $product_id, $latest_active_subscription, $latest_active_subscription_id );
	}

	/**
	 * Handle a user that already has at least one inactive local membership: verify
	 * the membership's plan matches the subscription's product, then reactivate and relink.
	 *
	 * @param \WP_User         $user User.
	 * @param int              $user_id User ID.
	 * @param \WC_Subscription $latest_active_subscription Latest active subscription.
	 * @param int              $latest_active_subscription_id Latest active subscription ID.
	 * @param int[]            $membership_ids Local inactive membership IDs.
	 * @return void
	 */
	private static function handle_user_with_inactive_membership( $user, $user_id, $latest_active_subscription, $latest_active_subscription_id, $membership_ids ) {
		$membership_to_relink = null;
		foreach ( $membership_ids as $mid ) {
			$m_plan_id = get_post_field( 'post_parent', $mid );
			if ( ! $m_plan_id ) {
				continue;
			}
			foreach ( $latest_active_subscription->get_items() as $item ) {
				$sub_product_plan = self::get_plan_id_for_product( $item->get_product_id() );
				if ( $sub_product_plan && (int) $sub_product_plan === (int) $m_plan_id ) {
					$membership_to_relink = $mid;
					break 2;
				}
			}
		}

		if ( ! $membership_to_relink ) {
			$log_line = sprintf( 'No inactive membership matching subscription (#%d) plan for user %s, skipping.', $latest_active_subscription_id, $user->user_email );
			if ( self::$verbose ) {
				WP_CLI::warning( $log_line );
			}
			self::$command_results['skipped'][] = $log_line;
			return;
		}

		self::flag_zero_value_subscription( $latest_active_subscription, $user );

		$membership      = new \WC_Memberships_Integration_Subscriptions_User_Membership( $membership_to_relink );
		$previous_status = $membership->get_status();
		$log_line        = sprintf( 'Activated membership (#%d) and relinked to subscription (#%d) for user %s.', $membership->get_id(), $latest_active_subscription_id, $user->user_email );
		if ( self::$live ) {
			$membership->unschedule_expiration_events();
			$membership->set_order_id( $latest_active_subscription->get_parent_id() );
			$membership->set_subscription_id( $latest_active_subscription_id );
			$membership->set_end_date();
			$membership->update_status( 'active' );
			self::add_audit_note( $membership, sprintf( 'Reactivated and relinked to subscription #%d by `wp newspack fix-memberships` (was %s).', $latest_active_subscription_id, $previous_status ) );
			WP_CLI::success( $log_line );
		} else {
			WP_CLI::line( sprintf( 'In live mode, would activate membership (#%d) and relink to subscription (#%d) for user %s.', $membership->get_id(), $latest_active_subscription_id, $user->user_email ) );
		}
		self::$command_results['processed'][] = $log_line;
	}

	/**
	 * Look up the membership plan ID from a list of subscription product IDs (via plan _product_ids).
	 *
	 * @param int[] $subscription_product_ids Product IDs on the subscription items.
	 * @return int|false
	 */
	private static function find_plan_id_for_subscription_products( $subscription_product_ids ) {
		global $wpdb;

		$meta_value_sql = implode(
			' OR ',
			array_map(
				function( $id ) {
					return "pm.meta_value LIKE '%i:" . (int) $id . ";%'";
				},
				$subscription_product_ids
			)
		);
		$sql_query      = "
			SELECT p.ID AS membership_plan_id, p.post_title AS membership_plan_name
			FROM {$wpdb->prefix}posts p
			LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
			WHERE p.post_type = 'wc_membership_plan'
			AND pm.meta_key = '_product_ids'
			AND {$meta_value_sql}
		";
		$rows           = $wpdb->get_results( $sql_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan_id        = isset( $rows[0]['membership_plan_id'] ) ? (int) $rows[0]['membership_plan_id'] : false;

		// Fall back to team product meta on the subscription's products.
		if ( false === $plan_id ) {
			foreach ( $subscription_product_ids as $sub_product_id ) {
				$team_plan_id = get_post_meta( $sub_product_id, '_wc_memberships_for_teams_plan', true );
				if ( $team_plan_id ) {
					return (int) $team_plan_id;
				}
				$sub_product = wc_get_product( $sub_product_id );
				if ( $sub_product && $sub_product->get_parent_id() ) {
					$team_plan_id = get_post_meta( $sub_product->get_parent_id(), '_wc_memberships_for_teams_plan', true );
					if ( $team_plan_id ) {
						return (int) $team_plan_id;
					}
				}
			}
		}

		return $plan_id;
	}

	/**
	 * Get the full set of product IDs that grant access to a plan, including team products and variations.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Plan.
	 * @param int                             $plan_id Plan ID.
	 * @return int[]
	 */
	private static function get_full_plan_product_id_list( $plan, $plan_id ) {
		$plan_product_id_list = $plan->get_product_ids();
		if ( function_exists( 'wc_memberships_for_teams' ) ) {
			$team_ids             = wc_memberships_for_teams()->get_membership_plans_instance()->get_membership_plan_team_product_ids( $plan_id );
			$plan_product_id_list = array_merge( $plan_product_id_list, $team_ids );
		}
		$plan_variation_ids = [];
		foreach ( $plan_product_id_list as $ppid ) {
			$pp = wc_get_product( $ppid );
			if ( $pp && ( $pp->is_type( 'variable' ) || $pp->is_type( 'variable-subscription' ) ) ) {
				$plan_variation_ids = array_merge( $plan_variation_ids, $pp->get_children() );
			}
		}
		return array_unique( array_merge( $plan_product_id_list, $plan_variation_ids ) );
	}

	/**
	 * Find a membership linked to a subscription via _subscription_id meta, owned by the given user.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $user_id Owner user ID.
	 * @return array<string,mixed>|null Row with membership_id, post_author, membership_name, or null.
	 */
	private static function find_membership_linked_to_subscription( $subscription_id, $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID AS membership_id, p.post_author, p.post_title AS membership_name
				FROM {$wpdb->prefix}posts p
				LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
				WHERE p.post_type = 'wc_user_membership'
				  AND pm.meta_key = '_subscription_id'
				  AND pm.meta_value = %d",
				(int) $subscription_id
			),
			ARRAY_A
		);

		// Filter to memberships owned by this user (team subscriptions link to other users' memberships).
		$rows = array_values(
			array_filter(
				$rows,
				function( $row ) use ( $user_id ) {
					return (int) $row['post_author'] === (int) $user_id;
				}
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Relink an existing subscription-linked membership: set its product, strip stale
	 * network-managed meta if present, and reactivate if needed.
	 *
	 * @param array<string,mixed> $linked Row from find_membership_linked_to_subscription.
	 * @param int                 $product_id Product ID.
	 * @param int                 $subscription_id Subscription ID.
	 * @return void
	 */
	private static function relink_existing_membership( $linked, $product_id, $subscription_id ) {
		$found_membership_id = (int) $linked['membership_id'];
		$membership          = new \WC_Memberships_Integration_Subscriptions_User_Membership( $found_membership_id );

		if ( get_post_meta( $found_membership_id, '_managed_by_newspack_network', true ) ) {
			$log_line = sprintf( 'Membership (#%d) is incorrectly marked as network-managed but has local subscription (#%d), removing network meta.', $found_membership_id, $subscription_id );
			if ( self::$live ) {
				self::clear_network_managed_meta( $found_membership_id );
				WP_CLI::success( $log_line );
			} else {
				WP_CLI::line( $log_line );
			}
			self::$command_results['processed'][] = $log_line;
		}

		$log_line = sprintf( 'Latest subscription (#%d) is linked to a membership (#%d), setting product ID to %d.', $subscription_id, $found_membership_id, $product_id );
		if ( self::$live ) {
			$membership->set_product_id( $product_id );
			if ( self::reactivate_if_inactive( $membership ) ) {
				$log_line .= ' Reactivated membership.';
			}
			WP_CLI::success( $log_line );
		} else {
			if ( ! self::is_active_status( $membership->get_status() ) ) {
				$log_line .= ' Would also reactivate membership.';
			}
			WP_CLI::line( $log_line );
		}
		self::$command_results['processed'][] = $log_line;
	}

	/**
	 * Reclaim or relink an existing plan-level membership (the user already has a
	 * membership for this plan but it isn't linked to the active subscription).
	 *
	 * @param \WC_Memberships_User_Membership $existing_plan_membership Existing membership.
	 * @param int                             $product_id Product ID.
	 * @param \WC_Subscription                $subscription Subscription.
	 * @param int                             $subscription_id Subscription ID.
	 * @param int                             $plan_id Plan ID.
	 * @param \WP_User                        $user User.
	 * @return void
	 */
	private static function reclaim_or_relink_plan_membership( $existing_plan_membership, $product_id, $subscription, $subscription_id, $plan_id, $user ) {
		$existing_id        = $existing_plan_membership->get_id();
		$is_network_managed = (bool) get_post_meta( $existing_id, '_managed_by_newspack_network', true );

		$log_line = $is_network_managed
			? sprintf( 'Reclaiming network-managed membership (#%d) for local subscription (#%d) for user %s.', $existing_id, $subscription_id, $user->user_email )
			: sprintf( 'Found existing membership (#%d) for plan (#%d), relinking to subscription (#%d) for user %s.', $existing_id, $plan_id, $subscription_id, $user->user_email );

		if ( self::$live ) {
			if ( $is_network_managed ) {
				self::clear_network_managed_meta( $existing_id );
			}
			$membership = new \WC_Memberships_Integration_Subscriptions_User_Membership( $existing_id );
			$membership->set_product_id( $product_id );
			$membership->set_subscription_id( $subscription_id );
			$membership->set_order_id( $subscription->get_parent_id() );
			if ( self::reactivate_if_inactive( $membership ) ) {
				$log_line .= ' Reactivated.';
			}
			WP_CLI::success( $log_line );
		} else {
			if ( ! self::is_active_status( $existing_plan_membership->get_status() ) ) {
				$log_line .= ' Would also reactivate.';
			}
			WP_CLI::line( $log_line );
		}
		self::$command_results['processed'][] = $log_line;
	}

	/**
	 * Create a brand-new membership for the user/plan/subscription combination.
	 *
	 * @param \WP_User         $user User.
	 * @param int              $user_id User ID.
	 * @param int              $plan_id Plan ID.
	 * @param int              $product_id Product ID.
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $subscription_id Subscription ID.
	 * @return void
	 */
	private static function create_membership( $user, $user_id, $plan_id, $product_id, $subscription, $subscription_id ) {
		if ( ! self::$live ) {
			WP_CLI::line( sprintf( 'In live mode, would create a membership for user %s.', $user->user_email ) );
			self::$command_results['processed'][] = sprintf( 'Would create a membership for user %s.', $user->user_email );
			return;
		}

		try {
			$membership = wc_memberships_create_user_membership(
				[
					'user_id'    => $user_id,
					'plan_id'    => $plan_id,
					'product_id' => $product_id,
					'order_id'   => $subscription->get_parent_id(),
				]
			);
			$membership = new \WC_Memberships_Integration_Subscriptions_User_Membership( $membership->get_id() );
			$membership->set_start_date( $subscription->get_date( 'start' ) );
			$membership->set_subscription_id( $subscription_id );
			WP_CLI::success( sprintf( 'Created a membership (#%d) for user %s.', $membership->get_id(), $user->user_email ) );
			self::$command_results['processed'][] = sprintf( 'Created a membership for user %s.', $user->user_email );
		} catch ( \Throwable $th ) {
			$log_line = sprintf( 'Could not create a membership for user %s: %s', $user->user_email, $th->getMessage() );
			WP_CLI::warning( $log_line );
			self::$command_results['skipped'][] = $log_line;
		}
	}

	/**
	 * Second pass: clear stale _end_date on memberships whose linked subscription is active.
	 * For subscription-linked memberships the subscription status drives expiration; a stale
	 * _end_date causes the membership to re-expire via cron.
	 *
	 * @param bool      $is_using_hpos Whether HPOS is enabled.
	 * @param int|false $limit Maximum number of memberships to touch, or false for no limit.
	 * @return void
	 */
	private static function fix_stale_end_dates( $is_using_hpos, $limit = false ) {
		global $wpdb;

		$sub_join = $is_using_hpos
			? "INNER JOIN {$wpdb->prefix}wc_orders sub ON sub.id = pm_sub.meta_value AND sub.type = 'shop_subscription' AND sub.status = 'wc-active'"
			: "INNER JOIN {$wpdb->prefix}posts sub ON sub.ID = pm_sub.meta_value AND sub.post_type = 'shop_subscription' AND sub.post_status = 'wc-active'";

		// Interpolated $wpdb->users and $wpdb->prefix, plus an integer-only LIMIT clause; no
		// user input reaches the SQL, so prepare() is not applicable here.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		$stale_end_dates_from = "FROM {$wpdb->prefix}posts p
			INNER JOIN {$wpdb->users} u ON p.post_author = u.ID
			INNER JOIN {$wpdb->prefix}postmeta pm_sub ON p.ID = pm_sub.post_id AND pm_sub.meta_key = '_subscription_id'
			INNER JOIN {$wpdb->prefix}postmeta pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '_end_date'
			$sub_join
			WHERE p.post_type = 'wc_user_membership'
			AND p.post_status != 'trash'
			AND pm_sub.meta_value != ''
			AND pm_end.meta_value != ''";

		// Bound the rows the query materialises so --limit caps query cost and peak memory on
		// large sites, not just the number of mutations. $limit is already an int (or false).
		$limit_clause = ( false !== $limit ) ? ' LIMIT ' . (int) $limit : '';

		$stale_end_dates = $wpdb->get_results(
			"SELECT p.ID as membership_id, p.post_status as membership_status,
					u.user_email,
					pm_sub.meta_value as subscription_id,
					pm_end.meta_value as end_date
				$stale_end_dates_from$limit_clause",
			ARRAY_A
		);

		if ( empty( $stale_end_dates ) ) {
			return;
		}

		// When limited we only fetched a slice, so COUNT the full set separately to still report
		// the true scope (a canary run wants to see how many memberships it left untouched).
		$found_count = ( false !== $limit )
			? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) $stale_end_dates_from" )
			: count( $stale_end_dates );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users

		WP_CLI::line(
			sprintf(
				'Found %d subscription-linked memberships with stale end dates (subscription active), processing %d.',
				$found_count,
				count( $stale_end_dates )
			)
		);
		foreach ( $stale_end_dates as $row ) {
			$membership_id      = (int) $row['membership_id'];
			$membership         = new \WC_Memberships_Integration_Subscriptions_User_Membership( $membership_id );
			$log_line           = sprintf(
				'Membership #%d (%s, %s): clearing end_date %s (subscription #%s).',
				$membership_id,
				$row['user_email'],
				$row['membership_status'],
				$row['end_date'],
				$row['subscription_id']
			);
			$needs_reactivation = ! in_array( $row['membership_status'], [ 'wcm-active', 'wcm-free_trial' ], true );
			if ( $needs_reactivation ) {
				$log_line .= ' Reactivating.';
			}
			if ( self::$live ) {
				$membership->unschedule_expiration_events();
				$membership->set_end_date();
				if ( $needs_reactivation ) {
					$membership->update_status( 'active' );
					self::add_audit_note( $membership, sprintf( 'Reactivated by `wp newspack fix-memberships`: subscription #%s is active.', $row['subscription_id'] ) );
				}
				WP_CLI::success( $log_line );
			} else {
				WP_CLI::line( $log_line );
			}
			self::$command_results['processed'][] = $log_line;
		}
		WP_CLI::line( '' );
	}

	/**
	 * Find the product on a subscription that grants the given membership plan.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param int              $plan_id Plan ID.
	 * @return int|false Product ID, or false if no item on the subscription grants the plan.
	 */
	private static function find_subscription_product_id_for_plan( $subscription, $plan_id ) {
		if ( ! $plan_id ) {
			return false;
		}
		foreach ( $subscription->get_items() as $item ) {
			$product_id      = $item->get_product_id();
			$product_plan_id = self::get_plan_id_for_product( $product_id );
			if ( $product_plan_id && (int) $product_plan_id === (int) $plan_id ) {
				return (int) $product_id;
			}
		}
		return false;
	}

	/**
	 * Find the membership plan ID for a given product (or its parent, for variations).
	 * Checks both _product_ids on plans and _wc_memberships_for_teams_plan on products.
	 *
	 * @param int $product_id The product ID.
	 * @return int|false The plan ID, or false if not found.
	 */
	private static function get_plan_id_for_product( $product_id ) {
		global $wpdb;

		// Regular plan _product_ids (serialized array).
		$plan_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->prefix}posts p
				INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
				WHERE p.post_type = 'wc_membership_plan' AND pm.meta_key = '_product_ids'
				AND pm.meta_value LIKE %s LIMIT 1",
				'%i:' . (int) $product_id . ';%'
			)
		);
		if ( $plan_id ) {
			return (int) $plan_id;
		}

		// Team product meta.
		$team_plan_id = get_post_meta( $product_id, '_wc_memberships_for_teams_plan', true );
		if ( $team_plan_id ) {
			return (int) $team_plan_id;
		}

		// Parent product for variations.
		$product = wc_get_product( $product_id );
		if ( $product && $product->get_parent_id() ) {
			$team_plan_id = get_post_meta( $product->get_parent_id(), '_wc_memberships_for_teams_plan', true );
			if ( $team_plan_id ) {
				return (int) $team_plan_id;
			}
		}

		return false;
	}

	/**
	 * Reactivate a membership in-place if it is currently inactive: clears the
	 * expiration schedule and end date, then sets status to active.
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 * @return bool True if the membership was reactivated.
	 */
	private static function reactivate_if_inactive( $membership ) {
		$previous_status = $membership->get_status();
		if ( self::is_active_status( $previous_status ) ) {
			return false;
		}
		$membership->unschedule_expiration_events();
		$membership->set_end_date();
		$membership->update_status( 'active' );
		self::add_audit_note( $membership, sprintf( 'Reactivated by `wp newspack fix-memberships` (was %s): a linked subscription is active.', $previous_status ) );
		return true;
	}

	/**
	 * Record a membership note describing a change this command made.
	 *
	 * Reactivation emails are suppressed for the duration of the run, so without a note
	 * the only record of a status change is the command's stdout. The note puts it on the
	 * membership itself, where support staff reviewing the account will find it.
	 *
	 * @param \WC_Memberships_User_Membership $membership Membership.
	 * @param string                          $note Note text.
	 * @return void
	 */
	private static function add_audit_note( $membership, $note ) {
		if ( method_exists( $membership, 'add_note' ) ) {
			$membership->add_note( $note );
		}
	}

	/**
	 * Whether a membership status represents an active membership.
	 *
	 * @param string $status Membership status (without the wcm- prefix).
	 * @return bool
	 */
	private static function is_active_status( $status ) {
		return in_array( $status, [ 'active', 'free_trial' ], true );
	}

	/**
	 * Strip Newspack Network managed meta from a membership.
	 *
	 * @param int $membership_id Membership ID.
	 * @return void
	 */
	private static function clear_network_managed_meta( $membership_id ) {
		delete_post_meta( $membership_id, '_managed_by_newspack_network' );
		delete_post_meta( $membership_id, '_remote_id' );
		delete_post_meta( $membership_id, '_remote_site_url' );
	}

	/**
	 * Warn about a zero-value subscription, which is usually a data issue worth a human look.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param \WP_User         $user User.
	 * @return void
	 */
	private static function flag_zero_value_subscription( $subscription, $user ) {
		if ( 0.0 === (float) $subscription->get_total() ) {
			WP_CLI::warning( sprintf( 'Latest subscription (#%d) total for user %s is 0.', $subscription->get_id(), $user->user_email ) );
			WP_CLI::line( '' );
		}
	}

	/**
	 * Print the final summary of skipped and processed items.
	 *
	 * @return void
	 */
	private static function print_summary() {
		WP_CLI::line( '' );
		WP_CLI::line( 'Done, here are the results:' );
		if ( ! empty( self::$command_results['skipped'] ) ) {
			WP_CLI::line( sprintf( 'Skipped %d:', count( self::$command_results['skipped'] ) ) );
			foreach ( self::$command_results['skipped'] as $line ) {
				WP_CLI::line( '    ' . $line );
			}
		}
		if ( ! empty( self::$command_results['processed'] ) ) {
			WP_CLI::line(
				self::$live
					? sprintf( 'Processed %d:', count( self::$command_results['processed'] ) )
					: sprintf( 'Would process %d:', count( self::$command_results['processed'] ) )
			);
			foreach ( self::$command_results['processed'] as $line ) {
				WP_CLI::line( '    ' . $line );
			}
		}
		WP_CLI::line( '' );
	}
}
