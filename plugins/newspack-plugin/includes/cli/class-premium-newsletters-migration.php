<?php
/**
 * WP-CLI command to migrate WooCommerce Membership plans that restrict newsletter
 * lists to Newspack Access Control premium newsletter gates.
 *
 * Sibling of the migrate-membership-gates command: same grouping, titling and
 * verification shape, applied to the premium newsletter gate bucket instead of the
 * content gate bucket. A premium newsletter gate is an ordinary content gate
 * carrying `is_newsletter` post meta, which the evaluator uses to decide which
 * bucket a post is judged against — so migrating one is a matter of writing the
 * right rules and mode settings, not of authoring layouts.
 *
 * The class file is included on every request like the other CLI classes; only the
 * command registration is gated on WP_CLI.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Membership plan → Access Control premium newsletter gate migration CLI command.
 */
class Premium_Newsletters_Migration {

	/**
	 * The newsletter list post type, used when Newspack Newsletters is not loaded.
	 *
	 * WooCommerce Memberships stores the post type name on the rule, so a rule
	 * written before the plugin was deactivated still names this CPT.
	 */
	const NEWSLETTER_LIST_CPT_FALLBACK = 'newspack_nl_list';

	/**
	 * The newsletter list post type.
	 *
	 * Read from Newspack Newsletters when it is loaded so the two stay in step, with
	 * a literal fallback so this command's sibling can call it without depending on
	 * that plugin.
	 *
	 * @return string The list post type.
	 */
	private static function get_list_cpt(): string {
		if ( class_exists( 'Newspack\Newsletters\Subscription_Lists' ) ) {
			$cpt = \Newspack\Newsletters\Subscription_Lists::CPT;
			if ( $cpt ) {
				return $cpt;
			}
		}
		return self::NEWSLETTER_LIST_CPT_FALLBACK;
	}

	/**
	 * Collect the newsletter list IDs a plan's content restriction rules select.
	 *
	 * Non-newsletter rules are ignored: they belong to the content gate the sibling
	 * command writes, and their object IDs would be read as list IDs here. The
	 * result feeds the gate's single 'newsletters' content rule, whose values the
	 * evaluator compares directly against a list post ID — so WooCommerce's object
	 * IDs carry across without translation.
	 *
	 * @param \WC_Memberships_Membership_Plan_Rule[] $wc_rules Array of WC Memberships rules.
	 *
	 * @return int[] Deduplicated newsletter list post IDs, in the order WC returned them.
	 */
	private static function extract_list_ids( array $wc_rules ): array {
		$list_cpt = self::get_list_cpt();
		$list_ids = [];
		foreach ( $wc_rules as $rule ) {
			if ( $list_cpt !== $rule->get_content_type_name() ) {
				continue;
			}
			foreach ( $rule->get_object_ids() as $object_id ) {
				$list_ids[] = (int) $object_id;
			}
		}
		return array_values( array_unique( array_filter( $list_ids ) ) );
	}

	/**
	 * Compute a canonical fingerprint for a set of newsletter list IDs.
	 *
	 * Groups plans that restrict the same lists so they share one gate. Sorting
	 * makes the fingerprint independent of the order WooCommerce returned the rules
	 * in, so an incidental ordering difference cannot split one gate into two.
	 *
	 * @param int[] $list_ids Newsletter list post IDs.
	 *
	 * @return string Canonical fingerprint.
	 */
	private static function compute_list_fingerprint( array $list_ids ): string {
		$normalised = array_values( array_unique( array_map( 'intval', $list_ids ) ) );
		sort( $normalised, SORT_NUMERIC );
		$fingerprint = \wp_json_encode( $normalised );
		// The values are integers, so a delimiter-joined string is an adequate
		// fallback; the fingerprint is an internal grouping key, never decoded.
		return $fingerprint ? $fingerprint : implode( ',', $normalised );
	}

	/**
	 * Whether a plan group should migrate to a purchase-gated gate.
	 *
	 * True only when every plan in the group requires a purchase. The two gate modes
	 * compose with AND for a logged-in reader — registration mode passes them, then
	 * custom_access restricts them unless they hold a subscription — so activating
	 * paid access on a group that also holds a signup plan would demand the
	 * subscription from everyone. WooCommerce Memberships grants access to a holder
	 * of either plan, so the signup plan's members would silently lose their lists
	 * at cutover. Keeping the most-permissive plan's requirement is the faithful
	 * migration.
	 *
	 * @param array[] $group Plan descriptors, each carrying an 'access_method' key.
	 *
	 * @return bool
	 */
	private static function group_requires_purchase( array $group ): bool {
		return ! array_filter( $group, fn( $g ) => 'purchase' !== $g['access_method'] );
	}
}
