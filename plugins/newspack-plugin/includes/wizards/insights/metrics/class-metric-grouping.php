<?php
/**
 * Newspack Insights — shared metric grouping helper (NEWS-2591 / NEWS-2580).
 *
 * Pure fold of windowed conversion records into grouped
 * { value, count, amount } rows for the "by campaign" tables and any future
 * group-by-order-meta table. Generic over record type AND meta key: callers
 * pass rows + which key groups + which key sums. Both the Subscribers (Tab 6)
 * and Donors (Tab 7) by-campaign tables call this; only the reader differs.
 *
 * Mirrors the pure-static, directly-testable shape of
 * {@see Subscribers_Metric::bucket_attributed_subscription_orders()}.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Shared grouping fold for order-meta-keyed Insights tables.
 */
class Metric_Grouping {

	/**
	 * Fold per-record rows into grouped { value, count, amount } rows.
	 *
	 * @param array<int, array<string, mixed>> $rows       One row per conversion in the window.
	 * @param string                           $group_key  Row key holding the grouping value, e.g. 'utm_campaign'.
	 * @param string                           $amount_key Row key holding the numeric amount to sum, e.g. 'revenue'.
	 *                                                      Pass '' for count-only (amount always 0.0).
	 * @param array                            $opts       Grouping options (all optional):
	 *   - untagged_label: non-null string => rows whose normalized value is '' collapse into one trailing
	 *       row with this label (is_untagged: true). Null/omitted => untagged rows are dropped.
	 *   - normalize: value normalizer applied before grouping; defaults to trim(). Return '' to route a
	 *       value into the untagged bucket.
	 *   - limit: if > 0, keep top N tagged rows and fold the rest into one 'other' row (after ranked rows,
	 *       before untagged). Omit/0 for the full table.
	 *   - other_label: label for the folded-tail row; defaults to '(other)'.
	 *
	 * @return array<int, array{value: string, count: int, amount: float, is_untagged: bool}>
	 *   Ranked count desc, amount desc, value asc. 'other' row (if any) after ranked rows; untagged row
	 *   (if enabled) always last.
	 */
	public static function group_records_by_key( array $rows, string $group_key, string $amount_key = '', array $opts = [] ): array {
		$untagged_label = array_key_exists( 'untagged_label', $opts ) ? $opts['untagged_label'] : null;
		$normalize      = $opts['normalize'] ?? 'trim';
		$limit          = isset( $opts['limit'] ) ? (int) $opts['limit'] : 0;
		$other_label    = $opts['other_label'] ?? '(other)';

		$tagged   = [];
		$untagged = [
			'count'  => 0,
			'amount' => 0.0,
		];

		foreach ( $rows as $row ) {
			$raw    = isset( $row[ $group_key ] ) ? (string) $row[ $group_key ] : '';
			$value  = (string) call_user_func( $normalize, $raw );
			$amount = ( '' !== $amount_key && isset( $row[ $amount_key ] ) ) ? (float) $row[ $amount_key ] : 0.0;

			if ( '' === $value ) {
				++$untagged['count'];
				$untagged['amount'] += $amount;
				continue;
			}

			if ( ! isset( $tagged[ $value ] ) ) {
				$tagged[ $value ] = [
					'count'  => 0,
					'amount' => 0.0,
				];
			}
			++$tagged[ $value ]['count'];
			$tagged[ $value ]['amount'] += $amount;
		}

		$ranked = [];
		foreach ( $tagged as $value => $agg ) {
			$ranked[] = [
				'value'       => (string) $value,
				'count'       => $agg['count'],
				'amount'      => $agg['amount'],
				'is_untagged' => false,
			];
		}

		usort(
			$ranked,
			static function ( $a, $b ) {
				return [ $b['count'], $b['amount'], $a['value'] ] <=> [ $a['count'], $a['amount'], $b['value'] ];
			}
		);

		if ( $limit > 0 && count( $ranked ) > $limit ) {
			$head  = array_slice( $ranked, 0, $limit );
			$tail  = array_slice( $ranked, $limit );
			$other = [
				'value'       => $other_label,
				'count'       => 0,
				'amount'      => 0.0,
				'is_untagged' => false,
			];
			foreach ( $tail as $r ) {
				$other['count']  += $r['count'];
				$other['amount'] += $r['amount'];
			}
			$ranked   = $head;
			$ranked[] = $other;
		}

		if ( null !== $untagged_label && $untagged['count'] > 0 ) {
			$ranked[] = [
				'value'       => $untagged_label,
				'count'       => $untagged['count'],
				'amount'      => $untagged['amount'],
				'is_untagged' => true,
			];
		}

		return $ranked;
	}
}
