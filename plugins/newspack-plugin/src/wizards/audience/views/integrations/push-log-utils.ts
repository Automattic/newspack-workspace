/**
 * Pure helpers for the sync activity tab, kept out of the components so the
 * wording and the request shape can be tested without rendering a table.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { isEmail } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { PUSH_LOG_STATUS_MAP, PUSH_LOG_ERROR_CLASS_LABELS } from './constants';
import type { LogsView, PushLogItem, IntegrationStatusDisplay } from './constants';

// The Status filter's "Needs attention" option. It is not a stored status:
// the server works it out from later pushes, so it travels as its own arg.
export const NEEDS_ATTENTION_VALUE = 'needs_attention';

// How long the log is kept, by outcome, when the server did not say.
const DEFAULT_RETENTION_DAYS = { success: 30, failed: 90 };

type RetentionDays = { success?: number; failed?: number } | null;

const getFilterValue = ( view: LogsView, field: string ): unknown => view.filters?.find( filter => filter.field === field )?.value;

/**
 * A retention window worded with its own unit, so a one-day window reads as
 * "1 day" rather than "1 days".
 *
 * @param days How many days.
 * @return "%d day" or "%d days", with the number filled in.
 */
const formatRetentionDuration = ( days: number ): string =>
	/* translators: %d: number of days in the window. */
	sprintf( _n( '%d day', '%d days', days, 'newspack-plugin' ), days );

/**
 * The REST args for a DataViews view. Undefined args are left out of the URL.
 *
 * @param view The DataViews view.
 * @return Args for the push-log route.
 */
export function buildPushLogQuery( view: LogsView ) {
	const status = getFilterValue( view, 'status' );
	const needsAttention = status === NEEDS_ATTENTION_VALUE;
	return {
		page: view.page,
		per_page: view.perPage,
		order: ( view.sort?.direction || 'desc' ).toUpperCase(),
		search: view.search || undefined,
		status: ( ! needsAttention && status ) || undefined,
		operation: getFilterValue( view, 'operation' ) || undefined,
		needs_attention: needsAttention || undefined,
	};
}

/**
 * Which retry a row is on. The log counts attempts; a publisher counts
 * retries, so the first attempt is left out of both numbers. A row still
 * waiting says its last attempt failed and names the retry ahead of it, the
 * one its scheduled action is titled with, rather than the last one it made.
 *
 * @param item A push log item.
 * @return "Failed. Waiting for retry 3 of 5" or "Failed. Waiting for retry 3" while a
 *         retry is due, "Running retry 3 of 5" while it runs, "Retry 2
 *         of 5" or "Retry 2" for the last one made, and '' on a first
 *         attempt or a retry that is gone.
 */
export function getAttemptLabel( item: PushLogItem ): string {
	const attempts = item.attempts || 1;
	const maxRetries = ( item.max_attempts || 1 ) - 1;

	if ( item.status === 'retrying' ) {
		if ( item.retry?.is_running ) {
			if ( maxRetries < attempts ) {
				/* translators: %d: which retry is running. */
				return sprintf( __( 'Running retry %d', 'newspack-plugin' ), attempts );
			}
			/* translators: 1: which retry is running. 2: how many retries a sync gets. */
			return sprintf( __( 'Running retry %1$d of %2$d', 'newspack-plugin' ), attempts, maxRetries );
		}
		// A retry that is gone is not coming: getRetryNote() says so instead.
		if ( ! item.retry?.is_pending ) {
			return '';
		}
		if ( maxRetries < attempts ) {
			/* translators: %d: which retry the row is waiting for. */
			return sprintf( __( 'Failed. Waiting for retry %d', 'newspack-plugin' ), attempts );
		}
		/* translators: 1: which retry the row is waiting for. 2: how many retries a sync gets. */
		return sprintf( __( 'Failed. Waiting for retry %1$d of %2$d', 'newspack-plugin' ), attempts, maxRetries );
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
 * @param entry A push log entry.
 * @return The label.
 */
export function getErrorKindLabel( entry: PushLogItem ): string | undefined {
	if ( entry.error_class === 'benign' && entry.operation !== 'upsert' ) {
		return __( 'Already removed', 'newspack-plugin' );
	}
	return PUSH_LOG_ERROR_CLASS_LABELS[ entry.error_class as string ] || entry.error_class;
}

/**
 * A retrying row whose scheduled retry is gone will not move on its own.
 *
 * @param item A push log item.
 * @return The note, or ''.
 */
export function getRetryNote( item: PushLogItem ): string {
	const isGone = item.status === 'retrying' && item.retry && ! item.retry.is_pending && ! item.retry.is_running;
	return isGone ? __( 'Retry no longer scheduled', 'newspack-plugin' ) : '';
}

/**
 * How a row's status is drawn. A retrying row whose retry is gone is stuck
 * and will not move on its own, so it reads as something to look at rather
 * than as work still in progress.
 *
 * @param item A push log item.
 * @return The label, the mark and the badge.
 */
export function getStatusDisplay( item: PushLogItem ): Pick< IntegrationStatusDisplay, 'label' | 'status' | 'intent' > {
	if ( getRetryNote( item ) ) {
		return { label: PUSH_LOG_STATUS_MAP.retrying.label, status: 'attention', intent: 'medium' };
	}
	return PUSH_LOG_STATUS_MAP[ item.status ] || { label: item.status, status: 'attention', intent: 'none' };
}

/**
 * What an empty table says, which depends on what was asked for.
 *
 * @param view            The DataViews view.
 * @param [retentionDays] How long the log is kept, by outcome.
 * @return The message.
 */
export function getEmptyMessage( view: LogsView, retentionDays?: RetentionDays ): string {
	// Worded as what the log holds, not as an all-clear: a push that sent
	// only some fields still closes a failed one.
	if ( getFilterValue( view, 'status' ) === NEEDS_ATTENTION_VALUE && ! getFilterValue( view, 'operation' ) ) {
		return __( 'Nothing in the log needs attention.', 'newspack-plugin' );
	}
	if ( getFilterValue( view, 'status' ) || getFilterValue( view, 'operation' ) ) {
		return __( 'No pushes match these filters.', 'newspack-plugin' );
	}
	// The server matches anything short of a full address on its start only,
	// and trims the search before telling the two apart.
	const search = ( view.search || '' ).trim();
	if ( search && ! isEmail( search ) ) {
		/* translators: %s: the text searched for. */
		return sprintf( __( 'No address in the log starts with “%s”.', 'newspack-plugin' ), search );
	}
	if ( search ) {
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
