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
import TabStateView from './components/TabStateView';
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

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load donor data.', 'newspack-plugin' ) }
			className="newspack-insights__donors-tab"
		>
			{ data && (
				<>
					<ScorecardSection snapshot={ data.snapshot } />
					<WindowedSection range={ range } current={ data.current } previous={ data.previous } />
					<RetentionSection current={ data.current } previous={ data.previous } />
					<PerformanceSection rows={ data.current.donations_by_tier } />
				</>
			) }
		</TabStateView>
	);
};

export default DonorsTab;
