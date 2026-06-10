/**
 * PromptsTab (NPPD-1607).
 *
 * Tab 5 orchestrator. Mirrors the GatesTab loading / error / success
 * lifecycle and composes the seven Prompts sections.
 *
 * Date range picker affects every metric — there are no current-state
 * metrics on this tab, only window-scoped ones. Comparison toggle is
 * forwarded by the wizard chrome via the standard `previousRange`
 * prop; when set, the response carries a `previous` window that the
 * sections thread into their per-card MetricCards.
 *
 * Note: unlike Gates, Prompts renders no top-of-tab "preview" banner —
 * the tab is not behind a preview flag, and the spec calls for none.
 * The `tab_pending` flag stays in the response envelope for parity
 * with the other Insights tabs and for Phase 2.
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
import DirectVsInfluencedCallout from './prompts/DirectVsInfluencedCallout';
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

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading prompt data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load prompt data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	return (
		<div className="newspack-insights__prompts-tab">
			<DirectVsInfluencedCallout />
			<PromptExposureSection current={ data.current } previous={ data.previous } />
			<PromptEngagementSection current={ data.current } previous={ data.previous } />
			<FreeReaderConversionSection current={ data.current } previous={ data.previous } />
			<PaidReaderConversionSection current={ data.current } previous={ data.previous } />
			<RevenueFromPromptsSection current={ data.current } previous={ data.previous } />
			<HowReadersConvertSection current={ data.current } />
			<PerformanceBreakdownSection current={ data.current } />
		</div>
	);
};

export default PromptsTab;
