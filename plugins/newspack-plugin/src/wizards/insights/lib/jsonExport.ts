/**
 * JSON export helpers (NEWS-2587).
 *
 * The Insights "Export JSON…" export ships the currently viewed tab's
 * `data` payload (the `data` key of its cache envelope) as a downloaded
 * `.json` file. The suggested filename encodes the data's computed-at
 * date, the tab slug, and the active date-range preset so exports are
 * self-describing, e.g. `2026-07-01-engagement-last-7-days.json`.
 */

/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';

/**
 * Internal dependencies
 */
import type { DateRangePreset } from '../state/useDateRange';

/**
 * Filename slug for each date-range preset. Numeric "Last N days" presets
 * carry a `-days` suffix; calendar presets use their key verbatim; a custom
 * range collapses to `custom-dates` (the exact bounds live in the payload).
 */
const PRESET_SLUG: Record< DateRangePreset, string > = {
	'last-7': 'last-7-days',
	'last-30': 'last-30-days',
	'last-90': 'last-90-days',
	'this-month': 'this-month',
	'last-month': 'last-month',
	custom: 'custom-dates',
};

/**
 * Build the suggested JSON filename for a tab + preset + computed-at
 * timestamp, e.g. `2026-07-01-engagement-last-7-days.json`. The date is
 * rendered in site time via `dateI18n`, matching the "Last updated" line.
 */
export const buildJsonFilename = ( tab: string, preset: DateRangePreset, computedAt: string ): string =>
	`${ dateI18n( 'Y-m-d', computedAt ) }-${ tab }-${ PRESET_SLUG[ preset ] }.json`;
