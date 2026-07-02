/**
 * Shared display labels for Tab 3 (Conversion Journey).
 *
 * Maps the machine source keys (`gate` / `prompt` / `direct`) used by the
 * Section 3 PieCharts and the Section 4 multi-series distributions to their
 * translated display labels, plus the per-axis point labels the Section 4–6
 * LineCharts feed to `formatLabel`, so the sections stay declarative and the
 * copy lives in one place.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatShortDate } from '../components/format';

export type ConversionSource = 'gate' | 'prompt' | 'direct';

export const sourceLabel = ( source: ConversionSource ): string => {
	switch ( source ) {
		case 'gate':
			return __( 'Gate', 'newspack-plugin' );
		case 'prompt':
			return __( 'Prompt', 'newspack-plugin' );
		case 'direct':
		default:
			return __( 'Direct', 'newspack-plugin' );
	}
};

/** X-axis point label for day-indexed cumulative curves (Section 4): "42" -> "Day 42". */
export const dayLabel = ( day: string ): string =>
	// translators: %s is a day number since the reader's first visit.
	sprintf( __( 'Day %s', 'newspack-plugin' ), day );

/** X-axis point label for month-indexed cohort curves (Section 5): "6" -> "Month 6". */
export const monthLabel = ( period: string ): string =>
	// translators: %s is a month number since the cohort started.
	sprintf( __( 'Month %s', 'newspack-plugin' ), period );

/** X-axis point label for weekly trend curves (Section 6): "2025-06-01" -> "Week of Jun 1". */
export const weekLabel = ( week: string ): string =>
	// translators: %s is the start date of a week, e.g. "Jun 1".
	sprintf( __( 'Week of %s', 'newspack-plugin' ), formatShortDate( week.replace( /-/g, '' ) ) );
