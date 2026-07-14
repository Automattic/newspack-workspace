/**
 * Newsletter Ads › Overview (NPPD-1861).
 *
 * Headline scorecards: a timeframe row (impressions / clicks / CTR / revenue)
 * and a second row mixing timeframe (eCPM, active ads) with the two clearly
 * labeled all-time cards driven by the lifetime counters. The lifetime cards
 * are cumulative, so they never carry a previous window or delta — a
 * period-over-period arrow on an ever-growing counter would always read "up".
 *
 * Empty state mirrors Advertising's ReachRevenueSection (NPPD-1697): a
 * whole-section `EmptyMetricSection` (`no_opportunity`) when the resolved
 * report saw no timeframe ad activity (`hasWindowActivity === false`). The tab
 * withholds the signal while the report isn't ready, so the all-time cards
 * survive the not-ready state.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/newsletter_ads';
import EmptyMetricSection from '../../components/EmptyMetricSection';
import Scorecard from '../../components/Scorecard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { Grid } from '../../../../../../packages/components/src';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
	/**
	 * Derived server signal: `false` only on a resolved window with zero
	 * newsletter ad activity. Absent while loading, on an errored metric, or —
	 * per the tab — while the report isn't ready (the all-time cards must stay).
	 */
	hasWindowActivity?: boolean;
	lastUpdated?: ReactNode;
}

// Scope-neutral heading matching Advertising's ReachRevenueSection: this
// section mixes timeframe cards with the two all-time cards, so a windowed
// "In the last N days" heading would sit above cards it doesn't describe. The
// page-level date picker carries the timeframe.
const TITLE = __( 'Reach & Revenue', 'newspack-plugin' );
const CAPTION = __( 'Newsletter ad volume and revenue, plus all-time totals.', 'newspack-plugin' );
// Timeframe metrics are non-computable only when dated ad tracking is
// unavailable (the not-ready state) — mirror the diagnostic's remediation.
const NOT_TRACKED_MESSAGE = __( 'Requires the latest Newspack Newsletters plugin.', 'newspack-plugin' );

const OverviewSection = ( { current, previous, hasWindowActivity, lastUpdated }: SectionProps ) => {
	// Whole-section empty: the report resolved with no ad activity. Strict
	// `=== false` so the absent-while-loading / absent-on-error / not-ready
	// cases fall through to the normal render.
	if ( hasWindowActivity === false ) {
		return (
			<EmptyMetricSection
				title={ TITLE }
				caption={ CAPTION }
				state="no_opportunity"
				body={ __(
					'No newsletter ad activity in this timeframe. Ad tracking is set up, but no impressions were recorded for this timeframe. Could be that no newsletters carrying ads were sent — worth expanding the date range or checking your ad placements.',
					'newspack-plugin'
				) }
			/>
		);
	}

	const excludedAds = current.revenue_excluded_ads?.computable ? current.revenue_excluded_ads?.value ?? 0 : 0;

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-overview">
			<SectionHeading id="newspack-insights-newsletter-ads-overview" title={ TITLE } description={ CAPTION } actions={ lastUpdated } />
			<VStack spacing={ 4 }>
				{ /* Row 1: the four timeframe headline scorecards. */ }
				<Grid columns={ 4 } gutter={ 16 } noMargin>
					<Scorecard
						label={ __( 'Impressions', 'newspack-plugin' ) }
						description={ __( 'How many times your ads were seen', 'newspack-plugin' ) }
						current={ current.total_impressions }
						previous={ previous?.total_impressions }
						// Timeframe counts are non-computable only when dated tracking is
						// unavailable (not-ready) — an em-dash there, not a fake 0.
						notComputableMessage={ NOT_TRACKED_MESSAGE }
					/>
					<Scorecard
						label={ __( 'Clicks', 'newspack-plugin' ) }
						description={ __( 'How many times your ads were clicked', 'newspack-plugin' ) }
						current={ current.total_clicks }
						previous={ previous?.total_clicks }
						notComputableMessage={ NOT_TRACKED_MESSAGE }
					/>
					<Scorecard
						label={ __( 'CTR', 'newspack-plugin' ) }
						description={ __( 'Clicks per impression', 'newspack-plugin' ) }
						current={ current.ctr }
						previous={ previous?.ctr }
						// A non-computable rate renders the em-dash treatment — never "0%".
						notComputableMessage={ __( 'No impressions in this timeframe to calculate a click-through rate.', 'newspack-plugin' ) }
					/>
					<Scorecard
						label={ __( 'Revenue', 'newspack-plugin' ) }
						description={ __( 'What your ads earned', 'newspack-plugin' ) }
						current={ current.total_revenue }
						previous={ previous?.total_revenue }
						notComputableMessage={ NOT_TRACKED_MESSAGE }
					/>
				</Grid>
				{ /* Row 2: remaining timeframe cards + the all-time lifetime counters.
			     Lifetime cards deliberately pass no `previous` — they're cumulative,
			     so a period delta is meaningless. */ }
				<Grid columns={ 4 } gutter={ 16 } noMargin>
					<Scorecard
						label={ __( 'eCPM', 'newspack-plugin' ) }
						description={ __( 'Revenue per 1,000 impressions', 'newspack-plugin' ) }
						current={ current.ecpm }
						previous={ previous?.ecpm }
						// eCPM is an undefined division without both revenue and impressions —
						// em-dash, never a misleading "$0.00".
						notComputableMessage={ __( 'Requires both revenue and impressions in this timeframe to calculate.', 'newspack-plugin' ) }
					/>
					<Scorecard
						label={ __( 'Active Ads', 'newspack-plugin' ) }
						description={ __( 'Ads that ran in newsletters', 'newspack-plugin' ) }
						current={ current.active_ads }
						previous={ previous?.active_ads }
						notComputableMessage={ NOT_TRACKED_MESSAGE }
					/>
					<Scorecard
						label={ __( 'All-Time Impressions', 'newspack-plugin' ) }
						description={ __( 'Cumulative total since tracking began', 'newspack-plugin' ) }
						current={ current.lifetime_impressions }
					/>
					<Scorecard
						label={ __( 'All-Time Clicks', 'newspack-plugin' ) }
						description={ __( 'Cumulative total since tracking began', 'newspack-plugin' ) }
						current={ current.lifetime_clicks }
					/>
				</Grid>
				{ excludedAds > 0 && (
					<p className="newspack-insights__table-footnote">
						{ sprintf(
							/* translators: %d: count of ads excluded from the revenue totals. */
							_n(
								'%d ad excluded from revenue (missing price or flight dates)',
								'%d ads excluded from revenue (missing price or flight dates)',
								excludedAds,
								'newspack-plugin'
							),
							excludedAds
						) }
					</p>
				) }
			</VStack>
		</Section>
	);
};

export default OverviewSection;
