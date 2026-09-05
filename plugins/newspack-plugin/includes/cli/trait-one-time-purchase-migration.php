<?php
/**
 * Shared one-time purchase support for the membership migration commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a WooCommerce Memberships plan's products and access length as the gate's
 * one_time_purchase rule needs them.
 *
 * A plan grants access through its products without distinguishing a subscription
 * from a single purchase; the two gate rules do. Both migration commands therefore
 * need the same split and the same duration reading, and they are here rather than
 * in each command so the two cannot answer the question differently.
 *
 * `resolve_group_duration()` calls `self::resolve_product_ids()`, which each command
 * defines for itself: they disagree about product variations, and that disagreement
 * is deliberate.
 */
trait One_Time_Purchase_Migration {
	/**
	 * Read a --one-time-duration value into the pair the gate rule stores.
	 *
	 * Only the units the rule evaluates are accepted. Taking "1year" and silently
	 * storing an unrecognised unit would write a rule that grants nobody access, so
	 * an unusable value is refused rather than approximated.
	 *
	 * @param mixed $value The raw flag value.
	 *
	 * @return array{duration_value:int,duration_unit:string}|null Null when unusable.
	 */
	private static function parse_one_time_duration( $value ): ?array {
		$value = trim( (string) $value );
		if ( 'forever' === $value ) {
			return [
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			];
		}
		if ( preg_match( '/^([1-9][0-9]*)\s*(days|months)$/', $value, $matches ) ) {
			return [
				'duration_value' => (int) $matches[1],
				'duration_unit'  => $matches[2],
			];
		}
		return null;
	}

	/**
	 * The one-time purchase duration a group's gate should carry, and the plans that
	 * need one.
	 *
	 * Only plans that actually grant through a one-time product are consulted: a
	 * subscription-only plan sharing the group has no say in how long a purchase
	 * lasts, and letting it vote would refuse groups that have no ambiguity.
	 *
	 * Plans disagreeing on the length is resolved the way this command resolves every
	 * other disagreement within a group — most permissive wins, because WooCommerce
	 * Memberships grants access from any one plan and the gate has a single rule to
	 * say it with. The caller reports the choice rather than making it silently.
	 *
	 * @param array[]    $group    Plan descriptors.
	 * @param array|null $override Operator-supplied duration, or null to derive.
	 *
	 * @return array{duration:?array,plans:string[],conflict:?string} 'duration' is null
	 *         when 'plans' is non-empty and no length could be derived: the caller stops
	 *         the run over that.
	 */
	private static function resolve_group_duration( array $group, ?array $override ): array {
		$plans     = [];
		$durations = [];
		// A group that does not require a purchase writes no paid access rules at all,
		// so it has no one-time rule to give a duration to. Asking anyway makes a plan
		// with no derivable duration stop a run over a rule that was never going to be
		// written. What such a group loses is reported on its own terms instead.
		if ( ! self::group_requires_purchase( $group ) ) {
			return [
				'duration' => null,
				'plans'    => [],
				'conflict' => null,
			];
		}
		foreach ( $group as $plan ) {
			if ( 'purchase' !== $plan['access_method'] ) {
				continue;
			}
			if ( empty( self::resolve_product_ids( [ $plan ] )['one_time_ids'] ) ) {
				continue;
			}
			$plans[]     = $plan['name'];
			$durations[] = $plan['one_time_duration'] ?? null;
		}

		if ( empty( $plans ) ) {
			return [
				'duration' => null,
				'plans'    => [],
				'conflict' => null,
			];
		}
		if ( null !== $override ) {
			return [
				'duration' => $override,
				'plans'    => $plans,
				'conflict' => null,
			];
		}
		if ( in_array( null, $durations, true ) ) {
			return [
				'duration' => null,
				'plans'    => $plans,
				'conflict' => null,
			];
		}

		usort( $durations, fn( $a, $b ) => self::duration_rank( $b ) <=> self::duration_rank( $a ) );
		$chosen   = $durations[0];
		$distinct = array_unique( array_map( fn( $d ) => self::describe_duration( $d ), $durations ) );

		return [
			'duration' => $chosen,
			'plans'    => $plans,
			'conflict' => count( $distinct ) > 1
				? sprintf( '%s, so the gate keeps the longest (%s)', implode( ' and ', $distinct ), self::describe_duration( $chosen ) )
				: null,
		];
	}

	/**
	 * Order two durations by how much access they grant.
	 *
	 * Months are ranked at 30 days apiece. The comparison only ever picks between
	 * durations, never computes an expiry, so the approximation cannot reach a reader:
	 * the value the gate stores is the plan's own, untouched.
	 *
	 * @param array $duration A duration_value/duration_unit pair.
	 *
	 * @return int
	 */
	private static function duration_rank( array $duration ): int {
		if ( 'forever' === $duration['duration_unit'] ) {
			return PHP_INT_MAX;
		}
		return 'months' === $duration['duration_unit']
			? (int) $duration['duration_value'] * 30
			: (int) $duration['duration_value'];
	}

	/**
	 * Render a duration for an operator.
	 *
	 * @param array $duration A duration_value/duration_unit pair.
	 *
	 * @return string
	 */
	private static function describe_duration( array $duration ): string {
		return 'forever' === $duration['duration_unit']
			? 'forever'
			: sprintf( '%d %s', $duration['duration_value'], $duration['duration_unit'] );
	}

	/**
	 * Whether a product grants access by subscription rather than by a single purchase.
	 *
	 * A membership plan grants on its products without caring which kind they are;
	 * the two gate rules do. Routing a one-time product into the subscription rule
	 * writes a condition its buyers can never satisfy, so the split has to happen
	 * here rather than being assumed.
	 *
	 * WooCommerce Subscriptions is asked directly when it is loaded, because it is
	 * the authority on its own product types and handles variations. The type check
	 * is the fallback for a site whose plan products outlived the plugin: those
	 * products read as simple, and a one-time rule over them at least grants the
	 * readers who bought them, where a subscription rule would grant nobody.
	 *
	 * @param int $product_id Product or variation post ID.
	 *
	 * @return bool
	 */
	private static function is_subscription_product( int $product_id ): bool {
		$product = \wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			return (bool) \WC_Subscriptions_Product::is_subscription( $product );
		}
		return $product->is_type( [ 'subscription', 'variable-subscription', 'subscription_variation' ] );
	}

	/**
	 * Split validated product IDs by the gate rule that can carry them.
	 *
	 * @param int[] $product_ids Validated product or variation post IDs.
	 *
	 * @return array{subscription:int[],one_time:int[]} Each list keeps its input order.
	 */
	private static function classify_product_ids( array $product_ids ): array {
		$subscription = [];
		$one_time     = [];
		foreach ( $product_ids as $product_id ) {
			if ( self::is_subscription_product( (int) $product_id ) ) {
				$subscription[] = (int) $product_id;
			} else {
				$one_time[] = (int) $product_id;
			}
		}
		return [
			'subscription' => $subscription,
			'one_time'     => $one_time,
		];
	}

	/**
	 * Read a plan's own access length as a one-time purchase duration.
	 *
	 * The plan already records how long its access lasts, so the migration reads it
	 * rather than asking an operator to restate it — and two plans with different
	 * lengths stay different, which one command-line value could not express.
	 *
	 * The gate rule counts in days, months or forever, while WooCommerce Memberships
	 * also offers weeks and years; those convert exactly (a week is 7 days, a year is
	 * 12 months), so nothing is lost in the translation.
	 *
	 * A plan whose access ends on a fixed calendar date has no duration relative to
	 * the purchase — the same product bought a year apart would grant a year of
	 * access or none. That is the one shape the caller has to be told about rather
	 * than migrate, hence null.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan The plan.
	 *
	 * @return array{duration_value:int,duration_unit:string}|null Null when the plan's
	 *                                                             access ends on a fixed date.
	 */
	private static function derive_one_time_duration( $plan ): ?array {
		if ( 'fixed' === $plan->get_access_length_type() ) {
			return null;
		}
		if ( ! $plan->has_access_length() ) {
			return [
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			];
		}
		$amount = (int) $plan->get_access_length_amount();
		switch ( $plan->get_access_length_period() ) {
			case 'days':
				return [
					'duration_value' => $amount,
					'duration_unit'  => 'days',
				];
			case 'weeks':
				return [
					'duration_value' => $amount * 7,
					'duration_unit'  => 'days',
				];
			case 'months':
				return [
					'duration_value' => $amount,
					'duration_unit'  => 'months',
				];
			case 'years':
				return [
					'duration_value' => $amount * 12,
					'duration_unit'  => 'months',
				];
		}
		return null;
	}
}
