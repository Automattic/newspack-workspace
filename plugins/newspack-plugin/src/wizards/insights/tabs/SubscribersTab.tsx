/**
 * SubscribersTab (NPPD-1616).
 *
 * Orchestrates the Tab 6 view: fetches data for the active range +
 * comparison range, then composes the four sections (scorecard,
 * revenue, tenure, performance).
 *
 * Loading / error states are local to this tab; the wizard chrome
 * (date picker, comparison toggle, tab navigation) stays interactive
 * while the tab body is in any state.
 *
 * The REST endpoint still returns `cancellation_reasons` in the
 * payload but it is no longer rendered — publisher data on this is
 * sparse and the section wasn't pulling its weight.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useSubscribersData from '../hooks/useSubscribersData';
import ScorecardSection from './subscribers/ScorecardSection';
import WindowedSection from './subscribers/WindowedSection';
import TenureSection from './subscribers/TenureSection';
import PerformanceSection from './subscribers/PerformanceSection';
import './subscribers/subscribers.scss';

export interface SubscribersTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const SubscribersTab = ( { range, previousRange }: SubscribersTabProps ) => {
	const { status, data, error } = useSubscribersData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading subscriber data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load subscriber data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	return (
		<div className="newspack-insights__subscribers-tab">
			<ScorecardSection snapshot={ data.snapshot } />
			<WindowedSection range={ range } current={ data.current } previous={ data.previous } />
			<TenureSection rows={ data.snapshot.tenure_distribution } />
			<PerformanceSection rows={ data.current.subscriptions_by_product } />
		</div>
	);
};

export default SubscribersTab;
