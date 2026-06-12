/**
 * GatesTab (NPPD-1604).
 *
 * Tab 4 orchestrator. Mirrors the SubscribersTab / DonorsTab
 * loading / error / success lifecycle and composes the five Gates
 * sections, plus a tab-level error banner when every section fails.
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
import LastUpdated from '../components/LastUpdated';
import TabStateView from './components/TabStateView';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import TabErrorBanner from './components/TabErrorBanner';
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

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load gate data.', 'newspack-plugin' ) }
			className="newspack-insights__gates-tab"
			loadingMessages={ TAB_LOADING_MESSAGES.gates }
		>
			{ data && (
				<>
					{ data.tab_error && <TabErrorBanner /> }
					<DirectVsInfluencedCallout />
					<GateExposureSection
						current={ data.current }
						previous={ data.previous }
						lastUpdated={ <LastUpdated tab="gates" range={ range } previousRange={ previousRange } /> }
					/>
					<FreeReaderConversionSection current={ data.current } previous={ data.previous } />
					<PaidReaderConversionSection current={ data.current } previous={ data.previous } />
					<HowReadersConvertSection current={ data.current } />
					<PerformanceByGateSection data={ data.current.performance_by_gate } />
				</>
			) }
		</TabStateView>
	);
};

export default GatesTab;
