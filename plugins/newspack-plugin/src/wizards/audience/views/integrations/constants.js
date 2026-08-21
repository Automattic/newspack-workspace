/**
 * Shared module-level constants and helpers for the integration activity logs
 * view and its detail modal.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';

export const API_BASE = '/newspack/v1/wizard/newspack-audience-integrations/settings';

/** @type {Record< string, { label: string, intent: import('../../../../../packages/components/src/types').BadgeIntent } >} */
export const STATUS_MAP = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), intent: 'stable' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), intent: 'high' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), intent: 'low' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), intent: 'informational' },
	// A cancelled job is a deliberate stop, not a failure, so it must not share `failed`'s
	// intent: the column offers them as separate filters and they have to read apart. The
	// design system files terminal, non-actionable states like this under `none`.
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), intent: 'none' },
};

export function formatTimestamp( gmt ) {
	if ( ! gmt ) {
		return '';
	}
	const dateFormat = getSettings().formats.datetime || 'F j, Y, g:i a';
	return dateI18n( dateFormat, `${ gmt }+00:00` );
}
