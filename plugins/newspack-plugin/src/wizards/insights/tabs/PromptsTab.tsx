/**
 * PromptsTab (NPPD-1607).
 *
 * Tab 5 orchestrator. Mirrors the GatesTab loading / error / success
 * lifecycle and composes the seven Prompts sections, plus a tab-level
 * error banner when every section fails.
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
import usePromptsData from '../hooks/usePromptsData';
import LastUpdated from '../components/LastUpdated';
import TabStateView from './components/TabStateView';
import { TAB_LOADING_MESSAGES } from './components/loading-messages';
import InfoCallout from './components/InfoCallout';
import PromptsErrorBanner from './prompts/PromptsErrorBanner';
import PromptExposureSection from './prompts/PromptExposureSection';
import PromptEngagementSection from './prompts/PromptEngagementSection';
import FreeReaderConversionSection from './prompts/FreeReaderConversionSection';
import PaidReaderConversionSection from './prompts/PaidReaderConversionSection';
import RevenueFromPromptsSection from './prompts/RevenueFromPromptsSection';
import HowReadersConvertSection from './prompts/HowReadersConvertSection';
import PerformanceBreakdownSection from './prompts/PerformanceBreakdownSection';
import './prompts/prompts.scss';

export interface PromptsTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const PromptsTab = ( { range, previousRange }: PromptsTabProps ) => {
	const { status, data, error } = usePromptsData( range, previousRange );

	return (
		<TabStateView
			status={ status }
			hasData={ !! data }
			error={ error }
			errorLabel={ __( 'Could not load prompt data.', 'newspack-plugin' ) }
			className="newspack-insights__prompts-tab"
			loadingMessages={ TAB_LOADING_MESSAGES.prompts }
		>
			{ data && (
				<>
					{ data.tab_error && <PromptsErrorBanner /> }
					<InfoCallout heading={ __( 'About Direct vs Influenced conversion', 'newspack-plugin' ) } dismissible persist={ false }>
						<p>
							<strong>{ __( 'Direct', 'newspack-plugin' ) }</strong>{ ' ' }
							{ __(
								'conversions happen in the same session as a prompt impression. The prompt is credited regardless of whether checkout happens on the same page (embedded checkout block) or after clicking through to a subscription page.',
								'newspack-plugin'
							) }
						</p>
						<p>
							<strong>{ __( 'Influenced', 'newspack-plugin' ) }</strong>{ ' ' }
							{ __(
								'conversions happen after a prompt impression but in a later session, within a lookback window (7 days for free conversions, 14 days for paid).',
								'newspack-plugin'
							) }
						</p>
						<p>
							{ __(
								'Same-session is Direct. Later-session-within-lookback is Influenced. The two are mutually exclusive and together capture every prompt-touched conversion within the lookback period.',
								'newspack-plugin'
							) }
						</p>
					</InfoCallout>
					<PromptExposureSection
						current={ data.current }
						previous={ data.previous }
						lastUpdated={ <LastUpdated tab="prompts" range={ range } previousRange={ previousRange } /> }
					/>
					<PromptEngagementSection current={ data.current } previous={ data.previous } />
					<FreeReaderConversionSection current={ data.current } previous={ data.previous } />
					<PaidReaderConversionSection current={ data.current } previous={ data.previous } />
					<RevenueFromPromptsSection current={ data.current } previous={ data.previous } />
					<HowReadersConvertSection current={ data.current } />
					<PerformanceBreakdownSection current={ data.current } />
				</>
			) }
		</TabStateView>
	);
};

export default PromptsTab;
