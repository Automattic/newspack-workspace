/**
 * AudienceTab (Tab 1, NPPD-1649).
 *
 * GA4-backed Audience tab. Fetches the Audience orchestrator endpoint and
 * renders six sections. When GA4 isn't connected the orchestrator returns a
 * tab-level error and the whole tab becomes a single connect banner. The
 * loading / error / refetch chrome is handled by the shared TabStateView.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useAudienceData from '../hooks/useAudienceData';
import ConnectBanner from './components/ConnectBanner';
import TabStateView from './components/TabStateView';
import ReachSection from './audience/sections/ReachSection';
import CompositionSection from './audience/sections/CompositionSection';
import TimeTrendsSection from './audience/sections/TimeTrendsSection';
import TrafficSourcesSection from './audience/sections/TrafficSourcesSection';
import GeographicSection from './audience/sections/GeographicSection';
import ContentPerformanceSection from './audience/sections/ContentPerformanceSection';
import './audience/audience.scss';

export interface AudienceTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const AudienceTab = ( { range, previousRange }: AudienceTabProps ) => {
	const { status, data, error } = useAudienceData( range, previousRange );

	const current = data?.current ?? null;
	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate on the
	// toggle here rather than on the response — matches Gates/Subscribers/Donors.
	const previous = previousRange ? data?.previous ?? null : null;

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load audience data.', 'newspack-plugin' ) }
			className="newspack-insights__audience-tab"
		>
			{ data &&
				( data.tab_error || ! current ? (
					<ConnectBanner text={ data.banner_text } />
				) : (
					<>
						<ReachSection current={ current } previous={ previous } />
						<CompositionSection current={ current } previous={ previous } />
						<TrafficSourcesSection current={ current } previous={ previous } />
						<GeographicSection current={ current } previous={ previous } />
						<ContentPerformanceSection current={ current } previous={ previous } />
						<TimeTrendsSection current={ current } previous={ previous } />
					</>
				) ) }
		</TabStateView>
	);
};

export default AudienceTab;
