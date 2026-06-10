/**
 * ConversionTab (NPPD-1609).
 *
 * Tab 3 orchestrator. Mirrors the GatesTab / PromptsTab loading / error /
 * success lifecycle and composes the eight Conversion Journey sections.
 *
 * The date range picker affects most sections, but Section 5 (Cohort
 * retention) and the Section 8 snapshot scorecards are current-state and
 * ignore the picker. Comparison is forwarded by the wizard chrome via the
 * standard `previousRange` prop; only Section 7 (cross-tab influenced
 * attribution) renders the resulting deltas.
 *
 * Phase 1 ships the full UI with placeholder data; the top-of-tab banner
 * and the cohort-retention-freshness callout (added in a later commit)
 * are the only signals that real data is pending. `tab_pending` stays in
 * the response envelope for Phase 2.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useConversionData from '../hooks/useConversionData';
import TabStateView from './components/TabStateView';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import ReaderLifecycleSection from './conversion/ReaderLifecycleSection';
import PerJourneyConversionFunnelsSection from './conversion/PerJourneyConversionFunnelsSection';
import WhereConversionsComeFromSection from './conversion/WhereConversionsComeFromSection';
import HowLongConversionsTakeSection from './conversion/HowLongConversionsTakeSection';
import CohortRetentionSection from './conversion/CohortRetentionSection';
import ConversionRateTrendsSection from './conversion/ConversionRateTrendsSection';
import CrossTabInfluencedAttributionSection from './conversion/CrossTabInfluencedAttributionSection';
import OpportunityBucketsSection from './conversion/OpportunityBucketsSection';
import './conversion/conversion.scss';

export interface ConversionTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const ConversionTab = ( { range, previousRange }: ConversionTabProps ) => {
	const { status, data, error } = useConversionData( range, previousRange );

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load conversion data.', 'newspack-plugin' ) }
			className="newspack-insights__conversion-tab"
			loadingMessages={ TAB_LOADING_MESSAGES.conversion }
		>
			{ data && (
				<>
					<ReaderLifecycleSection current={ data.current } />
					<PerJourneyConversionFunnelsSection current={ data.current } />
					<WhereConversionsComeFromSection current={ data.current } />
					<HowLongConversionsTakeSection current={ data.current } />
					<CohortRetentionSection current={ data.current } />
					<ConversionRateTrendsSection current={ data.current } />
					<CrossTabInfluencedAttributionSection current={ data.current } previous={ data.previous } />
					<OpportunityBucketsSection current={ data.current } />
				</>
			) }
		</TabStateView>
	);
};

export default ConversionTab;
