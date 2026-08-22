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

// `status` renders the log's Status column; `intent` badges the single status in
// the detail modal, where one marker is what a badge is for. Both carry the same
// constraint: a cancelled job is a deliberate stop, not a failure, so it must not
// share `failed`'s treatment. The column offers them as separate filters and they
// have to read apart. The design system files terminal, non-actionable states
// like this under `none`.
//
// The `canceled` key is Action Scheduler's own spelling, so it stays as the
// library writes it.
/** @type {Record< string, { label: string, status: import('../../../../../packages/components/src/status-indicator/statuses').StatusName, intent: import('../../../../../packages/components/src/types').BadgeIntent } >} */
export const STATUS_MAP = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), status: 'done', intent: 'stable' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), status: 'error', intent: 'high' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), status: 'pending', intent: 'low' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), status: 'progress', intent: 'informational' },
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), status: 'cancelled', intent: 'none' },
};

export function formatTimestamp( gmt ) {
	if ( ! gmt ) {
		return '';
	}
	const dateFormat = getSettings().formats.datetime || 'F j, Y, g:i a';
	return dateI18n( dateFormat, `${ gmt }+00:00` );
}
