<?php
/**
 * Newspack Insights — Woo Order Resolver.
 *
 * Joins BigQuery paywall-attempt rows against the local `wp_wc_orders` table to
 * identify which checkout attempts produced a completed order within a fixed
 * time window (default 30 minutes from `attempt_ts`).
 *
 * Input row shape (from the hub's `gates_paywall_*` and `prompts_*_conversion_*`
 * queries):
 *   - `uid` OR `user_pseudo_id` (string) — **interpreted as integer
 *     `wp_wc_orders.customer_id`**. The upstream BQ query projects
 *     `COALESCE(user_id, user_pseudo_id) AS uid` (Prompts; renamed per Copilot
 *     review on newspack-manager-admin#457) or `AS user_pseudo_id` (Gates; not
 *     renamed). The value is a numeric WP user ID for logged-in conversions.
 *     Anonymous conversions (where the value is a non-numeric GA4 pseudo ID)
 *     fail the `(int)` cast to 0 and are silently dropped. The resolver reads
 *     `uid` first and falls back to `user_pseudo_id` so both shapes work.
 *   - `session_id` (string) — passthrough; not used in the Woo join.
 *   - `attempt_ts` (string|int) — GA4 microsecond timestamp (Unix epoch × 10^6).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Woo completion data against BQ paywall attempts.
 */
class Woo_Order_Resolver {

	/**
	 * Default match window in seconds (30 minutes).
	 *
	 * @var int
	 */
	const DEFAULT_WINDOW_SECONDS = 1800;

	/**
	 * Order statuses that count as a completed purchase.
	 *
	 * @var string[]
	 */
	const COMPLETED_STATUSES = [ 'completed', 'processing' ];

	/**
	 * Cache of "matched orders" keyed by (window_seconds + rows fingerprint).
	 *
	 * Avoids re-running the same Woo query for the same row set when caller
	 * asks for count / sum / unique-users back-to-back.
	 *
	 * @var array<string, array<int, array{order_id:int,total:float,customer_id:int}|null>>
	 */
	private array $cache = [];

	/**
	 * Count how many BQ rows match a completed Woo order in the window.
	 *
	 * @param array $rows BQ rows: `[ [ 'uid' (or 'user_pseudo_id'), 'session_id', 'attempt_ts' ], ... ]`.
	 * @param int   $window_seconds Window after `attempt_ts` to look for a completion.
	 * @return int
	 */
	public function count_completed_orders( array $rows, int $window_seconds = self::DEFAULT_WINDOW_SECONDS ): int {
		$matches = $this->match_orders( $rows, $window_seconds );
		return count( array_filter( $matches ) );
	}

	/**
	 * Sum the totals of the first matched order per row.
	 *
	 * @param array $rows BQ rows.
	 * @param int   $window_seconds Window after `attempt_ts`.
	 * @return float
	 */
	public function sum_completed_revenue( array $rows, int $window_seconds = self::DEFAULT_WINDOW_SECONDS ): float {
		$matches = $this->match_orders( $rows, $window_seconds );
		$total   = 0.0;
		foreach ( $matches as $m ) {
			if ( null !== $m ) {
				$total += $m['total'];
			}
		}
		return $total;
	}

	/**
	 * Distinct user count among matched rows.
	 *
	 * @param array $rows BQ rows.
	 * @param int   $window_seconds Window after `attempt_ts`.
	 * @return int
	 */
	public function count_unique_completed_users( array $rows, int $window_seconds = self::DEFAULT_WINDOW_SECONDS ): int {
		$matches = $this->match_orders( $rows, $window_seconds );
		$users   = [];
		foreach ( $matches as $m ) {
			if ( null !== $m ) {
				$users[ $m['customer_id'] ] = true;
			}
		}
		return count( $users );
	}

	/**
	 * For each BQ row, find the first completed Woo order within the window.
	 *
	 * @param array $rows BQ rows.
	 * @param int   $window_seconds Window in seconds.
	 * @return array<int, array{order_id:int,total:float,customer_id:int}|null>
	 */
	private function match_orders( array $rows, int $window_seconds ): array {
		$cache_key = $window_seconds . ':' . md5( wp_json_encode( $rows ) );
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		if ( empty( $rows ) ) {
			$this->cache[ $cache_key ] = [];
			return [];
		}

		// Group attempts by customer_id for one query per customer (efficient on small sets).
		// Prompts queries project the resolved user identifier as `uid` (Copilot review on
		// newspack-manager-admin#457); Gates queries still use `user_pseudo_id`. Read `uid`
		// first with a fallback so both shapes work without a hub-side coordinated rename.
		$by_customer = [];
		foreach ( $rows as $i => $row ) {
			$customer_id = (int) ( $row['uid'] ?? $row['user_pseudo_id'] ?? 0 );
			$attempt_ts  = (int) ( $row['attempt_ts'] ?? 0 );
			if ( $customer_id <= 0 || $attempt_ts <= 0 ) {
				continue;
			}
			$by_customer[ $customer_id ][ $i ] = $attempt_ts;
		}

		if ( empty( $by_customer ) ) {
			$this->cache[ $cache_key ] = array_fill( 0, count( $rows ), null );
			return $this->cache[ $cache_key ];
		}

		$matches = array_fill( 0, count( $rows ), null );

		foreach ( $by_customer as $customer_id => $attempts ) {
			$orders = $this->fetch_completed_orders_for_customer( $customer_id );

			foreach ( $attempts as $row_index => $attempt_ts_micros ) {
				$attempt_seconds = (int) floor( $attempt_ts_micros / 1000000 );
				$min_order_ts    = $attempt_seconds;
				$max_order_ts    = $attempt_seconds + $window_seconds;

				foreach ( $orders as $order ) {
					$created_gmt = $this->get_order_timestamp( $order );
					if ( $created_gmt >= $min_order_ts && $created_gmt <= $max_order_ts ) {
						$matches[ $row_index ] = [
							'order_id'    => $order->get_id(),
							'total'       => (float) $order->get_total(),
							'customer_id' => $customer_id,
						];
						break;
					}
				}
			}
		}

		$this->cache[ $cache_key ] = $matches;
		return $matches;
	}

	/**
	 * Fetch all completed/processing orders for a customer, sorted oldest-first.
	 *
	 * Queries each status separately (real Woo accepts an array, but this also
	 * keeps the helper portable across Woo-mocked test environments that only
	 * read the first element of the status array).
	 *
	 * @param int $customer_id Customer ID.
	 * @return array Orders sorted ascending by creation timestamp.
	 */
	private function fetch_completed_orders_for_customer( int $customer_id ): array {
		$orders = [];
		foreach ( self::COMPLETED_STATUSES as $status ) {
			$batch = wc_get_orders(
				[
					'customer_id' => $customer_id,
					// Real WC accepts both bare ('completed') and prefixed ('wc-completed') statuses;
					// the prefixed form is also what the test-suite Woo mock matches against.
					'status'      => [ 'wc-' . $status ],
					'limit'       => -1,
					'orderby'     => 'date',
					'order'       => 'ASC',
				]
			);
			if ( is_array( $batch ) ) {
				$orders = array_merge( $orders, $batch );
			}
		}

		// Sort ascending by creation timestamp so "first matching order" semantics are deterministic.
		usort(
			$orders,
			function ( $a, $b ) {
				return $this->get_order_timestamp( $a ) <=> $this->get_order_timestamp( $b );
			}
		);

		return $orders;
	}

	/**
	 * Read the order's creation timestamp (GMT epoch seconds).
	 *
	 * Prefers `get_date_created()` (production-correct, maps to
	 * `wp_wc_orders.date_created_gmt`). Falls back to `get_date_paid()` when the
	 * order object does not expose creation (e.g. the test-suite Woo mocks).
	 *
	 * @param \WC_Order $order Woo order.
	 * @return int Epoch seconds, or 0 if neither date is available.
	 */
	private function get_order_timestamp( \WC_Order $order ): int {
		if ( method_exists( $order, 'get_date_created' ) ) {
			$dt = $order->get_date_created();
			if ( $dt ) {
				return (int) $dt->getTimestamp();
			}
		}
		if ( method_exists( $order, 'get_date_paid' ) ) {
			$dt = $order->get_date_paid();
			if ( $dt ) {
				return (int) $dt->getTimestamp();
			}
		}
		return 0;
	}
}
