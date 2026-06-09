/**
 * GatesTab (NPPD-1604).
 *
 * Tab 4 orchestrator. Mirrors the SubscribersTab / DonorsTab
 * loading / error / success lifecycle and composes the five Gates
 * sections plus the Phase 1 top-of-tab banner.
 *
 * Date range picker affects every metric — there are no current-state
 * metrics on this tab, only window-scoped ones. Comparison toggle is
 * forwarded by the wizard chrome via the standard `previousRange`
 * prop; when set, the response carries a `previous` window that the
 * sections thread into their per-card MetricCards.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useGatesData from '../hooks/useGatesData';
import PreviewBanner from './gates/PreviewBanner';
import DirectVsInfluencedCallout from './gates/DirectVsInfluencedCallout';
import GateExposureSection from './gates/GateExposureSection';
import FreeReaderConversionSection from './gates/FreeReaderConversionSection';
import PaidReaderConversionSection from './gates/PaidReaderConversionSection';
import HowReadersConvertSection from './gates/HowReadersConvertSection';
import PerformanceByGateSection from './gates/PerformanceByGateSection';
import './gates/gates.scss';

export interface GatesTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const GatesTab = ( { range, previousRange }: GatesTabProps ) => {
	const { status, data, error } = useGatesData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading gate data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load gate data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	return (
		<div className="newspack-insights__gates-tab">
			{ data.tab_pending && <PreviewBanner /> }
			<DirectVsInfluencedCallout />
			<GateExposureSection current={ data.current } previous={ data.previous } />
			<FreeReaderConversionSection current={ data.current } previous={ data.previous } />
			<PaidReaderConversionSection current={ data.current } previous={ data.previous } />
			<HowReadersConvertSection current={ data.current } />
			<PerformanceByGateSection data={ data.current.performance_by_gate } />
		</div>
	);
};

export default GatesTab;
