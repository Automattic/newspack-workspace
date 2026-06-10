/**
 * AdvertisingTab (Tab 8, NPPD-1618).
 *
 * GAM-backed Advertising tab. Fetches the Advertising orchestrator endpoint
 * (NPPD-1663) and renders four sections. Unlike the GA4 tabs, visibility
 * (GAM ad provider active) and reporting readiness (OAuth scope + network code)
 * are distinct signals, so this tab has an extra "finish connecting" state
 * between the hidden and ready states. Because GAM reports run asynchronously,
 * a ready-but-not-yet-cached window shows a brief loading note.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useAdvertisingData from '../hooks/useAdvertisingData';
import TabStateView from './components/TabStateView';
import TabLoading from './components/TabLoading';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import DataLagIndicator from './components/DataLagIndicator';
import FinishConnectingDiagnostic from './components/FinishConnectingDiagnostic';
import ReachRevenueSection from './advertising/sections/ReachRevenueSection';
import InventoryPerformanceSection from './advertising/sections/InventoryPerformanceSection';
import TopPerformersSection from './advertising/sections/TopPerformersSection';
import './advertising/advertising.scss';

export interface AdvertisingTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const AdvertisingTab = ( { range, previousRange }: AdvertisingTabProps ) => {
	const { status, data, error } = useAdvertisingData( range, previousRange );
	const current = data?.current;

	// Tab-specific render gates precede the standard data lifecycle. They require
	// the envelope, so they're guarded by `current` (skipped on initial load) and
	// by `status` so a fetch error still routes through TabStateView's error frame
	// rather than being masked by a gate.
	if ( status !== 'error' && current ) {
		// Tab visibility (GAM ad provider active). When false the tab renders nothing;
		// the wizard chrome likewise omits the nav entry (boot-config gate).
		if ( ! current.is_tab_visible ) {
			return null;
		}

		// Visible, but reporting isn't fully connected: itemized "finish connecting".
		if ( ! current.is_report_ready ) {
			return <FinishConnectingDiagnostic issues={ current.readiness_issues } />;
		}

		// Ready, but the first background refresh hasn't populated the cache yet
		// (async GAM reports). This is the genuinely long wait, so it carries the
		// progressive GAM messages — unlike the brief envelope fetch handled by
		// TabStateView below, which stays spinner-only. (Beyond the ticket's three
		// states — surfaced because the orchestrator exposes `is_loading`.)
		if ( current.is_loading ) {
			return <TabLoading messages={ TAB_LOADING_MESSAGES.advertising } />;
		}
	}

	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate here.
	const previous = previousRange ? data?.previous?.metrics ?? null : null;

	return (
		<TabStateView
			status={ status }
			hasData={ !! current }
			error={ error }
			errorLabel={ __( 'Could not load advertising data.', 'newspack-plugin' ) }
			className="newspack-insights__advertising-tab"
		>
			{ current && (
				<>
					<DataLagIndicator dataAsOf={ current.data_as_of } hasEstimatedData={ current.has_estimated_data } />
					<ReachRevenueSection current={ current.metrics } previous={ previous } />
					<InventoryPerformanceSection current={ current.metrics } previous={ previous } />
					<TopPerformersSection current={ current.metrics } previous={ previous } />
				</>
			) }
		</TabStateView>
	);
};

export default AdvertisingTab;
