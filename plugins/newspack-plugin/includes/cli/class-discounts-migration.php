<?php
/**
 * Migrates WooCommerce Memberships purchasing discounts to subscriber discounts.
 *
 * Ported shape: a Memberships purchasing-discount rule discounts products for
 * members of one plan; a subscriber discount discounts the same products for
 * holders of the subscription products that granted that plan.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Discounts;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Memberships discount migration.
 */
class Discounts_Migration {

	/**
	 * Option holding every WooCommerce Memberships rule.
	 */
	const MEMBERSHIPS_RULES_OPTION = 'wc_memberships_rules';

	/**
	 * Per-product flag opting a product out of member discounts.
	 */
	const EXCLUDE_DISCOUNTS_META_KEY = '_wc_memberships_exclude_discounts';

	/**
	 * Memberships' store-level setting for discounting on-sale products.
	 *
	 * Note the inverted sense: Memberships stores whether to *exclude* on-sale
	 * products (default 'no', i.e. it discounts them), while a subscriber
	 * discount stores whether to *apply* to them (default false).
	 */
	const EXCLUDE_ON_SALE_OPTION = 'wc_memberships_exclude_on_sale_products_from_member_discounts';

	/**
	 * Memberships' upsell switch: whether a membership in the cart discounts the
	 * rest of that same order.
	 */
	const APPLY_WHEN_PURCHASING_OPTION = 'wc_memberships_apply_member_discounts_when_purchasing_membership';

	/**
	 * The only product taxonomy a subscriber discount can target.
	 */
	const SUPPORTED_TAXONOMY = 'product_cat';

	/**
	 * Migrate WooCommerce Memberships purchasing discounts to Access Control
	 * subscriber discounts.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Apply the changes. Without this flag the command runs as a dry-run and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-discounts
	 *     wp newspack migrate-discounts --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate_discounts( $args, $assoc_args ) {
		$dry_run = ! (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		if ( $dry_run ) {
			WP_CLI::line( '' );
			WP_CLI::line( '*** DRY RUN MODE — no data will be modified. Pass --live to apply. ***' );
			WP_CLI::line( '' );
		}

		$memberships_rules = get_option( self::MEMBERSHIPS_RULES_OPTION, [] );
		if ( ! is_array( $memberships_rules ) || empty( $memberships_rules ) ) {
			WP_CLI::success( 'No WooCommerce Memberships rules found. Nothing to migrate.' );
			return;
		}

		$mapped = self::map_rules( $memberships_rules, [ __CLASS__, 'get_plan_subscription_product_ids' ], self::get_globally_excluded_product_ids() );

		$summary = [];
		foreach ( $mapped['rules'] as $rule ) {
			$row = [
				'source'    => $rule['_source_rule_id'],
				'plan'      => $rule['_source_plan_id'],
				'audience'  => count( $rule['subscription_product_ids'] ),
				'discount'  => 'percent' === $rule['discount_type'] ? $rule['amount'] . '%' : $rule['amount'],
				'targeting' => self::describe_targeting( $rule ),
				'active'    => $rule['active'] ? 'Y' : 'N',
				'result'    => $dry_run ? 'would create' : 'created',
			];

			unset( $rule['_source_rule_id'], $rule['_source_plan_id'] );

			if ( $dry_run ) {
				// Run the store's own validation without persisting, so a rule
				// the store would reject is named in the preview rather than
				// only on the live run.
				$validated = Subscriber_Discounts::sanitize_rule( $rule );
				if ( is_wp_error( $validated ) ) {
					$row['result'] = 'would fail: ' . $validated->get_error_message();
				}
			} else {
				$saved = Subscriber_Discounts::save_rule( $rule );
				if ( is_wp_error( $saved ) ) {
					$row['result'] = 'ERROR: ' . $saved->get_error_message();
				}
			}

			$summary[] = $row;
		}

		WP_CLI::line( '' );
		WP_CLI::line( $dry_run ? '=== DRY RUN SUMMARY ===' : '=== MIGRATION SUMMARY ===' );
		if ( ! empty( $summary ) ) {
			\WP_CLI\Utils\format_items(
				'table',
				$summary,
				[ 'source', 'plan', 'audience', 'discount', 'targeting', 'active', 'result' ]
			);
		}

		if ( ! empty( $mapped['skipped'] ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '=== SKIPPED — %d total ===', count( $mapped['skipped'] ) ) );
			\WP_CLI\Utils\format_items( 'table', $mapped['skipped'], [ 'source', 'plan', 'reason' ] );
		}

		$errored = count(
			array_filter(
				$summary,
				function ( $row ) {
					return 0 === strpos( $row['result'], 'ERROR' ) || 0 === strpos( $row['result'], 'would fail' );
				}
			)
		);

		WP_CLI::success(
			sprintf(
				'Done. %d discount rule(s) %s, %d skipped, %d error(s).',
				count( $summary ) - $errored,
				$dry_run ? 'would be created' : 'created',
				count( $mapped['skipped'] ),
				$errored
			)
		);

		if ( ! empty( $mapped['skipped'] ) ) {
			WP_CLI::warning( 'Skipped rules need a decision before the site is flipped — see the table above.' );
		}

		self::report_settings_parity( $dry_run, count( $mapped['rules'] ), $memberships_rules );
	}

	/**
	 * Report where Memberships' store-level discount behaviour differs from the
	 * subscriber-discount defaults, and carry across what can be carried.
	 *
	 * These two settings decide what the migrated rules actually do, and both
	 * defaults are inverted relative to Memberships — so a site flipped without
	 * looking at them would quietly charge subscribers more than it used to.
	 *
	 * @param bool  $dry_run           Whether this is a dry run.
	 * @param int   $rule_count        How many rules were mapped.
	 * @param array $memberships_rules Raw `wc_memberships_rules` option value.
	 */
	private static function report_settings_parity( $dry_run, $rule_count, $memberships_rules ) {
		if ( ! $rule_count ) {
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== STORE-LEVEL SETTINGS ===' );

		// Memberships stores whether to *exclude* on-sale products, so its value
		// inverts into ours. Both plugins discount on-sale products when nobody
		// has touched the setting, so this only differs on a store that turned
		// the exclusion on.
		$memberships_excludes_on_sale = 'yes' === get_option( self::EXCLUDE_ON_SALE_OPTION, 'no' );
		$apply_on_sale                = ! $memberships_excludes_on_sale;

		// Memberships' upsell switch maps across directly; both default to off.
		$apply_at_checkout = 'yes' === get_option( self::APPLY_WHEN_PURCHASING_OPTION, 'no' );

		WP_CLI::line(
			sprintf(
				'On-sale products: Memberships %s them.',
				$memberships_excludes_on_sale ? 'excludes' : 'discounts'
			)
		);
		WP_CLI::line(
			sprintf(
				'Membership in the cart: Memberships %s the rest of that order.',
				$apply_at_checkout ? 'discounts' : 'does not discount'
			)
		);

		// Only carried on the first run: rules update in place on a re-run, so
		// overwriting here would silently revert a setting a publisher changed
		// after the migration.
		$settings_already_stored = false !== get_option( Subscriber_Discounts::SETTINGS_OPTION_NAME, false );

		if ( $settings_already_stored ) {
			WP_CLI::line( 'This site already has its own discount settings; both left as they are.' );
		} else {
			WP_CLI::line(
				sprintf(
					'%1$s "Apply on top of sale prices" %2$s and "Apply discounts at checkout" %3$s.',
					$dry_run ? 'Would set' : 'Set',
					$apply_on_sale ? 'on' : 'off',
					$apply_at_checkout ? 'on' : 'off'
				)
			);
			if ( ! $dry_run ) {
				Subscriber_Discounts::save_settings(
					[
						'apply_on_sale'     => $apply_on_sale,
						'apply_at_checkout' => $apply_at_checkout,
					]
				);
			}
		}

		self::report_stacking_impact( $memberships_rules );
	}

	/**
	 * Report readers whose price rises because overlapping discounts stop
	 * accumulating.
	 *
	 * Memberships applies every matching rule in sequence when a reader holds
	 * several plans; Access Control applies the single best one. That only costs
	 * a reader money where they actually hold two plans whose rules cover the
	 * same product, so this counts affected readers rather than warning whenever
	 * two rules merely look like they could overlap — a warning nobody can act
	 * on, since Memberships' stacking switch is a code filter with no stored
	 * value to read.
	 *
	 * @param array $memberships_rules Raw `wc_memberships_rules` option value.
	 */
	private static function report_stacking_impact( $memberships_rules ) {
		$rules_by_plan = [];
		foreach ( $memberships_rules as $memberships_rule ) {
			if ( ! is_array( $memberships_rule ) || 'purchasing_discount' !== ( $memberships_rule['rule_type'] ?? '' ) ) {
				continue;
			}
			if ( 'yes' !== ( $memberships_rule['active'] ?? 'no' ) ) {
				continue;
			}
			$rules_by_plan[ (int) ( $memberships_rule['membership_plan_id'] ?? 0 ) ][] = $memberships_rule;
		}
		if ( count( $rules_by_plan ) < 2 ) {
			WP_CLI::line( 'Overlapping discounts: nothing to reconcile — fewer than two plans carry an active discount rule.' );
			return;
		}

		// Printed on screen, not just in the comments above: the comparison only
		// sees products it can enumerate, so the number below is a floor.
		$coverage_caveat = 'Overlapping discounts are compared per product, so this count is a floor: catalog-wide rules, categories past the first 500 products, and variable parents priced only through their variations are not counted.';

		$affected_readers = self::readers_losing_stacked_discounts( $rules_by_plan );
		if ( empty( $affected_readers ) ) {
			WP_CLI::line( 'Overlapping discounts: no reader holds two plans whose discounts cover the same product, so no price changes.' );
			WP_CLI::line( $coverage_caveat );
			return;
		}

		WP_CLI::warning(
			sprintf(
				'Overlapping discounts: %d reader/product pair(s) stack today and will cost more after the flip. ' .
				'Memberships compounds overlapping discounts; Access Control applies the single best one. Agree this with the publisher before flipping.',
				count( $affected_readers )
			)
		);
		foreach ( array_slice( $affected_readers, 0, 10 ) as $affected_reader ) {
			WP_CLI::line(
				sprintf(
					'  reader %1$d, product %2$d "%3$s": pays %4$s today, %5$s after.',
					$affected_reader['user_id'],
					$affected_reader['product_id'],
					$affected_reader['product_name'],
					wp_strip_all_tags( wc_price( $affected_reader['stacked_price'] ) ),
					wp_strip_all_tags( wc_price( $affected_reader['best_price'] ) )
				)
			);
		}
		if ( count( $affected_readers ) > 10 ) {
			WP_CLI::line( sprintf( '  ... and %d more.', count( $affected_readers ) - 10 ) );
		}
		WP_CLI::line( $coverage_caveat );
	}

	/**
	 * Reader/product pairs that pay more once overlapping discounts stop
	 * accumulating.
	 *
	 * @param array $rules_by_plan Active purchasing-discount rules keyed by plan id.
	 * @return array[]
	 */
	private static function readers_losing_stacked_discounts( $rules_by_plan ) {
		global $wpdb;

		// Expand every rule to the products it covers, so two rules are compared
		// on the product a reader actually buys rather than on their targeting —
		// one may name a category where the other names a product.
		$rules_by_plan_product = [];
		foreach ( $rules_by_plan as $plan_id => $plan_rules ) {
			foreach ( $plan_rules as $plan_rule ) {
				foreach ( self::products_covered_by( $plan_rule ) as $product_id ) {
					$rules_by_plan_product[ $plan_id ][ $product_id ] = $plan_rule;
				}
			}
		}
		if ( count( $rules_by_plan_product ) < 2 ) {
			return [];
		}

		$plan_id_list = implode( ',', array_map( 'intval', array_keys( $rules_by_plan_product ) ) );
		// Direct and uncached on purpose: a one-shot migration report, run by hand
		// once per site, over a membership set no WordPress API groups this way.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_members = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plan ids are cast to int above.
			"SELECT post_author AS user_id, GROUP_CONCAT( DISTINCT post_parent ) AS plan_ids
			 FROM {$wpdb->posts}
			 WHERE post_type = 'wc_user_membership'
			   AND post_status IN ( 'wcm-active', 'wcm-complimentary', 'wcm-free_trial' )
			   AND post_parent IN ( {$plan_id_list} )
			 GROUP BY post_author
			 HAVING COUNT( DISTINCT post_parent ) > 1"
			// phpcs:enable
		);

		$affected = [];
		foreach ( $active_members as $active_member ) {
			$rules_by_product = [];
			foreach ( array_map( 'intval', explode( ',', (string) $active_member->plan_ids ) ) as $held_plan_id ) {
				foreach ( $rules_by_plan_product[ $held_plan_id ] ?? [] as $product_id => $plan_rule ) {
					$rules_by_product[ $product_id ][] = $plan_rule;
				}
			}
			foreach ( $rules_by_product as $product_id => $product_rules ) {
				if ( count( $product_rules ) < 2 ) {
					continue;
				}
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				$base_price = (float) $product->get_regular_price();
				if ( $base_price <= 0 ) {
					continue;
				}

				$stacked_price = $base_price;
				$best_price    = $base_price;
				foreach ( $product_rules as $product_rule ) {
					$compounded_price = self::memberships_discounted_price( $stacked_price, $product_rule );
					if ( $compounded_price < $stacked_price ) {
						$stacked_price = $compounded_price;
					}
					$standalone_price = self::memberships_discounted_price( $base_price, $product_rule );
					if ( $standalone_price < $best_price ) {
						$best_price = $standalone_price;
					}
				}

				if ( round( $best_price, 2 ) > round( $stacked_price, 2 ) ) {
					$affected[] = [
						'user_id'       => (int) $active_member->user_id,
						'product_id'    => (int) $product_id,
						'product_name'  => $product->get_name(),
						'stacked_price' => round( $stacked_price, 2 ),
						'best_price'    => round( $best_price, 2 ),
					];
				}
			}
		}

		return $affected;
	}

	/**
	 * Products a Memberships rule covers.
	 *
	 * A rule with no targets covers the whole catalog. Enumerating that would be
	 * unbounded, so it reports nothing: a catalog-wide rule is reported by the
	 * rule listing above, and the comparison here is about the specific products
	 * two rules share.
	 *
	 * Category expansion is capped at 500 products. A site whose discounted
	 * category is larger than that gets an undercount rather than a slow command;
	 * the cap has never been approached on a site carrying discount rules.
	 *
	 * @param array $memberships_rule One `wc_memberships_rules` entry.
	 * @return int[]
	 */
	private static function products_covered_by( $memberships_rule ) {
		$object_ids = array_map( 'intval', (array) ( $memberships_rule['object_ids'] ?? [] ) );
		if ( empty( $object_ids ) ) {
			return [];
		}
		if ( 'taxonomy' !== ( $memberships_rule['content_type'] ?? '' ) ) {
			return $object_ids;
		}

		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => [ 'product', 'product_variation' ],
					'post_status'    => 'publish',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded, one-shot CLI report; see the cap note above.
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						[
							'taxonomy' => (string) ( $memberships_rule['content_type_name'] ?? 'product_cat' ),
							'field'    => 'term_id',
							'terms'    => $object_ids,
						],
					],
				]
			)
		);
	}

	/**
	 * Memberships' own discount arithmetic, for the before/after comparison.
	 *
	 * @param float $price            Price to discount.
	 * @param array $memberships_rule One `wc_memberships_rules` entry.
	 * @return float
	 */
	private static function memberships_discounted_price( $price, $memberships_rule ) {
		$amount = (float) ( $memberships_rule['discount_amount'] ?? 0 );
		$price  = 'percentage' === ( $memberships_rule['discount_type'] ?? '' )
			? $price * ( 100 - $amount ) / 100
			: $price - $amount;
		return max( $price, 0 );
	}

	/**
	 * Convert WooCommerce Memberships rules into subscriber discount rules.
	 *
	 * Pure apart from the injected plan resolver, so the mapping can be tested
	 * without WordPress or WP-CLI.
	 *
	 * @param array    $memberships_rules    Raw `wc_memberships_rules` option value.
	 * @param callable $plan_products_getter Given a plan id, returns the subscription product ids that grant it.
	 * @param int[]    $excluded_product_ids Products flagged in Memberships as never discounted.
	 * @return array {
	 *     @type array[] $rules   Rules ready for the discount store.
	 *     @type array[] $skipped Rules that need a human decision, with a reason.
	 * }
	 */
	public static function map_rules( $memberships_rules, $plan_products_getter, $excluded_product_ids = [] ) {
		$rules   = [];
		$skipped = [];

		foreach ( $memberships_rules as $memberships_rule ) {
			if ( ! is_array( $memberships_rule ) || 'purchasing_discount' !== ( $memberships_rule['rule_type'] ?? '' ) ) {
				continue;
			}

			$source_id = $memberships_rule['id'] ?? '(no id)';
			$plan_id   = (int) ( $memberships_rule['membership_plan_id'] ?? 0 );

			$subscription_product_ids = $plan_id ? (array) call_user_func( $plan_products_getter, $plan_id ) : [];
			if ( empty( $subscription_product_ids ) ) {
				// A plan granted only by hand has no product to key the discount
				// on. Inventing one would silently discount for the wrong
				// readers, so it is left for a human.
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id ? $plan_id : '(none)',
					'reason' => 'Plan grants access without a subscription product — pick the subscription(s) by hand.',
				];
				continue;
			}

			$object_ids  = array_values( array_filter( array_map( 'absint', (array) ( $memberships_rule['object_ids'] ?? [] ) ) ) );
			$is_taxonomy = 'taxonomy' === ( $memberships_rule['content_type'] ?? '' );

			// Memberships lets a discount target any product taxonomy — tags and
			// product attributes included — while a subscriber discount resolves
			// categories only. Migrating a tag rule into `category_ids` would
			// produce a rule that matches nothing and reports success.
			if ( $is_taxonomy && self::SUPPORTED_TAXONOMY !== ( $memberships_rule['content_type_name'] ?? '' ) ) {
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id,
					'reason' => sprintf(
						'Targets the "%s" taxonomy, which subscriber discounts cannot express — re-target it by product or category.',
						(string) ( $memberships_rule['content_type_name'] ?? 'unknown' )
					),
				];
				continue;
			}

			if ( empty( $object_ids ) ) {
				$targeting = Product_Targeting::TARGETING_ALL;
			} elseif ( $is_taxonomy ) {
				$targeting = Product_Targeting::TARGETING_CATEGORY;
			} else {
				$targeting = Product_Targeting::TARGETING_PRODUCTS;
			}

			// An unrecognized type would otherwise fall through to "fixed" and
			// turn "10% off" into "$10 off" — a large mispricing reported as a
			// clean migration.
			$memberships_discount_type = $memberships_rule['discount_type'] ?? '';
			if ( ! in_array( $memberships_discount_type, [ 'percentage', 'amount' ], true ) ) {
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id,
					'reason' => sprintf( 'Unrecognized discount type "%s" — cannot tell a percentage from an amount.', (string) $memberships_discount_type ),
				];
				continue;
			}

			$amount = (float) ( $memberships_rule['discount_amount'] ?? 0 );
			if ( $amount <= 0 ) {
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id,
					'reason' => 'Discount amount is zero or missing.',
				];
				continue;
			}

			// Memberships applies the per-product exclusion flag before any rule
			// matches, so a flagged product is undiscounted even where a rule
			// names it outright. A subscriber discount carries exclusions on the
			// rule and drops them for a hand-picked product list, so for that
			// targeting the flagged products come off the list instead.
			$product_ids = Product_Targeting::TARGETING_PRODUCTS === $targeting
				? array_values( array_diff( $object_ids, $excluded_product_ids ) )
				: [];
			if ( Product_Targeting::TARGETING_PRODUCTS === $targeting && empty( $product_ids ) ) {
				$skipped[] = [
					'source' => $source_id,
					'plan'   => $plan_id,
					'reason' => 'Every product this rule names is flagged in Memberships as never discounted, so the rule discounts nothing.',
				];
				continue;
			}

			$rules[] = [
				'_source_rule_id'          => $source_id,
				'_source_plan_id'          => $plan_id,
				// Derived from the source rule so a re-run updates the same rule
				// in place. Without it every run would mint a new id and
				// duplicate the whole rule set.
				'id'                       => self::migrated_rule_id( $source_id ),
				'subscription_product_ids' => $subscription_product_ids,
				'targeting'                => $targeting,
				'product_ids'              => $product_ids,
				'category_ids'             => Product_Targeting::TARGETING_CATEGORY === $targeting ? $object_ids : [],
				// A category or catalog-wide rule carries the flagged products as
				// rule exclusions; see the note above for the hand-picked case.
				'excluded_product_ids'     => Product_Targeting::TARGETING_PRODUCTS === $targeting ? [] : $excluded_product_ids,
				'discount_type'            => 'percentage' === ( $memberships_rule['discount_type'] ?? '' ) ? 'percent' : 'fixed',
				'amount'                   => $amount,
				// A rule paused in Memberships stays paused, so a migration never
				// switches a discount back on.
				'active'                   => 'yes' === ( $memberships_rule['active'] ?? '' ),
			];
		}

		return [
			'rules'   => $rules,
			'skipped' => $skipped,
		];
	}

	/**
	 * A stable subscriber-discount id for a Memberships rule.
	 *
	 * @param string $source_rule_id The Memberships rule id.
	 * @return string
	 */
	public static function migrated_rule_id( $source_rule_id ) {
		return 'wcm-' . substr( md5( (string) $source_rule_id ), 0, 24 );
	}

	/**
	 * The subscription products that grant a Memberships plan.
	 *
	 * @param int $plan_id Plan post id.
	 * @return int[]
	 */
	public static function get_plan_subscription_product_ids( $plan_id ) {
		$product_ids = get_post_meta( $plan_id, '_product_ids', true );
		return array_values( array_filter( array_map( 'absint', (array) $product_ids ) ) );
	}

	/**
	 * Products Memberships flags as never discounted.
	 *
	 * @return int[]
	 */
	private static function get_globally_excluded_product_ids() {
		global $wpdb;

		// A direct id lookup on an exact meta key, rather than a `-1` WP_Query
		// over the whole catalogue. Only parent products are collected:
		// `Product_Targeting` already treats a variation as excluded when its
		// parent is listed, so listing variations too would only pad the rules.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off migration lookup; caching a value read once per run would be worse than the query.
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'yes'",
				self::EXCLUDE_DISCOUNTS_META_KEY
			)
		);

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
	}

	/**
	 * A short human description of what a mapped rule covers.
	 *
	 * @param array $rule Mapped rule.
	 * @return string
	 */
	private static function describe_targeting( $rule ) {
		switch ( $rule['targeting'] ) {
			case Product_Targeting::TARGETING_ALL:
				$description = 'all products';
				break;
			case Product_Targeting::TARGETING_CATEGORY:
				$description = count( $rule['category_ids'] ) . ' categor' . ( 1 === count( $rule['category_ids'] ) ? 'y' : 'ies' );
				break;
			default:
				$description = count( $rule['product_ids'] ) . ' product(s)';
		}
		if ( ! empty( $rule['excluded_product_ids'] ) ) {
			$description .= ', ' . count( $rule['excluded_product_ids'] ) . ' excluded';
		}
		return $description;
	}
}
