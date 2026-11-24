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
use Newspack\Subscriber_Only_Products;
use Newspack\WooCommerce_Subscriptions;
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
	 * Store-wide restriction mode: what a restricted page does for a reader without access.
	 */
	const RESTRICTION_MODE_OPTION = 'wc_memberships_restriction_mode';

	/**
	 * The page the "redirect" restriction mode sends readers to.
	 */
	const REDIRECT_PAGE_ID_OPTION = 'wc_memberships_redirect_page_id';

	/**
	 * The shortcode Memberships puts on the page it creates as the redirect target.
	 */
	const RESTRICTED_CONTENT_SHORTCODE = 'wcm_content_restricted';

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
	 * a restriction paused if the publisher paused it. Any other edit made to a
	 * migrated restriction in Access Control is overwritten. Where a "view" rule
	 * migrates, the restriction settings (product lists, product page) are
	 * overwritten with Memberships' too.
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

		$memberships_rules = get_option( self::MEMBERSHIPS_RULES_OPTION );
		// Treating missing or unreadable source data as "no rules" would mark
		// every migrated restriction stale, and a live run would delete them all.
		if ( ! is_array( $memberships_rules ) ) {
			WP_CLI::error( sprintf( 'The %s option is missing or not an array, so the Memberships rules cannot be read. Nothing was changed.', self::MEMBERSHIPS_RULES_OPTION ) );
		}

		$non_subscription_grants = [];
		$mapped                  = self::map_rules(
			$memberships_rules,
			function ( int $plan_id ) use ( &$non_subscription_grants ): array {
				$granting_product_ids     = Discounts_Migration::get_plan_subscription_product_ids( $plan_id );
				$subscription_product_ids = self::filter_subscription_products( $granting_product_ids );
				$other_product_ids        = array_values( array_diff( $granting_product_ids, $subscription_product_ids ) );
				if ( ! empty( $other_product_ids ) ) {
					$non_subscription_grants[ $plan_id ] = $other_product_ids;
				}
				return $subscription_product_ids;
			},
			self::get_force_public_product_ids(),
			function ( int $plan_id ): bool {
				return 'publish' === get_post_status( $plan_id );
			}
		);
		$stale_rule_ids          = self::get_stale_rule_ids( $mapped['rules'], $mapped['still_enforced_rule_ids'] );

		if ( empty( $mapped['rules'] ) && empty( $mapped['skipped'] ) && empty( $stale_rule_ids ) ) {
			WP_CLI::success( 'No WooCommerce Memberships product restrictions found. Nothing to migrate.' );
			return;
		}

		$summary = [];
		foreach ( $mapped['rules'] as $rule ) {
			$existing_rule = Subscriber_Only_Products::get_rule( $rule['id'] );
			$exists        = null !== $existing_rule;
			// A "view" rule's restriction on a site migrated before the type
			// existed sits under the "Purchase" id; a pause made there carries over.
			if ( ! $exists && Subscriber_Only_Products::RESTRICTION_TYPE_VIEW === $rule['restriction_type'] ) {
				$legacy_rule = Subscriber_Only_Products::get_rule( self::migrated_rule_id( $rule['_source_plan_id'], $rule['targeting'] ) );
				if ( $legacy_rule && empty( $legacy_rule['active'] ) ) {
					$rule['active'] = false;
				}
			}
			if ( $exists ) {
				// Memberships has no pause for this rule type, so a pause can only
				// be the publisher's own, made after the first run. Keep it.
				$rule['active'] = $existing_rule['active'];
			}
			if ( ! $exists ) {
				$result = $dry_run ? 'would create' : 'created';
			} elseif ( self::without_created_at( $existing_rule ) === self::without_created_at( Subscriber_Only_Products::sanitize_rule( $rule ) ) ) {
				$result = 'unchanged';
			} else {
				$result = $dry_run ? 'would update' : 'updated';
			}

			$summary[] = [
				'plan'     => self::describe_plan( $rule['_source_plan_id'] ),
				'restrict' => Subscriber_Only_Products::RESTRICTION_TYPE_VIEW === $rule['restriction_type'] ? 'purchase and visibility' : 'purchase',
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
			\WP_CLI\Utils\format_items( 'table', $summary, [ 'plan', 'restrict', 'products', 'audience', 'result' ] );
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

		// The settings only shape what "Purchase and visibility" products do.
		if ( in_array( Subscriber_Only_Products::RESTRICTION_TYPE_VIEW, wp_list_pluck( $mapped['rules'], 'restriction_type' ), true ) ) {
			self::migrate_settings( $dry_run );
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

		// Eligibility looks for an active subscription, so a one-time product
		// that grants a plan in Memberships can't let anyone buy after the flip.
		foreach ( $non_subscription_grants as $plan_id => $product_ids ) {
			WP_CLI::warning(
				sprintf(
					'Plan %1$s is also granted by product(s) %2$s, which are not subscriptions. Readers who joined that way cannot buy its restricted products after the flip.',
					self::describe_plan( $plan_id ),
					implode( ', ', $product_ids )
				)
			);
		}

		$unchanged_count = count( wp_list_filter( $summary, [ 'result' => 'unchanged' ] ) );
		WP_CLI::success(
			sprintf(
				'Done. %1$d restriction(s) %2$s, %3$d unchanged, %4$d %5$s, %6$d Memberships rule(s) skipped.',
				count( $summary ) - $unchanged_count,
				$dry_run ? 'would be written' : 'written',
				$unchanged_count,
				count( $stale_rule_ids ),
				$dry_run ? 'would be removed' : 'removed',
				count( $mapped['skipped'] )
			)
		);

		if ( ! empty( $mapped['skipped'] ) ) {
			WP_CLI::warning( 'Skipped rules need a decision before the site is flipped — see the table above.' );
		}
	}

	/**
	 * Carry the store-wide settings that decide what a "Purchase and
	 * visibility" product does for readers who can't see it.
	 *
	 * @param bool $dry_run Whether to report without writing.
	 */
	private static function migrate_settings( bool $dry_run ): void {
		$current = Subscriber_Only_Products::get_settings();
		$mapped  = self::map_settings(
			(string) get_option( self::RESTRICTION_MODE_OPTION, '' ),
			(string) get_option( self::HIDE_RESTRICTED_PRODUCTS_OPTION, 'no' ),
			(int) get_option( self::REDIRECT_PAGE_ID_OPTION, 0 )
		);

		$description = sprintf(
			'hide from product lists: %1$s; product page: %2$s',
			$mapped['hide_from_product_lists'] ? 'yes' : 'no',
			Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT === $mapped['product_page'] ? 'redirect to page ' . $mapped['redirect_page_id'] : $mapped['product_page']
		);
		self::warn_about_redirect_page( $mapped );
		if ( $current === $mapped ) {
			WP_CLI::line( sprintf( 'Restriction settings unchanged (%s).', $description ) );
			return;
		}
		WP_CLI::line( sprintf( 'Restriction settings %1$s (%2$s).', $dry_run ? 'would be updated' : 'updated', $description ) );
		if ( ! $dry_run ) {
			Subscriber_Only_Products::update_settings( $mapped );
		}
	}

	/**
	 * Warn when the redirect target won't work once Memberships is off.
	 *
	 * Without a published page, readers get the "hide content" page instead. The
	 * page Memberships creates for this holds only its own shortcode, which
	 * prints as raw text once the plugin is deactivated.
	 *
	 * @param array $settings Mapped settings.
	 */
	private static function warn_about_redirect_page( array $settings ): void {
		if ( Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT !== $settings['product_page'] ) {
			return;
		}
		$page = $settings['redirect_page_id'] ? get_post( $settings['redirect_page_id'] ) : null;
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			WP_CLI::warning( 'Products hidden from non-subscribers redirect to a page that is not a published page, so their product pages will hide their content instead. Pick a page under Audience > Subscriptions > Subscriber-only products > Settings.' );
			return;
		}
		// A plain match: has_shortcode() only sees registered shortcodes.
		if ( false !== strpos( $page->post_content, '[' . self::RESTRICTED_CONTENT_SHORTCODE ) ) {
			WP_CLI::warning( sprintf( 'Products hidden from non-subscribers redirect to page %1$d "%2$s", which uses the [%3$s] shortcode. It prints as text once Memberships is deactivated: edit the page or pick another one before the switch.', $page->ID, $page->post_title, self::RESTRICTED_CONTENT_SHORTCODE ) );
		}
	}

	/**
	 * Map the store-wide Memberships settings to subscriber-only product settings.
	 *
	 * @param string $restriction_mode         The Memberships restriction mode: "hide_content", "hide" or "redirect".
	 * @param string $hide_restricted_products The Memberships "hide restricted products" option, "yes" or "no".
	 * @param int    $redirect_page_id         The page the "redirect" mode sends readers to.
	 * @return array Settings shaped for Subscriber_Only_Products::update_settings().
	 */
	public static function map_settings( string $restriction_mode, string $hide_restricted_products, int $redirect_page_id ): array {
		$product_page = in_array( $restriction_mode, [ Subscriber_Only_Products::PRODUCT_PAGE_HIDE, Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT ], true )
			? $restriction_mode
			: Subscriber_Only_Products::PRODUCT_PAGE_HIDE_CONTENT;
		return [
			// "Hide completely" keeps hidden products out of lists whatever this
			// says, here as in Memberships, so the option carries over as it is.
			'hide_from_product_lists' => 'yes' === $hide_restricted_products,
			'product_page'            => $product_page,
			'redirect_page_id'        => Subscriber_Only_Products::PRODUCT_PAGE_REDIRECT === $product_page ? max( 0, $redirect_page_id ) : 0,
		];
	}

	/**
	 * Convert WooCommerce Memberships rules into subscriber-only product restrictions.
	 *
	 * Pure apart from the injected plan callbacks, so the mapping can be tested
	 * without WP-CLI.
	 *
	 * @param array         $memberships_rules        Raw `wc_memberships_rules` option value.
	 * @param callable      $plan_products_getter     Given a plan id, returns the subscription product ids that grant it.
	 * @param int[]         $force_public_product_ids Products Memberships never restricts.
	 * @param callable|null $plan_is_published        Given a plan id, whether it is published. Null treats every plan as published.
	 * @return array {
	 *     @type array[] $rules   Restrictions ready for the store, one per plan and targeting.
	 *     @type array[] $skipped Memberships rules that need a human decision, with a reason.
	 *     @type array[] $delayed Rules that granted purchase only some time after joining.
	 *     @type string[] $still_enforced_rule_ids Earlier restrictions for rules skipped here that Memberships still enforces.
	 * }
	 */
	public static function map_rules( array $memberships_rules, callable $plan_products_getter, array $force_public_product_ids = [], ?callable $plan_is_published = null ): array {
		$force_public_product_ids = array_map( 'intval', $force_public_product_ids );

		$grouped                 = [];
		$skipped                 = [];
		$delayed                 = [];
		$still_enforced_rule_ids = [];

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

			if ( ! $plan_id ) {
				$skip( 'Rule names no membership plan.' );
				continue;
			}

			// Memberships stores no `active` flag on product restrictions; it
			// applies a plan's rules only while the plan is published.
			if ( $plan_is_published && ! call_user_func( $plan_is_published, $plan_id ) ) {
				$skip( 'Plan is not published, so Memberships does not apply this rule.' );
				continue;
			}

			$object_ids       = array_values( array_filter( array_map( 'absint', (array) ( $memberships_rule['object_ids'] ?? [] ) ) ) );
			$is_taxonomy      = 'taxonomy' === ( $memberships_rule['content_type'] ?? '' );
			$restriction_type = 'view' === ( $memberships_rule['access_type'] ?? '' ) ? Subscriber_Only_Products::RESTRICTION_TYPE_VIEW : Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE;

			if ( $is_taxonomy && self::SUPPORTED_TAXONOMY !== ( $memberships_rule['content_type_name'] ?? '' ) ) {
				$skip(
					sprintf(
						'Targets the "%s" taxonomy, which subscriber-only products cannot express — re-target it by product or category.',
						(string) ( $memberships_rule['content_type_name'] ?? 'unknown' )
					)
				);
				continue;
			}

			$subscription_product_ids = array_map( 'intval', (array) call_user_func( $plan_products_getter, $plan_id ) );
			if ( empty( $subscription_product_ids ) ) {
				// A restriction naming no subscription is skipped by the store as
				// unbuyable-by-everyone, so the rule would migrate and do nothing.
				$skip( 'Plan grants access without a subscription product — pick the subscription(s) by hand.' );
				// Memberships still enforces this rule, so the restriction an
				// earlier run wrote for it must stay until someone decides.
				if ( empty( $object_ids ) ) {
					$skipped_targeting = Product_Targeting::TARGETING_ALL;
				} else {
					$skipped_targeting = $is_taxonomy ? Product_Targeting::TARGETING_CATEGORY : Product_Targeting::TARGETING_PRODUCTS;
				}
				$still_enforced_rule_ids[] = self::migrated_rule_id( $plan_id, $skipped_targeting, $restriction_type );
				// A site migrated before the type existed holds a "view" rule under
				// the "Purchase" id, which must stay for the same reason.
				$still_enforced_rule_ids[] = self::migrated_rule_id( $plan_id, $skipped_targeting );
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

			$key = $plan_id . '|' . $targeting . '|' . $restriction_type;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = [
					'_source_plan_id'          => $plan_id,
					// Derived from the plan and targeting so a re-run updates the
					// same restriction in place instead of adding a copy.
					'id'                       => self::migrated_rule_id( $plan_id, $targeting, $restriction_type ),
					'restriction_type'         => $restriction_type,
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
		}

		return [
			'rules'                   => array_values( $grouped ),
			'skipped'                 => $skipped,
			'delayed'                 => $delayed,
			'still_enforced_rule_ids' => array_values( array_unique( $still_enforced_rule_ids ) ),
		];
	}

	/**
	 * A stable subscriber-only restriction id for one plan's rules of one
	 * targeting and type.
	 *
	 * A "Purchase" restriction's id hashes the plan and targeting only, the
	 * id that sites migrated before the type existed carry, so a re-run there
	 * updates those restrictions in place.
	 *
	 * @param int    $plan_id          Plan post id.
	 * @param string $targeting        Targeting mode.
	 * @param string $restriction_type Restriction type.
	 * @return string
	 */
	public static function migrated_rule_id( int $plan_id, string $targeting, string $restriction_type = Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE ): string {
		$key = $plan_id . '|' . $targeting;
		if ( Subscriber_Only_Products::RESTRICTION_TYPE_PURCHASE !== $restriction_type ) {
			$key .= '|' . $restriction_type;
		}
		return self::MIGRATED_RULE_ID_PREFIX . substr( md5( $key ), 0, 24 );
	}

	/**
	 * Restrictions this command wrote on an earlier run that the current
	 * Memberships rules no longer produce.
	 *
	 * @param array[]  $mapped_rules            Rules mapped on this run.
	 * @param string[] $still_enforced_rule_ids Earlier restrictions to keep, though nothing maps to them now.
	 * @return string[]
	 */
	private static function get_stale_rule_ids( array $mapped_rules, array $still_enforced_rule_ids ): array {
		$kept_ids = array_merge( wp_list_pluck( $mapped_rules, 'id' ), $still_enforced_rule_ids );
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
		unset( $rule['created_at'] );
		return $rule;
	}

	/**
	 * The subscription products among a plan's granting products.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return int[]
	 */
	public static function filter_subscription_products( array $product_ids ): array {
		return array_values(
			array_filter(
				$product_ids,
				function ( $product_id ) {
					$product = wc_get_product( $product_id );
					return $product && WooCommerce_Subscriptions::is_subscription_product( $product );
				}
			)
		);
	}

	/**
	 * Products Memberships never restricts.
	 *
	 * @return int[]
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
