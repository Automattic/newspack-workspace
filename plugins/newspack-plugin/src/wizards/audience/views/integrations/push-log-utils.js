/**
 * Pure helpers for the sync activity tab, kept out of the components so the
 * wording and the request shape can be tested without rendering a table.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

// The "Needs attention" filter has one option; this is its value.
export const NEEDS_ATTENTION_VALUE = 'yes';

const getFilterValue = ( view, field ) => view.filters?.find( filter => filter.field === field )?.value;

/**
 * The REST args for a DataViews view. Undefined args are left out of the URL.
 *
 * @param {Object} view The DataViews view.
 * @return {Object} Args for the push-log route.
 */
export function buildPushLogQuery( view ) {
	return {
		page: view.page,
		per_page: view.perPage,
		order: ( view.sort?.direction || 'desc' ).toUpperCase(),
		search: view.search || undefined,
		status: getFilterValue( view, 'status' ) || undefined,
		operation: getFilterValue( view, 'operation' ) || undefined,
		needs_attention: getFilterValue( view, 'needs_attention' ) === NEEDS_ATTENTION_VALUE ? true : undefined,
	};
}

/**
 * Which retry a row is on. The log counts attempts; a publisher counts
 * retries, so the first attempt is left out of both numbers.
 *
 * @param {Object} item A push log item.
 * @return {string} "Retry 2 of 5", or '' on a first attempt.
 */
export function getAttemptLabel( item ) {
	const retries = ( item.attempts || 1 ) - 1;
	if ( retries < 1 ) {
		return '';
	}
	const maxRetries = ( item.max_attempts || 1 ) - 1;
	if ( maxRetries < retries ) {
		/* translators: %d: which retry this is. */
		return sprintf( __( 'Retry %d', 'newspack-plugin' ), retries );
	}
	/* translators: 1: which retry this is. 2: how many retries a sync gets. */
	return sprintf( __( 'Retry %1$d of %2$d', 'newspack-plugin' ), retries, maxRetries );
}

/**
 * A retrying row whose scheduled retry is gone will not move on its own.
 *
 * @param {Object} item A push log item.
 * @return {string} The note, or ''.
 */
export function getRetryNote( item ) {
	return item.status === 'retrying' && item.retry && ! item.retry.is_pending ? __( 'Retry no longer scheduled', 'newspack-plugin' ) : '';
}

/**
 * What an empty table says, which depends on what was asked for.
 *
 * @param {Object} view The DataViews view.
 * @return {string} The message.
 */
export function getEmptyMessage( view ) {
	if ( getFilterValue( view, 'needs_attention' ) === NEEDS_ATTENTION_VALUE ) {
		return __( 'No sync problems.', 'newspack-plugin' );
	}
	if ( view.search ) {
		return __(
			'No pushes recorded for this reader. The log holds pushes made while the integration was enabled with outbound sync on: 30 days for the ones that worked, 90 for the ones that failed.',
			'newspack-plugin'
		);
	}
	return __( 'No pushes recorded yet.', 'newspack-plugin' );
}
