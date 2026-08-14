<?php
/**
 * Access source attribution, shared by the ESP contact sync and the GA4 layer.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a passing access rule to source labels, and picks a single label when
 * more than one rule granted access.
 *
 * Deliberately free of request-context coupling: the ESP walks every published
 * gate for a reader, while the GA4 layer walks only the gates on the current
 * post. Both need the same rule-to-label mapping and the same precedence, and
 * nothing else in common. (Request-scoped state does live here — see
 * $owned_subscriptions_memo — it simply does not depend on which surface called.)
 */
final class Access_Attribution {

	/**
	 * Generic source labels in precedence order, strongest first.
	 *
	 * Any label absent from this list is a product name, which outranks all of
	 * them: naming the product a reader bought is more informative than saying
	 * they bought something. `subscription` and `one_time_purchase` sit at the
	 * top because they still mean ownership, only with the product unresolved.
	 *
	 * Product names share this namespace, so a product literally named
	 * `subscription`, `group`, `institution`, `domain` or `reader_data` is read
	 * as the generic label and demoted accordingly. Left as is: the collision
	 * needs a publisher to name a product after a vocabulary term, and the
	 * alternative — tagging labels by origin — would push a structure through
	 * every caller to fix a case nobody has hit.
	 *
	 * @var string[]
	 */
	const GENERIC_PRECEDENCE = [
		'subscription',
		'one_time_purchase',
		'group',
		'institution',
		'domain',
		'reader_data',
	];

	/**
	 * Request-scoped memo of a reader's active subscriptions, keyed on user ID
	 * and payment-recovery grace. Request-scoped on purpose: a purchase
	 * mid-request should not be served a stale answer on the next one.
	 *
	 * @var array<string,\WC_Subscription[]>
	 */
	private static $owned_subscriptions_memo = [];

	/**
	 * Clear the request memo. Used by tests and long-running CLI processes,
	 * where one PHP process spans many readers.
	 */
	public static function reset_memo() {
		self::$owned_subscriptions_memo = [];
	}

	/**
	 * The reader's active subscriptions, loaded once per request.
	 *
	 * Loads the reader's subscriptions once instead of asking "do you own a
	 * subscription with product N?" once per product in the rule — each of
	 * those probes re-runs the full ownership query on its own.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $grace   Whether payment-recovery grace applies.
	 * @return \WC_Subscription[] Subscriptions, keyed by subscription ID.
	 */
	private static function get_owned_subscriptions( $user_id, $grace ) {
		$memo_key = $user_id . ':' . ( $grace ? '1' : '0' );
		if ( isset( self::$owned_subscriptions_memo[ $memo_key ] ) ) {
			return self::$owned_subscriptions_memo[ $memo_key ];
		}

		$subscriptions = [];
		if ( function_exists( 'wcs_get_subscription' ) ) {
			$subscription_ids = WooCommerce_Connection::get_active_subscriptions_for_user( $user_id, [], $grace );
			foreach ( $subscription_ids as $subscription_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				if ( $subscription ) {
					$subscriptions[ $subscription_id ] = $subscription;
				}
			}
		}

		self::$owned_subscriptions_memo[ $memo_key ] = $subscriptions;
		return self::$owned_subscriptions_memo[ $memo_key ];
	}

	/**
	 * Ask the ownership filter, product by product, which one granted access.
	 *
	 * The owned-subscriptions intersection only sees subscriptions recorded on
	 * this site. On a Newspack Network node the reader's subscription lives on
	 * a sibling site and is answered by newspack-network's
	 * `newspack_access_rules_has_active_subscription` callback, so there is no
	 * local record for that intersection to match and the label would degrade
	 * to a bare `subscription`. Probing runs the filter and gets the name.
	 *
	 * Only reached when the intersection named nothing at all, so a locally
	 * owned subscription — the common case — still resolves in a single
	 * ownership lookup and never pays for this loop.
	 *
	 * @param array $product_ids Product IDs from the rule's value.
	 * @param int   $user_id     User ID.
	 * @return string[] Names of the products the filter grants, possibly empty.
	 */
	private static function probe_product_names( $product_ids, $user_id ) {
		$names = [];
		foreach ( $product_ids as $product_id ) {
			if ( ! Access_Rules::has_active_subscription( $user_id, [ $product_id ], true ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$names[] = html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $names;
	}

	/**
	 * Pick the single strongest label from a set of source labels.
	 *
	 * @param string[] $labels Source labels collected from passing rules.
	 * @return string The winning label, or '' when there is nothing to attribute.
	 */
	public static function pick_primary( $labels ) {
		if ( empty( $labels ) ) {
			return '';
		}

		// Sorting first keeps the answer stable when a reader owns more than one
		// product, so the same reader and gate never report different values.
		sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );

		foreach ( $labels as $label ) {
			if ( ! in_array( $label, self::GENERIC_PRECEDENCE, true ) ) {
				return $label;
			}
		}

		foreach ( self::GENERIC_PRECEDENCE as $candidate ) {
			if ( in_array( $candidate, $labels, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Map an access rule slug and value to source labels.
	 *
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @param array  $context Evaluation context the gate's rules were evaluated
	 *                        under, from User_Gate_Access::evaluate_gate_for_user().
	 * @return string[] Source labels.
	 */
	public static function get_source_labels( $slug, $value, $user_id, $context = [] ) {
		switch ( $slug ) {
			case 'subscription':
				if ( ! is_array( $value ) || ! function_exists( 'wc_get_product' ) ) {
					return [ 'subscription' ];
				}
				// These re-run the rule callback to work out *which* subscription
				// granted access, so they must see the same gate settings the rule
				// itself was evaluated under. Called bare they would fall back to
				// the callback's own defaults — notably grace-ON — and attribute
				// access to an in-recovery subscription on a gate whose publisher
				// turned payment-recovery grace off.
				return Access_Rules::with_evaluation_context(
					$context,
					function () use ( $value, $user_id ) {
						// Determine ownership first so an owner of a sub matching an
						// "any subscription" rule (empty $value) isn't mislabeled as
						// `group` by the non-strict check below.
						if ( Access_Rules::has_active_subscription( $user_id, $value, true ) ) {
							$grace         = (bool) Access_Rules::get_evaluation_context( 'payment_recovery_grace', true );
							$subscriptions = self::get_owned_subscriptions( $user_id, $grace );
							$names         = [];
							foreach ( $value as $product_id ) {
								foreach ( $subscriptions as $subscription ) {
									if ( ! $subscription->has_product( $product_id ) ) {
										continue;
									}
									$product = wc_get_product( $product_id );
									if ( $product ) {
										$names[] = html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
									}
									break;
								}
							}
							if ( empty( $names ) ) {
								$names = self::probe_product_names( $value, $user_id );
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
					}
				);

			case 'one_time_purchase':
				$sanitized = Access_Rules::sanitize_one_time_purchase_value( $value );
				if ( empty( $sanitized['product_ids'] ) || ! function_exists( 'wc_get_product' ) ) {
					return [ 'one_time_purchase' ];
				}
				// Probe per product to find which one granted access.
				// Access_Rules::has_one_time_purchase() memoizes per user and value,
				// and wc_customer_bought_product() is cached by WooCommerce, so the
				// repeated calls stay cheap.
				$names = [];
				foreach ( $sanitized['product_ids'] as $product_id ) {
					$single = array_merge( $sanitized, [ 'product_ids' => [ $product_id ] ] );
					if ( ! Access_Rules::has_one_time_purchase( $user_id, $single ) ) {
						continue;
					}
					$product = wc_get_product( $product_id );
					if ( $product ) {
						$names[] = html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					}
				}
				return ! empty( $names ) ? $names : [ 'one_time_purchase' ];

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
}
