/**
 * DonorsTab (NPPD-1617).
 *
 * Orchestrates the Tab 7 view: fetches data for the active range +
 * comparison range, then composes the four sections (scorecard,
 * windowed, retention, performance).
 *
 * Loading / error states are local to this tab; the wizard chrome
 * (date picker, comparison toggle, tab navigation) stays interactive
 * while the tab body is in any state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useDonorsData from '../hooks/useDonorsData';
import ScorecardSection from './donors/ScorecardSection';
import WindowedSection from './donors/WindowedSection';
import RetentionSection from './donors/RetentionSection';
import PerformanceSection from './donors/PerformanceSection';
import './donors/donors.scss';

export interface DonorsTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const DonorsTab = ( { range, previousRange }: DonorsTabProps ) => {
	const { status, data, error } = useDonorsData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading donor data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load donor data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	return (
		<div className="newspack-insights__donors-tab">
			<ScorecardSection snapshot={ data.snapshot } />
			<WindowedSection range={ range } current={ data.current } previous={ data.previous } />
			<RetentionSection current={ data.current } previous={ data.previous } />
			<PerformanceSection rows={ data.current.donations_by_tier } />
		</div>
	);
};

export default DonorsTab;
