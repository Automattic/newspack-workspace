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
use Newspack\Access_Rules;
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
	 */
	public static function reset_cache() {
		self::$custom_access_gates_cache = null;
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
	 * @param callable $resolver    Receives ($slug, $value, $user_id) and returns string[] of labels.
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
					foreach ( $resolver( $rule['slug'], $rule['value'], $user_id ) as $label ) {
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
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @return array Source labels.
	 */
	private static function get_source_labels( $slug, $value, $user_id ) {
		switch ( $slug ) {
			case 'subscription':
				if ( ! is_array( $value ) || ! function_exists( 'wc_get_product' ) ) {
					return [ 'subscription' ];
				}
				// Determine ownership first so an owner of a sub matching an
				// "any subscription" rule (empty $value) isn't mislabeled as
				// `group` by the non-strict check below.
				if ( Access_Rules::has_active_subscription( $user_id, $value, true ) ) {
					$names = [];
					foreach ( $value as $product_id ) {
						if ( Access_Rules::has_active_subscription( $user_id, [ $product_id ], true ) ) {
							$product = wc_get_product( $product_id );
							if ( $product ) {
								$names[] = html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							}
						}
					}
					return ! empty( $names ) ? $names : [ 'subscription' ];
				}
				// Not an owner — check group subscription membership.
				if ( Access_Rules::has_active_subscription( $user_id, $value ) ) {
					return [ 'group' ];
				}
				// They might still have access via the
				// `newspack_access_rules_has_active_subscription` filter hook.
				return [ 'subscription' ];

			case 'email_domain':
				return [ 'domain' ];

			case 'institution':
				return [ 'institution' ];

			case 'reader_data':
				return [ 'reader_data' ];

			default:
				return [];
		}
	}

	/**
	 * Map an access rule slug and value to group labels.
	 *
	 * Delegates name resolution to `Group_Subscription::get_group_names_for_user()` and
	 * `Institution::get_matching_names_for_user()` so the GA4 helper and other callers
	 * share the same logic (memoization, status filters, name decoding).
	 *
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @return array Group labels.
	 */
	private static function get_group_labels( $slug, $value, $user_id ) {
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
