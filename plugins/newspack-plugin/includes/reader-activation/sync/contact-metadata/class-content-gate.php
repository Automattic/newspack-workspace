<?php
/**
 * Content Gate contact metadata fields.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation\Sync\Contact_Metadata;

use Newspack\Reader_Activation\Sync\Contact_Metadata;
use Newspack\Reader_Activation\Sync\Legacy_Metadata;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Access_Attribution;
use Newspack\Content_Gate as Content_Gate_CPT;
use Newspack\Group_Subscription;
use Newspack\Institution;
use Newspack\User_Gate_Access;

defined( 'ABSPATH' ) || exit;

/**
 * Content Gate metadata class.
 */
class Content_Gate extends Contact_Metadata {

	/**
	 * Cached custom access gates for the current request.
	 *
	 * @var array|null
	 */
	private static $custom_access_gates_cache = null;

	/**
	 * Reset the cached custom access gates.
	 *
	 * Also clears the attribution memo of readers' owned subscriptions. A sync
	 * run is one PHP process spanning many readers, and a subscription that
	 * activates between two syncs in that process would otherwise be missed —
	 * degrading the source label from the product's name to a bare
	 * `subscription`.
	 *
	 * Called at every batch boundary of the bulk contact loops, alongside the
	 * object cache flush — see RAS_Contact_Sync::batch_boundary_pause().
	 */
	public static function reset_cache() {
		self::$custom_access_gates_cache = null;
		Access_Attribution::reset_memo();
	}

	/**
	 * Whether or not the metadata fields of this class are available to be synced.
	 *
	 * @return boolean
	 */
	public static function is_available() {
		return Content_Gate_CPT::is_newspack_feature_enabled();
	}

	/**
	 * The name of the metadata class, used as a section name for the fields handled by this class when syncing and in the UI for selecting which fields to sync.
	 *
	 * @return string
	 */
	public static function get_section_name() {
		return __( 'Content Access', 'newspack-plugin' );
	}

	/**
	 * The fields handled by this metadata class.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return [
			'Content_Access'        => 'Content Access',
			'Content_Access_Source' => 'Content Access Source',
			'Content_Access_Group'  => 'Content Access Group',
		];
	}

	/**
	 * Get the metadata for the given user, customer or order.
	 *
	 * @return array
	 */
	public function get_metadata() {
		if ( ! $this->user ) {
			return [];
		}

		$custom_access_gates = self::get_custom_access_gates();

		if ( empty( $custom_access_gates ) ) {
			$metadata = [
				'Content_Access'        => '',
				'Content_Access_Source' => '',
				'Content_Access_Group'  => '',
			];
		} else {
			$evaluations = [];
			foreach ( $custom_access_gates as $gate ) {
				$evaluations[] = User_Gate_Access::evaluate_gate_for_user( $gate, $this->user->ID );
			}

			$user_id  = $this->user->ID;
			$metadata = [
				'Content_Access'        => self::has_content_access( $evaluations ) ? 'Yes' : 'No',
				'Content_Access_Source' => implode( ', ', self::collect_labels( $evaluations, $user_id, [ self::class, 'get_source_labels' ] ) ),
				'Content_Access_Group'  => implode( ', ', self::collect_labels( $evaluations, $user_id, [ self::class, 'get_group_labels' ] ) ),
			];
		}

		// In legacy mode the main sync path does not run a normalize step on
		// the merged contact, so each metadata class must return keys in the
		// prefixed shape (matching Legacy_Basic / Legacy_Payment). Without this,
		// raw Content_Access keys are silently dropped at the ESP push.
		if ( 'legacy' === Metadata::get_version() ) {
			$normalized = Legacy_Metadata::normalize_contact_data( [ 'metadata' => $metadata ] );
			return $normalized['metadata'] ?? [];
		}

		return $metadata;
	}

	/**
	 * Get published gates with active custom access, cached for the request.
	 *
	 * @return array
	 */
	private static function get_custom_access_gates() {
		if ( null === self::$custom_access_gates_cache ) {
			$gates                          = Content_Gate_CPT::get_gates( Content_Gate_CPT::GATE_CPT, 'publish' );
			self::$custom_access_gates_cache = array_filter(
				$gates,
				function ( $gate ) {
					return ! is_wp_error( $gate ) && ! empty( $gate['custom_access']['active'] );
				}
			);
		}

		return self::$custom_access_gates_cache;
	}

	/**
	 * Whether any evaluated gate grants the user bypass access.
	 *
	 * @param array $evaluations Results from User_Gate_Access::evaluate_gate_for_user().
	 * @return bool
	 */
	private static function has_content_access( $evaluations ) {
		foreach ( $evaluations as $result ) {
			if ( $result['can_bypass'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk gate evaluations and collect labels via a per-rule resolver.
	 *
	 * @param array    $evaluations Results from User_Gate_Access::evaluate_gate_for_user().
	 * @param int      $user_id     User ID.
	 * @param callable $resolver    Receives ($slug, $value, $user_id, $context) and returns string[] of labels.
	 * @return array Sorted, deduplicated labels.
	 */
	private static function collect_labels( $evaluations, $user_id, $resolver ) {
		$labels_set = [];

		foreach ( $evaluations as $result ) {
			if ( ! $result['can_bypass'] ) {
				continue;
			}
			foreach ( $result['groups'] as $group ) {
				if ( ! $group['passes'] ) {
					continue;
				}
				foreach ( $group['rules'] as $rule ) {
					if ( ! $rule['passes'] ) {
						continue;
					}
					foreach ( $resolver( $rule['slug'], $rule['value'], $user_id, $result['context'] ?? [] ) as $label ) {
						$labels_set[ $label ] = true;
					}
				}
			}
		}

		$labels = array_keys( $labels_set );
		sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
		return $labels;
	}

	/**
	 * Map an access rule slug and value to source labels.
	 *
	 * The mapping itself lives in `Access_Attribution`, shared with the GA4
	 * layer so both consumers attribute a passing rule to the same source.
	 *
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @param array  $context Evaluation context the gate's rules were evaluated under,
	 *                        from User_Gate_Access::evaluate_gate_for_user().
	 * @return array Source labels.
	 */
	private static function get_source_labels( $slug, $value, $user_id, $context = [] ) {
		return Access_Attribution::get_source_labels( $slug, $value, $user_id, $context );
	}

	/**
	 * Map an access rule slug and value to group labels.
	 *
	 * Delegates name resolution to `Group_Subscription::get_group_names_for_user()` and
	 * `Institution::get_matching_names_for_user()` so the GA4 helper and other callers
	 * share the same logic (memoization, status filters, name decoding).
	 *
	 * Group names come from the shared, request-memoized helper, which counts only
	 * subscriptions with an active status. A group subscription in the payment-retry
	 * window therefore grants access without contributing a group name, so `$context`
	 * has nothing to switch on here — it is accepted to keep the resolver signature
	 * uniform. Aligning the group name with the grace toggle means threading context
	 * through that shared, cross-feature helper (also used by GA4); tracked in NPPD-2133.
	 *
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @param array  $context Evaluation context the gate's rules were evaluated under.
	 * @return array Group labels.
	 */
	private static function get_group_labels( $slug, $value, $user_id, $context = [] ) {
		switch ( $slug ) {
			case 'subscription':
				// An empty/non-array $value mirrors Access_Rules::has_active_subscription's
				// "any active subscription" semantics — every active group sub matches.
				$product_filter = is_array( $value ) && ! empty( $value ) ? $value : null;
				return Group_Subscription::get_group_names_for_user( $user_id, $product_filter );

			case 'institution':
				// A malformed institution rule (missing/empty/scalar value) matches everyone
				// per Institution::evaluate(), but there's no specific institution to attribute.
				if ( ! is_array( $value ) || empty( $value ) ) {
					return [];
				}
				return Institution::get_matching_names_for_user( $user_id, $value );

			default:
				return [];
		}
	}
}
