<?php
/**
 * Migrates WooCommerce Memberships product restrictions to subscriber-only products.
 *
 * Ported shape: a Memberships product restriction lets members of one plan buy
 * the products it names; a subscriber-only restriction lets holders of the
 * subscription products that granted that plan buy them. One rule is written
 * per plan, so plans keep their separate audiences.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Commerce;
use Newspack\Subscriber_Only_Products;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Memberships product restriction migration.
 */
class Product_Restrictions_Migration {

	/**
	 * Option holding every WooCommerce Memberships rule.
	 */
	const MEMBERSHIPS_RULES_OPTION = 'wc_memberships_rules';

	/**
	 * Per-post flag exempting content, products included, from every Memberships rule.
	 */
	const FORCE_PUBLIC_META_KEY = '_wc_memberships_force_public';

	/**
	 * Memberships' store-level switch hiding view-restricted products from the catalog.
	 */
	const HIDE_RESTRICTED_PRODUCTS_OPTION = 'wc_memberships_hide_restricted_products';

	/**
	 * The only product taxonomy a subscriber-only restriction can target.
	 */
	const SUPPORTED_TAXONOMY = 'product_cat';

	/**
	 * Prefix marking a restriction as written by this command, and so as one a
	 * re-run may update or remove.
	 */
	const MIGRATED_RULE_ID_PREFIX = 'wcm-pr-';

	/**
	 * Migrate WooCommerce Memberships product restrictions to Access Control
	 * subscriber-only products.
	 *
	 * Writes one restriction per membership plan, sold to the subscription
	 * products that grant that plan. Dry-run by default; pass --live to write.
	 * Re-running brings the restrictions it wrote back in line with Memberships:
	 * it updates them in place, removes any whose source rule is gone, and keeps
	 * a restriction paused if the publisher paused it.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-product-restrictions
	 *     wp newspack migrate-product-restrictions --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate_product_restrictions( array $args, array $assoc_args ): void {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		$memberships_rules = get_option( self::MEMBERSHIPS_RULES_OPTION, [] );
		$mapped            = self::map_rules(
			is_array( $memberships_rules ) ? $memberships_rules : [],
			[ Discounts_Migration::class, 'get_plan_subscription_product_ids' ],
			self::get_force_public_product_ids(),
			function ( int $plan_id ): bool {
				return 'publish' === get_post_status( $plan_id );
			}
		);
		$stale_rule_ids    = self::get_stale_rule_ids( $mapped['rules'], $mapped['still_enforced_plan_ids'] );

		if ( empty( $mapped['rules'] ) && empty( $mapped['skipped'] ) && empty( $stale_rule_ids ) ) {
			WP_CLI::success( 'No WooCommerce Memberships product restrictions found. Nothing to migrate.' );
			return;
		}

		$summary = [];
		foreach ( $mapped['rules'] as $rule ) {
			$existing_rule = Subscriber_Only_Products::get_rule( $rule['id'] );
			$exists        = null !== $existing_rule;
			if ( $exists ) {
				// Memberships has no pause for this rule type, so a pause can only
				// be the publisher's own, made after the first run. Keep it.
				$rule['active'] = $existing_rule['active'];
			}
			if ( ! $exists ) {
				$result = $dry_run ? 'would create' : 'created';
			} elseif ( self::without_created_at( $existing_rule ) === self::without_created_at( Subscriber_Commerce::sanitize_base_rule( $rule ) ) ) {
				$result = 'unchanged';
			} else {
				$result = $dry_run ? 'would update' : 'updated';
			}

			$summary[] = [
				'plan'     => self::describe_plan( $rule['_source_plan_id'] ),
				'products' => self::describe_targeting( $rule ),
				'audience' => implode( ', ', $rule['subscription_product_ids'] ),
				'result'   => $result,
			];

			if ( ! $dry_run ) {
				unset( $rule['_source_plan_id'] );
				Subscriber_Only_Products::save_rule( $rule );
			}
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		if ( ! empty( $summary ) ) {
			\WP_CLI\Utils\format_items( 'table', $summary, [ 'plan', 'products', 'audience', 'result' ] );
		}

		if ( ! empty( $mapped['skipped'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== SKIPPED — %d total ===', count( $mapped['skipped'] ) ) );
			\WP_CLI\Utils\format_items( 'table', $mapped['skipped'], [ 'source', 'plan', 'reason' ] );
		}

		// A restriction written on an earlier run whose source rule has since
		// been deleted or retargeted, or whose plan was unpublished, would
		// otherwise stay enforced and block purchases Memberships now allows.
		foreach ( $stale_rule_ids as $stale_rule_id ) {
			WP_CLI::line( sprintf( '%1$s restriction %2$s: its Memberships rule no longer maps to it.', $dry_run ? 'Would remove' : 'Removed', $stale_rule_id ) );
			if ( ! $dry_run ) {
				Subscriber_Only_Products::delete_rule( $stale_rule_id );
			}
		}

		self::report_hidden( $mapped['hidden'] );

		foreach ( $mapped['view_only_buyers'] as $view_only_buyer ) {
			WP_CLI::warning(
				sprintf(
					'Product %1$d "%2$s": members of %3$s could see it but not buy it in Memberships. After the flip their subscribers can buy it.',
					$view_only_buyer['product_id'],
					get_the_title( $view_only_buyer['product_id'] ),
					implode( ', ', array_map( [ __CLASS__, 'describe_plan' ], $view_only_buyer['plans'] ) )
				)
			);
		}

		foreach ( $mapped['unchecked_view_overlaps'] as $view_overlap ) {
			WP_CLI::warning(
				sprintf(
					'Memberships rule %1$s let members of %2$s see products without buying them, and may overlap another plan\'s purchase rule. Check by hand: after the flip those subscribers can buy every product it covers.',
					$view_overlap['source'],
					self::describe_plan( $view_overlap['plan'] )
				)
			);
		}

		foreach ( $mapped['delayed'] as $delayed_rule ) {
			WP_CLI::warning(
				sprintf(
					'Memberships rule %1$s let members of %2$s buy only after %3$s. Access Control lets subscribers buy straight away.',
					$delayed_rule['source'],
					self::describe_plan( $delayed_rule['plan'] ),
					$delayed_rule['schedule']
				)
			);
		}

		$unchanged_count = count( wp_list_filter( $summary, [ 'result' => 'unchanged' ] ) );
		WP_CLI::success(
			sprintf(
				'Done. %1$d restriction(s) %2$s, %3$d unchanged, %4$d removed, %5$d Memberships rule(s) skipped.',
				count( $summary ) - $unchanged_count,
				$dry_run ? 'would be written' : 'written',
				$unchanged_count,
				count( $stale_rule_ids ),
				count( $mapped['skipped'] )
			)
		);

		if ( ! empty( $mapped['skipped'] ) ) {
			WP_CLI::warning( 'Skipped rules need a decision before the site is flipped — see the table above.' );
		}
	}

	/**
	 * Warn about each product a Memberships rule hid from non-members.
	 *
	 * A "view" restriction hides the product as well as blocking its purchase.
	 * Subscriber-only products block the purchase and nothing else, so these
	 * products become visible to every reader after the flip.
	 *
	 * @param array[] $hidden Hidden entries from map_rules().
	 */
	private static function report_hidden( array $hidden ): void {
		if ( empty( $hidden ) ) {
			return;
		}

		WP_CLI::line( '' );
		foreach ( $hidden as $entry ) {
			switch ( $entry['kind'] ) {
				case Product_Targeting::TARGETING_ALL:
					$what = 'Every product';
					break;
				case Product_Targeting::TARGETING_CATEGORY:
					$term = get_term( $entry['object_id'], self::SUPPORTED_TAXONOMY );
					$what = sprintf( 'Every product in category %d "%s"', $entry['object_id'], $term && ! is_wp_error( $term ) ? $term->name : '' );
					break;
				default:
					$what = sprintf( 'Product %d "%s"', $entry['object_id'], get_the_title( $entry['object_id'] ) );
			}
			WP_CLI::warning(
				sprintf(
					'%1$s is hidden from non-members of %2$s in Memberships. Access Control only blocks its purchase, so it will be visible to everyone.',
					$what,
					self::describe_plan( $entry['plan'] )
				)
			);
		}

		if ( 'yes' === get_option( self::HIDE_RESTRICTED_PRODUCTS_OPTION, 'no' ) ) {
			WP_CLI::line( 'Memberships also hides these products from the shop catalog. Access Control\'s "Hide from product lists" setting does that for every subscriber-only product, not only these.' );
		}
	}

	/**
	 * Convert WooCommerce Memberships rules into subscriber-only product restrictions.
	 *
	 * Pure apart from the injected plan resolver, so the mapping can be tested
	 * without WP-CLI.
	 *
	 * @param array         $memberships_rules        Raw `wc_memberships_rules` option value.
	 * @param callable      $plan_products_getter     Given a plan id, returns the subscription product ids that grant it.
	 * @param int[]         $force_public_product_ids Products Memberships never restricts.
	 * @param callable|null $plan_is_published        Given a plan id, whether it is published. Null treats every plan as published.
	 * @return array {
	 *     @type array[] $rules   Restrictions ready for the store, one per plan and targeting.
	 *     @type array[] $skipped Memberships rules that need a human decision, with a reason.
	 *     @type array[] $hidden  What view-restricted rules hid from non-members.
	 *     @type array[] $delayed Rules that granted purchase only some time after joining.
	 *     @type array[] $view_only_buyers Named products a view-only plan could see but not buy.
	 *     @type array[] $unchecked_view_overlaps View rules that may overlap another plan's purchase rule by category or catalog.
	 *     @type int[]   $still_enforced_plan_ids Plans skipped here whose rules Memberships still enforces.
	 * }
	 */
	public static function map_rules( array $memberships_rules, callable $plan_products_getter, array $force_public_product_ids = [], ?callable $plan_is_published = null ): array {
		$force_public_product_ids = array_map( 'intval', $force_public_product_ids );

		$grouped = [];
		$skipped = [];
		$hidden  = [];
		$delayed = [];
		// Plans per named product and access type, to find view-only plans
		// that gain purchase rights; see the end of this method.
		$plans_by_product_access = [];
		$still_enforced_plan_ids = [];
		$view_scopes             = [];
		$purchase_scopes         = [];

		foreach ( $memberships_rules as $memberships_rule ) {
			if ( ! is_array( $memberships_rule ) || 'product_restriction' !== ( $memberships_rule['rule_type'] ?? '' ) ) {
				continue;
			}

			$source_id = $memberships_rule['id'] ?? '(no id)';
			$plan_id   = (int) ( $memberships_rule['membership_plan_id'] ?? 0 );
			$skip      = function ( string $reason ) use ( &$skipped, $source_id, $plan_id ): void {
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id ? $plan_id : '(none)',
					'reason' => $reason,
				];
			};

			// Memberships stores no `active` flag on product restrictions; it
			// applies a plan's rules only while the plan is published.
			if ( $plan_is_published && ! call_user_func( $plan_is_published, $plan_id ) ) {
				$skip( 'Plan is not published, so Memberships does not apply this rule.' );
				continue;
			}

			$subscription_product_ids = $plan_id ? array_map( 'intval', (array) call_user_func( $plan_products_getter, $plan_id ) ) : [];
			if ( empty( $subscription_product_ids ) ) {
				// A restriction naming no subscription is skipped by the store as
				// unbuyable-by-everyone, so the rule would migrate and do nothing.
				$skip( 'Plan grants access without a subscription product — pick the subscription(s) by hand.' );
				// Memberships still enforces this rule, so a restriction written
				// for the plan on an earlier run must stay until someone decides.
				$still_enforced_plan_ids[] = $plan_id;
				continue;
			}

			$object_ids  = array_values( array_filter( array_map( 'absint', (array) ( $memberships_rule['object_ids'] ?? [] ) ) ) );
			$is_taxonomy = 'taxonomy' === ( $memberships_rule['content_type'] ?? '' );

			if ( $is_taxonomy && self::SUPPORTED_TAXONOMY !== ( $memberships_rule['content_type_name'] ?? '' ) ) {
				$skip(
					sprintf(
						'Targets the "%s" taxonomy, which subscriber-only products cannot express — re-target it by product or category.',
						(string) ( $memberships_rule['content_type_name'] ?? 'unknown' )
					)
				);
				continue;
			}

			if ( empty( $object_ids ) ) {
				$targeting = Product_Targeting::TARGETING_ALL;
			} elseif ( $is_taxonomy ) {
				$targeting = Product_Targeting::TARGETING_CATEGORY;
			} else {
				$targeting = Product_Targeting::TARGETING_PRODUCTS;
				// Memberships checks the force-public flag before any rule, so a
				// flagged product stays buyable even where a rule names it.
				$object_ids = array_values( array_diff( $object_ids, $force_public_product_ids ) );
				if ( empty( $object_ids ) ) {
					$skip( 'Every product this rule names has restrictions disabled in Memberships, so the rule restricts nothing.' );
					continue;
				}
			}

			$key = $plan_id . '|' . $targeting;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = [
					'_source_plan_id'          => $plan_id,
					// Derived from the plan and targeting so a re-run updates the
					// same restriction in place instead of adding a copy.
					'id'                       => self::migrated_rule_id( $plan_id, $targeting ),
					'subscription_product_ids' => $subscription_product_ids,
					'targeting'                => $targeting,
					'product_ids'              => [],
					'category_ids'             => [],
					// Named products had the flagged ones removed above; a category
					// or catalog-wide rule carries them as exclusions instead.
					'excluded_product_ids'     => Product_Targeting::TARGETING_PRODUCTS === $targeting ? [] : $force_public_product_ids,
					'active'                   => true,
				];
			}
			if ( Product_Targeting::TARGETING_PRODUCTS === $targeting ) {
				$grouped[ $key ]['product_ids'] = array_values( array_unique( array_merge( $grouped[ $key ]['product_ids'], $object_ids ) ) );
				$access_type                    = 'view' === ( $memberships_rule['access_type'] ?? '' ) ? 'view' : 'purchase';
				foreach ( $object_ids as $object_id ) {
					$plans_by_product_access[ $object_id ][ $access_type ][] = $plan_id;
				}
			} elseif ( Product_Targeting::TARGETING_CATEGORY === $targeting ) {
				$grouped[ $key ]['category_ids'] = array_values( array_unique( array_merge( $grouped[ $key ]['category_ids'], $object_ids ) ) );
			}

			// Subscriber-only products grant purchase from the first day, so a
			// member who had to wait under Memberships can buy straight away.
			$access_schedule = (string) ( $memberships_rule['access_schedule'] ?? 'immediate' );
			if ( '' !== $access_schedule && 'immediate' !== $access_schedule ) {
				$delayed[] = [
					'source'   => $source_id,
					'plan'     => $plan_id,
					'schedule' => $access_schedule,
				];
			}

			$scope = [
				'source'    => $source_id,
				'plan'      => $plan_id,
				'targeting' => $targeting,
			];
			if ( 'view' === ( $memberships_rule['access_type'] ?? '' ) ) {
				$view_scopes[] = $scope;
			} else {
				$purchase_scopes[] = $scope;
			}

			if ( 'view' === ( $memberships_rule['access_type'] ?? '' ) ) {
				foreach ( Product_Targeting::TARGETING_ALL === $targeting ? [ 0 ] : $object_ids as $object_id ) {
					$hidden[] = [
						'plan'      => $plan_id,
						'kind'      => $targeting,
						'object_id' => $object_id,
					];
				}
			}
		}

		// Once any plan has a "purchase" rule on a product, Memberships lets
		// only those plans buy it; a plan with just a "view" rule may look but
		// not buy. Each migrated rule is a way in on its own, so that plan's
		// subscribers would be able to buy.
		$view_only_buyers = [];
		foreach ( $plans_by_product_access as $product_id => $plans_by_access ) {
			$view_only_plan_ids = array_values( array_diff( array_unique( $plans_by_access['view'] ?? [] ), $plans_by_access['purchase'] ?? [] ) );
			if ( ! empty( $plans_by_access['purchase'] ) && ! empty( $view_only_plan_ids ) ) {
				$view_only_buyers[] = [
					'product_id' => $product_id,
					'plans'      => $view_only_plan_ids,
				];
			}
		}

		// Where either rule covers a category or the whole catalog, the overlap
		// can't be settled without expanding the catalog, so the view rule is
		// named for a human to check instead.
		$unchecked_view_overlaps = [];
		foreach ( $view_scopes as $view_scope ) {
			foreach ( $purchase_scopes as $purchase_scope ) {
				$named_on_both_sides = Product_Targeting::TARGETING_PRODUCTS === $view_scope['targeting'] && Product_Targeting::TARGETING_PRODUCTS === $purchase_scope['targeting'];
				if ( $purchase_scope['plan'] !== $view_scope['plan'] && ! $named_on_both_sides ) {
					$unchecked_view_overlaps[ $view_scope['source'] ] = $view_scope;
					break;
				}
			}
		}

		return [
			'rules'                   => array_values( $grouped ),
			'skipped'                 => $skipped,
			'hidden'                  => $hidden,
			'delayed'                 => $delayed,
			'view_only_buyers'        => $view_only_buyers,
			'unchecked_view_overlaps' => array_values( $unchecked_view_overlaps ),
			'still_enforced_plan_ids' => array_values( array_unique( $still_enforced_plan_ids ) ),
		];
	}

	/**
	 * A stable subscriber-only restriction id for one plan's rules of one targeting.
	 *
	 * @param int    $plan_id   Plan post id.
	 * @param string $targeting Targeting mode.
	 * @return string
	 */
	public static function migrated_rule_id( int $plan_id, string $targeting ): string {
		return self::MIGRATED_RULE_ID_PREFIX . substr( md5( $plan_id . '|' . $targeting ), 0, 24 );
	}

	/**
	 * Restrictions this command wrote on an earlier run that the current
	 * Memberships rules no longer produce.
	 *
	 * @param array[] $mapped_rules            Rules mapped on this run.
	 * @param int[]   $still_enforced_plan_ids Plans whose earlier restrictions stay, though nothing maps to them now.
	 * @return string[]
	 */
	private static function get_stale_rule_ids( array $mapped_rules, array $still_enforced_plan_ids ): array {
		$kept_ids = wp_list_pluck( $mapped_rules, 'id' );
		foreach ( $still_enforced_plan_ids as $plan_id ) {
			foreach ( [ Product_Targeting::TARGETING_PRODUCTS, Product_Targeting::TARGETING_CATEGORY, Product_Targeting::TARGETING_ALL ] as $targeting ) {
				$kept_ids[] = self::migrated_rule_id( $plan_id, $targeting );
			}
		}
		$stale_ids = [];
		foreach ( Subscriber_Only_Products::get_rules() as $existing_rule ) {
			if ( str_starts_with( $existing_rule['id'], self::MIGRATED_RULE_ID_PREFIX ) && ! in_array( $existing_rule['id'], $kept_ids, true ) ) {
				$stale_ids[] = $existing_rule['id'];
			}
		}
		return $stale_ids;
	}

	/**
	 * A rule without its creation date, which a save keeps from the stored
	 * rule and so never counts as a change.
	 *
	 * @param array $rule Rule.
	 * @return array
	 */
	private static function without_created_at( array $rule ): array {
		unset( $rule['created_at'], $rule['_source_plan_id'] );
		return $rule;
	}

	/**
	 * Products Memberships never restricts.
	 *
	 * <PERSON_06f9e545bd0d>[]
	 */
	private static function get_force_public_product_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off migration lookup on an exact meta key.
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = 'yes' AND p.post_type = 'product'",
				self::FORCE_PUBLIC_META_KEY
			)
		);

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
	}

	/**
	 * A plan's id and title, for the report.
	 *
	 * @param int $plan_id Plan post id.
	 * @return string
	 */
	private static function describe_plan( int $plan_id ): string {
		$title = get_the_title( $plan_id );
		return $title ? sprintf( '%d "%s"', $plan_id, $title ) : (string) $plan_id;
	}

	/**
	 * What a mapped restriction covers, for the report.
	 *
	 * @param array $rule Mapped rule.
	 * @return string
	 */
	private static function describe_targeting( array $rule ): string {
		switch ( $rule['targeting'] ) {
			case Product_Targeting::TARGETING_ALL:
				$description = 'all products';
				break;
			case Product_Targeting::TARGETING_CATEGORY:
				$description = 'categories ' . implode( ', ', $rule['category_ids'] );
				break;
			default:
				$description = implode( ', ', $rule['product_ids'] );
		}
		if ( ! empty( $rule['excluded_product_ids'] ) ) {
			$description .= ', ' . count( $rule['excluded_product_ids'] ) . ' excluded';
		}
		return $description;
	}
}
