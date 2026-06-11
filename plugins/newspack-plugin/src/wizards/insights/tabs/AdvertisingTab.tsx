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

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading advertising data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load advertising data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	const current = data?.current;
	if ( ! current ) {
		return null;
	}

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
	// (async GAM reports). Show a loading note rather than empty sections; the
	// data arrives on a later poll. (Beyond the ticket's three states — surfaced
	// because the orchestrator is asynchronous and exposes `is_loading`.)
	if ( current.is_loading ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Preparing your advertising data… this can take a minute the first time.', 'newspack-plugin' ) }
			</div>
		);
	}

	const metrics = current.metrics;
	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate here.
	const previous = previousRange ? data?.previous?.metrics ?? null : null;

	return (
		<div className="newspack-insights__advertising-tab">
			<DataLagIndicator dataAsOf={ current.data_as_of } hasEstimatedData={ current.has_estimated_data } />
			<ReachRevenueSection current={ metrics } previous={ previous } />
			<InventoryPerformanceSection current={ metrics } previous={ previous } />
			<TopPerformersSection current={ metrics } previous={ previous } />
		</div>
	);
};

export default AdvertisingTab;
