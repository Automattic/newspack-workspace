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

export const STATUS_MAP = {
	complete: { label: __( 'Complete', 'newspack-plugin' ), level: 'success' },
	failed: { label: __( 'Failed', 'newspack-plugin' ), level: 'error' },
	pending: { label: __( 'Pending', 'newspack-plugin' ), level: 'info' },
	'in-progress': { label: __( 'In progress', 'newspack-plugin' ), level: 'info' },
	canceled: { label: __( 'Canceled', 'newspack-plugin' ), level: 'warning' },
};

export function formatTimestamp( gmt ) {
	if ( ! gmt ) {
		return '';
	}
	const dateFormat = getSettings().formats.datetime || 'F j, Y, g:i a';
	return dateI18n( dateFormat, `${ gmt }+00:00` );
}
