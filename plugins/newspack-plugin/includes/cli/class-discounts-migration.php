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

			if ( ! $dry_run ) {
				unset( $rule['_source_rule_id'], $rule['_source_plan_id'] );
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
					return 0 === strpos( $row['result'], 'ERROR' );
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

		self::report_settings_parity( $dry_run, count( $mapped['rules'] ) );
	}

	/**
	 * Report where Memberships' store-level discount behaviour differs from the
	 * subscriber-discount defaults, and carry across what can be carried.
	 *
	 * These two settings decide what the migrated rules actually do, and both
	 * defaults are inverted relative to Memberships — so a site flipped without
	 * looking at them would quietly charge subscribers more than it used to.
	 *
	 * @param bool $dry_run    Whether this is a dry run.
	 * @param int  $rule_count How many rules were mapped.
	 */
	private static function report_settings_parity( $dry_run, $rule_count ) {
		if ( ! $rule_count ) {
			return;
		}

		WP_CLI::line( '' );
		WP_CLI::line( '=== STORE-LEVEL SETTINGS ===' );

		// Memberships stores whether to *exclude* on-sale products and defaults
		// to 'no' — i.e. it discounts them. Subscriber discounts default to not
		// discounting them, so this has to be carried across explicitly.
		$memberships_excludes_on_sale = 'yes' === get_option( self::EXCLUDE_ON_SALE_OPTION, 'no' );
		$apply_on_sale                = ! $memberships_excludes_on_sale;

		WP_CLI::line(
			sprintf(
				'On-sale products: Memberships %s them. %s "Apply on top of sale prices" %s.',
				$memberships_excludes_on_sale ? 'excludes' : 'discounts',
				$dry_run ? 'Would set' : 'Set',
				$apply_on_sale ? 'on' : 'off'
			)
		);
		if ( ! $dry_run ) {
			Subscriber_Discounts::save_settings( [ 'apply_on_sale' => $apply_on_sale ] );
		}

		// Cumulative stacking is filter-only in Memberships (default: on), so
		// there is no stored value to read — a publisher's override is invisible
		// from here and the call has to be made by a human.
		$overlap = Subscriber_Discounts::get_settings()['overlap'];
		WP_CLI::line( sprintf( 'Overlapping discounts: currently "%s".', $overlap ) );
		if ( 'best' === $overlap ) {
			WP_CLI::warning(
				'Memberships combines overlapping discounts by default; this site is set to apply only the best one. ' .
				'If any two migrated rules can cover the same product, subscribers will now save less than they did — ' .
				'check the rules above and switch the setting to "Combine discounts" if so.'
			);
		}
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
			if ( $is_taxonomy && ! empty( $object_ids ) && self::SUPPORTED_TAXONOMY !== ( $memberships_rule['content_type_name'] ?? '' ) ) {
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

			$rules[] = [
				'_source_rule_id'          => $source_id,
				'_source_plan_id'          => $plan_id,
				// Derived from the source rule so a re-run updates the same rule
				// in place. Without it every run would mint a new id and
				// duplicate the whole rule set — which under the "combine"
				// overlap setting would compound the discount readers get.
				'id'                       => self::migrated_rule_id( $source_id ),
				'subscription_product_ids' => $subscription_product_ids,
				'targeting'                => $targeting,
				'product_ids'              => Product_Targeting::TARGETING_PRODUCTS === $targeting ? $object_ids : [],
				'category_ids'             => Product_Targeting::TARGETING_CATEGORY === $targeting ? $object_ids : [],
				// Memberships excludes products from discounts with a per-product
				// flag; subscriber discounts carry exclusions on the rule, so the
				// flagged products are attached to every rule that could reach them.
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
