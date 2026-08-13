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

	/**
	 * Resolve a newsletter list's public (ESP) list ID.
	 *
	 * The post type is checked first so a stale or mistyped ID returns null instead
	 * of reaching Subscription_List, whose constructor throws for anything that is
	 * not a list.
	 *
	 * @param int $list_id The list post ID.
	 *
	 * @return string|null The public list ID, or null when it cannot be resolved.
	 */
	private static function get_public_id( int $list_id ): ?string {
		if ( \get_post_type( $list_id ) !== self::get_list_cpt() ) {
			return null;
		}
		if ( ! class_exists( 'Newspack\Newsletters\Subscription_List' ) ) {
			return null;
		}
		try {
			$list = new \Newspack\Newsletters\Subscription_List( $list_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		$public_id = $list->get_public_id();
		return $public_id ? (string) $public_id : null;
	}

	/**
	 * The public list IDs shown in the post-checkout newsletter signup modal.
	 *
	 * Mirrors the lookup the pre-Access-Control WooCommerce Memberships integration
	 * used to decide which lists to leave to reader opt-in. With custom lists off the
	 * modal offers every list rather than a chosen set, so the saved selection is not
	 * a carve-out and the set is empty.
	 *
	 * @return string[] Public list IDs.
	 */
	private static function get_modal_public_ids(): array {
		if ( ! method_exists( 'Newspack\Reader_Activation', 'get_settings' ) ) {
			return [];
		}
		$settings = \Newspack\Reader_Activation::get_settings();
		if ( empty( $settings['use_custom_lists'] ) || empty( $settings['newsletter_lists'] ) ) {
			return [];
		}
		$public_ids = [];
		foreach ( $settings['newsletter_lists'] as $list ) {
			if ( isset( $list['id'] ) ) {
				$public_ids[] = (string) $list['id'];
			}
		}
		return array_values( array_unique( $public_ids ) );
	}

	/**
	 * Derive the site-wide auto-signup setting from the restricted lists.
	 *
	 * Before Access Control, activating a membership auto-subscribed the member to
	 * every list the plan restricted, except lists shown in the post-checkout signup
	 * modal, which were left to reader opt-in. `newspack_premium_newsletters_auto_signup`
	 * is a single site-wide option, so that per-list distinction only survives when
	 * every restricted list falls on the same side. A split returns a null value:
	 * one flag cannot express it, and either guess has a victim — on subscribes
	 * readers who opted out, off drops readers who expected the list.
	 *
	 * A list whose public ID cannot be resolved is reported separately and counted as
	 * non-modal, matching the pre-Access-Control default.
	 *
	 * @param int[] $list_ids The restricted newsletter list post IDs.
	 *
	 * @return array{value: bool|null, modal: int[], non_modal: int[], unresolved: int[]}
	 */
	private static function derive_auto_signup( array $list_ids ): array {
		$modal_public_ids = self::get_modal_public_ids();
		$modal            = [];
		$non_modal        = [];
		$unresolved       = [];

		foreach ( $list_ids as $list_id ) {
			$list_id   = (int) $list_id;
			$public_id = self::get_public_id( $list_id );
			if ( null === $public_id ) {
				$unresolved[] = $list_id;
				$non_modal[]  = $list_id;
				continue;
			}
			if ( in_array( $public_id, $modal_public_ids, true ) ) {
				$modal[] = $list_id;
			} else {
				$non_modal[] = $list_id;
			}
		}

		if ( empty( $modal ) && empty( $non_modal ) ) {
			$value = null;
		} elseif ( empty( $modal ) ) {
			$value = true;
		} elseif ( empty( $non_modal ) ) {
			$value = false;
		} else {
			$value = null;
		}

		return [
			'value'      => $value,
			'modal'      => $modal,
			'non_modal'  => $non_modal,
			'unresolved' => $unresolved,
		];
	}

	/**
	 * Which of the given IDs are not newsletter list posts.
	 *
	 * The evaluator matches a 'newsletters' rule by comparing the list post's own ID
	 * against the rule values, so an ID belonging to a deleted post or to something
	 * that is not a list matches nothing and leaves that list open.
	 *
	 * @param int[] $list_ids Newsletter list post IDs.
	 *
	 * @return int[] The IDs that do not resolve to a newsletter list.
	 */
	private static function list_ids_that_do_not_resolve( array $list_ids ): array {
		$list_cpt = self::get_list_cpt();
		$missing  = [];
		foreach ( $list_ids as $list_id ) {
			if ( \get_post_type( (int) $list_id ) !== $list_cpt ) {
				$missing[] = (int) $list_id;
			}
		}
		return $missing;
	}

	/**
	 * Describe how many of a gate's restricted lists fail to resolve.
	 *
	 * Shared by the live and dry-run passes so both report the same wording.
	 *
	 * @param int[] $list_ids     The gate's restricted list IDs.
	 * @param int[] $unresolvable The subset that does not resolve.
	 *
	 * @return string|null The problem, or null when every list resolves.
	 */
	private static function describe_unresolvable_lists( array $list_ids, array $unresolvable ): ?string {
		if ( empty( $unresolvable ) ) {
			return null;
		}
		if ( count( $unresolvable ) === count( $list_ids ) ) {
			return sprintf(
				'none of its restricted lists (%s) exist as newsletter lists',
				implode( ', ', $unresolvable )
			);
		}
		return sprintf(
			'%d of its %d restricted lists (%s) do not exist as newsletter lists, so those lists stay unrestricted',
			count( $unresolvable ),
			count( $list_ids ),
			implode( ', ', $unresolvable )
		);
	}

	/**
	 * Re-read a freshly written gate and report why it would fail to restrict.
	 *
	 * Mirrors the conditions Content_Restriction_Control::get_post_gates() and
	 * is_post_restricted() apply to a newsletter list post, so a gate that passes
	 * here is one the evaluator can act on for the readers the source plan
	 * restricted. Migrated gates stay dormant until WooCommerce Memberships is
	 * deactivated, so without this an unenforceable gate would look migrated for as
	 * long as it takes someone to notice at cutover.
	 *
	 * Layout checks are deliberately absent: this command never authors layouts, a
	 * premium gate controls list visibility rather than rendering an article gate,
	 * and create_gate() seeds both layout posts.
	 *
	 * @param int  $gate_id      The gate post ID.
	 * @param bool $has_purchase Whether every plan behind this gate requires a purchase.
	 *
	 * @return string[] Human-readable problems; empty when the gate is enforceable.
	 */
	private static function verify_migrated_gate( int $gate_id, bool $has_purchase = false ): array {
		$issues = [];

		if ( 'publish' !== \get_post_status( $gate_id ) ) {
			$issues[] = 'the gate is not published';
		}

		if ( ! \get_post_meta( $gate_id, 'is_newsletter', true ) ) {
			$issues[] = 'it is missing the is_newsletter flag, so the evaluator judges list posts against the content gate bucket and this gate never applies';
		}

		$content_rules = \Newspack\Content_Rules::get_gate_content_rules( $gate_id );
		$list_ids      = [];
		foreach ( $content_rules as $content_rule ) {
			if ( 'newsletters' === ( $content_rule['slug'] ?? '' ) ) {
				$list_ids = array_merge( $list_ids, array_map( 'intval', (array) ( $content_rule['value'] ?? [] ) ) );
			}
		}
		$list_ids = array_values( array_unique( $list_ids ) );

		if ( empty( $list_ids ) ) {
			// get_gate_content_rules() drops rules with an empty value, so a gate can be
			// written with rules and still evaluate as having none — say which it is.
			$written_rules = \get_post_meta( $gate_id, 'content_rules', true );
			$issues[]      = empty( $written_rules )
				? 'it has no content rules'
				: 'none of its content rules select a newsletter list';
		} else {
			$unresolvable = self::describe_unresolvable_lists( $list_ids, self::list_ids_that_do_not_resolve( $list_ids ) );
			if ( null !== $unresolvable ) {
				$issues[] = $unresolvable;
			}
		}

		$registration  = \Newspack\Content_Gate::get_registration_settings( $gate_id );
		$custom_access = \Newspack\Content_Gate::get_custom_access_settings( $gate_id );
		if ( empty( $registration['active'] ) && empty( $custom_access['active'] ) ) {
			$issues[] = 'neither the registration nor the paid access mode is active';
		}

		// A plan that required a purchase must migrate to a gate that gates on the
		// purchase. Registration mode alone stops nobody who has an account, so a paid
		// plan whose paid access mode is missing or unconstrained turns into a premium
		// list any reader can join by registering a free account.
		if ( $has_purchase ) {
			if ( empty( $custom_access['active'] ) ) {
				$issues[] = 'it migrates a plan that requires a purchase, but its paid access mode is not active — any registered reader would keep the list';
			} elseif ( empty( $custom_access['access_rules'] ) ) {
				$issues[] = 'its paid access mode is active but has no access rules, so it asks for no purchase — any registered reader would keep the list';
			}
		}

		return $issues;
	}

	/**
	 * Predict migration issues from group data alone, without writing anything.
	 *
	 * The computable subset of verify_migrated_gate(). Called in dry-run mode so the
	 * planning pass surfaces the same warnings --live would.
	 *
	 * @param int[] $list_ids     The group's restricted list IDs.
	 * @param bool  $has_purchase Whether every plan in the group requires a purchase.
	 * @param int[] $product_ids  Merged parent product IDs for the paid access mode.
	 *
	 * @return string[] Human-readable problems; empty when no issues are predicted.
	 */
	private static function compute_pre_write_issues( array $list_ids, bool $has_purchase, array $product_ids ): array {
		$issues = [];

		$unresolvable = self::describe_unresolvable_lists( $list_ids, self::list_ids_that_do_not_resolve( $list_ids ) );
		if ( null !== $unresolvable ) {
			$issues[] = $unresolvable;
		}

		if ( $has_purchase && empty( $product_ids ) ) {
			$issues[] = 'its paid access mode will have no access rules (no product IDs remain after stripping product variations), so it will ask for no purchase — any registered reader would keep the list';
		}

		return $issues;
	}
}
