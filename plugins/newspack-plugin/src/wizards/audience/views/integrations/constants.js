/**
 * Shared module-level constants and helpers for the integration activity logs
 * view and its detail modal.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';
import { error, notAllowed, pending, published, update } from '@wordpress/icons';

export const API_BASE = '/newspack/v1/wizard/newspack-audience-integrations/settings';

// `icon` renders the log's Status column; `intent` badges the single status in
// the detail modal, where one marker is what a badge is for. Both carry the same
// constraint: a cancelled job is a deliberate stop, not a failure, so it must not
// share `failed`'s treatment. The column offers them as separate filters and they
// have to read apart. The design system files terminal, non-actionable states
// like this under `none`.
/** @type {Record< string, { label: string, icon: unknown, intent: import('../../../../../packages/components/src/types').BadgeIntent } >} */
export const STATUS_MAP = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), icon: published, intent: 'stable' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), icon: error, intent: 'high' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), icon: pending, intent: 'low' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), icon: update, intent: 'informational' },
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), icon: notAllowed, intent: 'none' },
};

export function formatTimestamp( gmt ) {
	if ( ! gmt ) {
		return '';
	}
	const dateFormat = getSettings().formats.datetime || 'F j, Y, g:i a';
	return dateI18n( dateFormat, `${ gmt }+00:00` );
}
