/**
 * AdvertisingTab (Tab 8).
 *
 * GAM-backed Advertising tab. Fetches the Advertising orchestrator endpoint
 * and renders the report sections. Unlike the GA4 tabs, visibility
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
import LastUpdated from '../components/LastUpdated';
import TabStateView from './components/TabStateView';
import TabSections from './components/TabSections';
import TabLoading from './components/TabLoading';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import DataLagIndicator from './components/DataLagIndicator';
import FinishConnectingDiagnostic from './components/FinishConnectingDiagnostic';
import ReachRevenueSection from './advertising/sections/ReachRevenueSection';
import RevenueTrendSection from './advertising/sections/RevenueTrendSection';
import ChannelDeviceSection from './advertising/sections/ChannelDeviceSection';
import SitePerformanceSection from './advertising/sections/SitePerformanceSection';
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

	// Broadstreet has no revenue in its API (NPPD-2045), so its variant is
	// impressions-only: a provider-aware Reach section and a Top performers section
	// with zones + advertisers + campaigns. The GAM-only sections (revenue/inventory
	// scorecards, revenue trend, channel/device, per-site) and the GAM data-lag note
	// are hidden.
	const isBroadstreet = current?.active_provider === 'broadstreet';

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
					{ /* GAM-specific AdX data-lag note; Broadstreet has no estimate lag. */ }
					{ ! isBroadstreet && <DataLagIndicator dataAsOf={ current.data_as_of } hasEstimatedData={ current.has_estimated_data } /> }
					<TabSections>
						<ReachRevenueSection
							current={ current.metrics }
							previous={ previous }
							hasWindowActivity={ current.has_window_activity }
							activeProvider={ current.active_provider }
							lastUpdated={ <LastUpdated tab="advertising" range={ range } previousRange={ previousRange } /> }
						/>
						{ /* Revenue/inventory sections are GAM-only — Broadstreet's API has no
						     revenue, channels, devices, or per-site custom dimension. Kept as
						     direct TabSections children (not wrapped in a Fragment) so a
						     full-width divider is drawn between each rendered section. */ }
						{ ! isBroadstreet && <RevenueTrendSection current={ current.metrics } previous={ previous } /> }
						{ /* Channel pie + device table: after the trend, before the per-site and
						     top-performer tables. */ }
						{ ! isBroadstreet && <ChannelDeviceSection current={ current.metrics } previous={ previous } /> }
						{ /* Per-site breakdown: network members only; absent otherwise. */ }
						{ ! isBroadstreet && current.is_network_member && (
							<SitePerformanceSection current={ current.metrics } previous={ previous } />
						) }
						<TopPerformersSection current={ current.metrics } previous={ previous } activeProvider={ current.active_provider } />
					</TabSections>
				</>
			) }
		</TabStateView>
	);
};

export default AdvertisingTab;
