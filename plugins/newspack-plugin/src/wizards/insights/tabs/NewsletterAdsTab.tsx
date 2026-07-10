/**
 * NewsletterAdsTab.
 *
 * Newsletter Ads tab. Fetches the newsletter-ads orchestrator endpoint and
 * renders three sections. Lifecycle mirrors {@see AdvertisingTab} with one
 * deliberate difference: `is_report_ready: false` does NOT replace the whole
 * tab with a diagnostic. The lifetime counters (all-time impressions / clicks)
 * resolve without the per-send stats table, so a not-ready tab still renders
 * the Overview section — with an inline notice built from `readiness_issues` —
 * and only the timeframe-scoped Trend / Tables sections are withheld.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Notice, Button } from '../../../../packages/components/src';
import type { DateRange } from '../state/useDateRange';
import useNewsletterAdsData from '../hooks/useNewsletterAdsData';
import LastUpdated from '../components/LastUpdated';
import TabStateView from './components/TabStateView';
import TabSections from './components/TabSections';
import FinishConnectingDiagnostic from './components/FinishConnectingDiagnostic';
import OverviewSection from './newsletter_ads/sections/OverviewSection';
import TrendSection from './newsletter_ads/sections/TrendSection';
import TablesSection from './newsletter_ads/sections/TablesSection';

import './newsletter_ads/newsletter-ads.scss';

export interface NewsletterAdsTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

/**
 * Informational-only readiness codes: surfaced as an info notice but never
 * presented as a "finish connecting" blocker (they don't gate any section).
 */
const INFORMATIONAL_ISSUE_CODES = [ 'newsletter_ads_tracking_disabled' ];

const NewsletterAdsTab = ( { range, previousRange }: NewsletterAdsTabProps ) => {
	const { status, data, error } = useNewsletterAdsData( range, previousRange );
	const current = data?.current;

	// Tab visibility gate precedes the standard data lifecycle. Guarded by
	// `current` (skipped on initial load) and by `status` so a fetch error still
	// routes through TabStateView's error frame rather than being masked.
	if ( status !== 'error' && current && ! current.is_tab_visible ) {
		return null;
	}

	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate here.
	const previous = previousRange ? data?.previous?.metrics ?? null : null;

	const issues = current?.readiness_issues ?? [];
	const infoIssues = issues.filter( issue => INFORMATIONAL_ISSUE_CODES.includes( issue.code ) );
	const blockingIssues = issues.filter( issue => ! INFORMATIONAL_ISSUE_CODES.includes( issue.code ) );
	const isReportReady = !! current?.is_report_ready;

	return (
		<TabStateView
			status={ status }
			hasData={ !! current }
			error={ error }
			errorLabel={ __( 'Could not load newsletter ads data.', 'newspack-plugin' ) }
			className="newspack-insights__newsletter-ads-tab"
		>
			{ current && (
				<>
					{ /* No DataLagIndicator here: this tab reads local data with no lag,
					     so an "About this data / data as of" callout is noise. */ }
					{ /* Deliberate divergence from AdvertisingTab: readiness issues render as
					     an INLINE notice above the Overview section instead of replacing the
					     tab — the all-time cards below carry real signal without the stats
					     table (e.g. `newsletter_ads_stats_missing`). */ }
					{ ! isReportReady && blockingIssues.length > 0 && (
						<FinishConnectingDiagnostic
							heading={ __( 'Finish setting up newsletter ad tracking to see timeframe data', 'newspack-plugin' ) }
							issues={ blockingIssues }
						/>
					) }
					{ /* Informational notices (e.g. tracking disabled) — shown regardless of
					     readiness, never gating a section. */ }
					{ infoIssues.map( issue => (
						<Notice key={ issue.code } className="newspack-insights__newsletter-ads-info-notice" noticeText={ issue.message }>
							{ issue.remediation_url && (
								<Button
									variant="link"
									href={ issue.remediation_url }
									aria-label={ sprintf(
										/* translators: %s: the readiness issue being remediated. */
										__( 'Fix: %s', 'newspack-plugin' ),
										issue.message
									) }
								>
									{ __( 'Fix this →', 'newspack-plugin' ) }
								</Button>
							) }
						</Notice>
					) ) }
					<TabSections>
						<OverviewSection
							current={ current.metrics }
							previous={ previous }
							// The whole-section empty collapse only applies to a resolved report:
							// while not ready, the lifetime cards must stay on screen, so the
							// signal is withheld (undefined never collapses).
							hasWindowActivity={ isReportReady ? current.has_window_activity : undefined }
							lastUpdated={ <LastUpdated tab="newsletter_ads" range={ range } previousRange={ previousRange } /> }
						/>
						{ /* Timeframe-scoped sections need the stats table; withheld until ready. */ }
						{ isReportReady && <TrendSection current={ current.metrics } previous={ previous } /> }
						{ isReportReady && <TablesSection current={ current.metrics } /> }
					</TabSections>
				</>
			) }
		</TabStateView>
	);
};

export default NewsletterAdsTab;
