/**
 * TabStateView (NPPD).
 *
 * Shared fetch-lifecycle chrome for every data-backed Insights tab (Audience,
 * Engagement, Gates, Subscribers, Donors), which previously each copy-pasted the
 * same loading / error / empty / refetch handling. Centralizing it keeps the
 * behavior identical across tabs and gives one place to evolve it.
 *
 * States:
 *   - initial load (no data yet)              → centered spinner (Waiting)
 *   - error                                   → message + optional detail
 *   - no data (idle/empty)                    → nothing
 *   - refetch (range/comparison change with   → the populated body is muted and a
 *     data already on screen)                   spinner floats over it, so the
 *                                               change is visibly registering
 *                                               instead of stale values lingering
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import { Waiting } from '../../../../../packages/components/src';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface TabStateViewProps {
	status: FetchStatus;
	/** Whether a response payload has arrived — distinguishes first load from refetch. */
	hasData: boolean;
	error?: string | null;
	/** Headline shown in the error state (tab-specific copy). */
	errorLabel: string;
	/** Tab root class, e.g. 'newspack-insights__audience-tab'. */
	className: string;
	children: React.ReactNode;
}

const TabStateView = ( { status, hasData, error, errorLabel, className, children }: TabStateViewProps ) => {
	if ( status === 'loading' && ! hasData ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				<Waiting />
				<span className="screen-reader-text">{ __( 'Loading…', 'newspack-plugin' ) }</span>
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ errorLabel }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! hasData ) {
		return null;
	}

	// Data is on screen; a concurrent fetch (range / comparison change) is a refetch.
	const isRefreshing = status === 'loading';

	return (
		<div className={ classnames( 'newspack-insights__tab-body', className, { 'is-refreshing': isRefreshing } ) } aria-busy={ isRefreshing }>
			{ isRefreshing && (
				<div className="newspack-insights__tab-refreshing" role="status" aria-live="polite">
					<Waiting />
					<span className="screen-reader-text">{ __( 'Updating…', 'newspack-plugin' ) }</span>
				</div>
			) }
			{ children }
		</div>
	);
};

export default TabStateView;
