<?php
/**
 * Newspack Insights — envelope data_status deriver (NEWS-2603).
 *
 * Derives a top-level `data_status` for a tab's assembled REST response from
 * the `state` carried by every metric-scalar node in the envelope (see
 * {@see \Newspack\Insights\Conversion_Metric}'s `populated` / `warming` /
 * `error` scalar states). The envelope-level vocabulary is intentionally
 * distinct from the metric-scalar vocabulary: `data_status` is one of
 * `complete` / `warming` / `incomplete`, never `populated` / `error`. A
 * later React banner task consumes this field to decide whether to show a
 * "still warming up" notice.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Pure derivation of the envelope-level data_status from nested metric states.
 */
class Metric_Status {

	/**
	 * Derive the envelope-level status from every metric-scalar `state` found
	 * in the response, recursively. A node is treated as a metric scalar when
	 * it is an array carrying a `state` key — its own children are not
	 * descended into (the `state` key is the leaf signal); any other array is
	 * walked further. Error takes precedence over warming: a single errored
	 * metric makes the whole tab `incomplete` even if others are still
	 * warming up.
	 *
	 * @param array $envelope Assembled tab response (or any sub-array of it).
	 * @return string One of 'complete' | 'warming' | 'incomplete'.
	 */
	public static function derive( array $envelope ): string {
		$has_warming = false;

		foreach ( self::walk_states( $envelope ) as $state ) {
			if ( 'error' === $state ) {
				return 'incomplete';
			}
			if ( 'warming' === $state ) {
				$has_warming = true;
			}
		}

		return $has_warming ? 'warming' : 'complete';
	}

	/**
	 * Yield every metric-scalar `state` value found by recursively walking
	 * array children, without descending into a node once it's identified as
	 * a metric scalar.
	 *
	 * @param array $node Current array node.
	 * @return \Generator<string>
	 */
	private static function walk_states( array $node ) {
		if ( array_key_exists( 'state', $node ) ) {
			yield (string) $node['state'];
			return;
		}

		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				yield from self::walk_states( $child );
			}
		}
	}
}
