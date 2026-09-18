/**
 * Pure helpers for the sync activity tab, kept out of the components so the
 * wording and the request shape can be tested without rendering a table.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PUSH_LOG_STATUS_MAP, PUSH_LOG_ERROR_CLASS_LABELS } from './constants';

// The "Needs attention" filter has one option; this is its value.
export const NEEDS_ATTENTION_VALUE = 'yes';

// How long the log is kept, by outcome, when the server did not say.
const DEFAULT_RETENTION_DAYS = { success: 30, failed: 90 };

const getFilterValue = ( view, field ) => view.filters?.find( filter => filter.field === field )?.value;

/**
 * A retention window worded with its own unit, so a one-day window reads as
 * "1 day" rather than "1 days".
 *
 * @param {number} days How many days.
 * @return {string} "%d day" or "%d days", with the number filled in.
 */
const formatRetentionDuration = days =>
	/* translators: %d: number of days in the window. */
	sprintf( _n( '%d day', '%d days', days, 'newspack-plugin' ), days );

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
 * retries, so the first attempt is left out of both numbers. A row still
 * waiting names the retry ahead of it, the one its scheduled action is
 * titled with, rather than the last one it made.
 *
 * @param {Object} item A push log item.
 * @return {string} "Waiting for retry 3 of 5" or "Waiting for retry 3" while a
 *                  retry is due, "Retry 2 of 5" or "Retry 2" for the last one
 *                  made, and '' on a first attempt or a retry that is gone.
 */
export function getAttemptLabel( item ) {
	const attempts = item.attempts || 1;
	const maxRetries = ( item.max_attempts || 1 ) - 1;

	if ( item.status === 'retrying' ) {
		// A retry that is gone is not coming: getRetryNote() says so instead.
		if ( ! item.retry?.is_pending ) {
			return '';
		}
		if ( maxRetries < attempts ) {
			/* translators: %d: which retry the row is waiting for. */
			return sprintf( __( 'Waiting for retry %d', 'newspack-plugin' ), attempts );
		}
		/* translators: 1: which retry the row is waiting for. 2: how many retries a sync gets. */
		return sprintf( __( 'Waiting for retry %1$d of %2$d', 'newspack-plugin' ), attempts, maxRetries );
	}

	const retries = attempts - 1;
	if ( retries < 1 ) {
		return '';
	}
	if ( maxRetries < retries ) {
		/* translators: %d: which retry this is. */
		return sprintf( __( 'Retry %d', 'newspack-plugin' ), retries );
	}
	/* translators: 1: which retry this is. 2: how many retries a sync gets. */
	return sprintf( __( 'Retry %1$d of %2$d', 'newspack-plugin' ), retries, maxRetries );
}

/**
 * What the sync made of a row's error, in words that fit the operation. A
 * benign deletion means the contact was already gone at the provider, which
 * "Already up to date" would describe as the opposite.
 *
 * @param {Object} entry A push log entry.
 * @return {string} The label.
 */
export function getErrorKindLabel( entry ) {
	if ( entry.error_class === 'benign' && entry.operation !== 'upsert' ) {
		return __( 'Already removed', 'newspack-plugin' );
	}
	return PUSH_LOG_ERROR_CLASS_LABELS[ entry.error_class ] || entry.error_class;
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
 * How a row's status is drawn. A retrying row whose retry is gone is stuck
 * and will not move on its own, so it reads as something to look at rather
 * than as work still in progress.
 *
 * @param {Object} item A push log item.
 * @return {{ label: string, status: string, intent: string }} The label, the mark and the badge.
 */
export function getStatusDisplay( item ) {
	if ( getRetryNote( item ) ) {
		return { label: PUSH_LOG_STATUS_MAP.retrying.label, status: 'attention', intent: 'medium' };
	}
	return PUSH_LOG_STATUS_MAP[ item.status ] || { label: item.status, status: 'attention', intent: 'none' };
}

/**
 * What an empty table says, which depends on what was asked for.
 *
 * @param {Object} view            The DataViews view.
 * @param {Object} [retentionDays] How long the log is kept, by outcome.
 * @return {string} The message.
 */
export function getEmptyMessage( view, retentionDays ) {
	if ( getFilterValue( view, 'needs_attention' ) === NEEDS_ATTENTION_VALUE ) {
		return __( 'No sync problems.', 'newspack-plugin' );
	}
	if ( view.search ) {
		return sprintf(
			/* translators: 1: how long a successful push is kept, e.g. "30 days". 2: how long a failed push is kept, e.g. "90 days". */
			__(
				'No pushes recorded for this reader. The log holds pushes made while the integration was enabled with outbound sync on: %1$s for the ones that worked, %2$s for the ones that failed.',
				'newspack-plugin'
			),
			formatRetentionDuration( retentionDays?.success ?? DEFAULT_RETENTION_DAYS.success ),
			formatRetentionDuration( retentionDays?.failed ?? DEFAULT_RETENTION_DAYS.failed )
		);
	}
	return __( 'No pushes recorded yet.', 'newspack-plugin' );
}
