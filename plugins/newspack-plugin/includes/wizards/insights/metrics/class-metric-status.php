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
	 * Derive the envelope-level status from every metric-scalar found in the
	 * response, recursively. Each leaf metric resolves to one of `error` /
	 * `warming` / `populated` (see {@see self::leaf_state()}); error takes
	 * precedence over warming, so a single errored metric makes the whole tab
	 * `incomplete` even if others are still warming up.
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
	 * Yield the resolved state of every metric-scalar found by recursively
	 * walking array children, without descending into a node once it's
	 * identified as a metric scalar.
	 *
	 * A node is a metric-scalar leaf when it carries either a `state` key (the
	 * three-state model, {@see \Newspack\Insights\Conversion_Metric}) or the
	 * universal `computable` marker emitted by every metric shaper — including
	 * the core BigQuery-proxy metrics (Audience's proxy_scalar/proxy_rows)
	 * which predate the three-state model and never set a `state` key.
	 *
	 * @param array $node Current array node.
	 * @return \Generator<string>
	 */
	private static function walk_states( array $node ) {
		if ( array_key_exists( 'state', $node ) || array_key_exists( 'computable', $node ) ) {
			yield self::leaf_state( $node );
			return;
		}

		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				yield from self::walk_states( $child );
			}
		}
	}

	/**
	 * Resolve a single metric-scalar leaf to `error` | `warming` | `populated`.
	 *
	 * A hub/proxy failure surfaces either as an explicit `state` of `error`
	 * (three-state metrics) or as a non-empty `error` message string alongside
	 * `computable:false` (core BigQuery-proxy metrics, which predate the
	 * three-state model). Either means the last data fetch for that metric
	 * failed. A `computable:false` node with no error — an empty cohort, an
	 * overlay, a not-configured metric — is NOT a failure and stays populated.
	 *
	 * The one legacy `error` explicitly excluded is the proxy's "not
	 * configured" code: a never-connected hub is a setup state, not a failed
	 * fetch, so it must not read as `incomplete`.
	 *
	 * @param array $node Metric-scalar leaf node.
	 * @return string One of 'error' | 'warming' | 'populated'.
	 */
	private static function leaf_state( array $node ): string {
		$is_error = ( isset( $node['state'] ) && 'error' === $node['state'] )
			|| (
				! empty( $node['error'] ) && is_string( $node['error'] )
				&& BigQuery_Proxy_Client::ERROR_NOT_CONFIGURED !== ( $node['error_code'] ?? null )
			);

		if ( $is_error ) {
			return 'error';
		}

		if ( isset( $node['state'] ) && 'warming' === $node['state'] ) {
			return 'warming';
		}

		return 'populated';
	}
}
