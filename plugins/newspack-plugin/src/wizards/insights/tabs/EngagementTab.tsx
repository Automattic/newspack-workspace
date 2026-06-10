/**
 * EngagementTab (Tab 2, NPPD-1649).
 *
 * GA4-backed Engagement tab. Fetches the Engagement orchestrator endpoint and
 * renders three sections — Overall engagement quality, Reader segments, Content
 * engagement. The loading / error / refetch chrome is handled by the shared
 * TabStateView, with a connect banner when GA4 isn't connected.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useEngagementData from '../hooks/useEngagementData';
import ConnectBanner from './components/ConnectBanner';
import TabStateView from './components/TabStateView';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import QualitySection from './engagement/sections/QualitySection';
import ContentEngagementSection from './engagement/sections/ContentEngagementSection';
import ReaderSegmentsSection from './engagement/sections/ReaderSegmentsSection';
import './engagement/engagement.scss';

export interface EngagementTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const EngagementTab = ( { range, previousRange }: EngagementTabProps ) => {
	const { status, data, error } = useEngagementData( range, previousRange );

	const current = data?.current ?? null;
	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate here.
	const previous = previousRange ? data?.previous ?? null : null;

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load engagement data.', 'newspack-plugin' ) }
			className="newspack-insights__engagement-tab"
			loadingMessages={ TAB_LOADING_MESSAGES.engagement }
		>
			{ data &&
				( data.tab_error || ! current ? (
					<ConnectBanner text={ data.banner_text } />
				) : (
					<>
						<QualitySection current={ current } previous={ previous } />
						<ReaderSegmentsSection current={ current } previous={ previous } />
						<ContentEngagementSection current={ current } previous={ previous } />
					</>
				) ) }
		</TabStateView>
	);
};

export default EngagementTab;
