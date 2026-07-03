<?php
/**
 * Newspack Insights — Reader Revenue Development 3-year CLV model (NEWS-2603).
 *
 * Pure, storage-free implementation of the RDP "Reader Revenue CLV" worksheet's
 * per-supporter lifetime-value formula. Shared by Subscribers_Metric,
 * Donors_Metric, and the Audience newsletter-CLV card so the model lives in one
 * place and is unit-testable without a database.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Modeled 3-year customer lifetime value.
 */
final class Clv_Model {

	/**
	 * Flat maintenance cost (payment-processing fees, servicing) as a share of
	 * revenue, per the worksheet's 5% assumption.
	 *
	 * @var float
	 */
	const MAINTENANCE_RATE = 0.05;

	/**
	 * Per-supporter modeled 3-year CLV.
	 *
	 *   CLV = (1 - MAINTENANCE_RATE) * ARPU * (r + r^2 + r^3) - discount
	 *
	 * Each of three years earns ARPU scaled by cumulative survival to that year
	 * (r, r^2, r^3), net of the flat maintenance rate, less an optional Year-1
	 * intro discount. This applies the maintenance subtraction uniformly to all
	 * three years — the worksheet's Year 1 cell has a sign slip that adds the
	 * cost instead; the intended subtraction is used here. Result clamped to >= 0.
	 *
	 * @param float $arpu     Average annual revenue per supporter (>= 0).
	 * @param float $r        12-month retention as a fraction; clamped to [0, 1].
	 * @param float $discount Per-supporter Year-1 intro discount (default 0).
	 * @return float CLV rounded to cents.
	 */
	public static function three_year( float $arpu, float $r, float $discount = 0.0 ): float {
		$r   = max( 0.0, min( 1.0, $r ) );
		$net = ( 1.0 - self::MAINTENANCE_RATE ) * $arpu * ( $r + ( $r ** 2 ) + ( $r ** 3 ) );
		return max( 0.0, round( $net - $discount, 2 ) );
	}
}
