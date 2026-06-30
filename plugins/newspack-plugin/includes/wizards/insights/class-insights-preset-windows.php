<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Insights prefix matches directory convention.
/**
 * Newspack Insights — fixed timeframe preset windows.
 *
 * PHP mirror of the React date-range presets (src/wizards/insights/state/
 * useDateRange.ts computeRangeForPreset). Used by the daily pre-warm to
 * compute the exact windows the dropdown produces, so warmed cache entries
 * key-match the REST requests the UI issues. Excludes 'custom' (unbounded /
 * unpredictable — never a warm target).
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed preset window calculator.
 */
final class Preset_Windows {

	/**
	 * Compute every fixed preset window, anchored to $today, in $today's
	 * timezone. Each "Last N days" preset is an inclusive N-day window ending
	 * today (subtract N-1). Start is 00:00:00, end is 23:59:59, matching the
	 * REST controllers' parse_date() boundaries.
	 *
	 * @param DateTimeImmutable $today Site-timezone "now" (e.g. current_datetime()).
	 * @return array<int, array{preset:string, start:DateTimeImmutable, end:DateTimeImmutable}>
	 */
	public static function all( DateTimeImmutable $today ): array {
		$end_today = $today->setTime( 23, 59, 59 );
		$sod       = static fn( DateTimeImmutable $d ): DateTimeImmutable => $d->setTime( 0, 0, 0 );
		$eod       = static fn( DateTimeImmutable $d ): DateTimeImmutable => $d->setTime( 23, 59, 59 );

		$month_start      = $sod( $today->modify( 'first day of this month' ) );
		$last_month_start = $sod( $today->modify( 'first day of last month' ) );
		$last_month_end   = $eod( $today->modify( 'last day of last month' ) );

		return [
			[
				'preset' => 'last-7',
				'start'  => $sod( $today->modify( '-6 days' ) ),
				'end'    => $end_today,
			],
			[
				'preset' => 'last-30',
				'start'  => $sod( $today->modify( '-29 days' ) ),
				'end'    => $end_today,
			],
			[
				'preset' => 'last-90',
				'start'  => $sod( $today->modify( '-89 days' ) ),
				'end'    => $end_today,
			],
			[
				'preset' => 'this-month',
				'start'  => $month_start,
				'end'    => $end_today,
			],
			[
				'preset' => 'last-month',
				'start'  => $last_month_start,
				'end'    => $last_month_end,
			],
		];
	}
}
