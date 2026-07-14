/**
 * Advertising › Reach & revenue (NPPD-1618, Section 1; empty states NPPD-1697;
 * cross-system scorecards NPPD-1675).
 *
 * Headline scorecards for the period: impressions served, revenue earned, and
 * two cross-system derived cards — RPM (revenue per 1,000 sessions) and
 * impressions per session — that join the GAM figures with GA4 sessions. Those
 * four sit in a --cols-4 row; a second --cols-4 row carries the
 * inventory-quality cards (eCPM, fill rate, viewability — folded in from the
 * former Inventory performance section, NPPD-1618 Section 2). The old Revenue
 * mix card was retired in favor of Impressions by type. Viewability degrades
 * to a `data_unavailable` overlay (rendered via the shared MetricNote) when the
 * publisher hasn't enabled Active View — handled centrally by Scorecard.
 *
 * Empty states (NPPD-1697), mirroring Donors (NPPD-1696) / Subscribers
 * (NPPD-1695):
 *   - Whole-section `EmptyMetricSection` (`no_opportunity`) when the resolved
 *     report saw no ad activity (`hasWindowActivity === false`). This is the
 *     off-season / no-impressions case.
 *   - Per-card no-revenue treatment on the Total Revenue card when impressions
 *     are running but revenue is zero — a whole-section empty would hide the
 *     real impressions count, so only that card gets the context line (delta
 *     suppressed).
 *
 * `is_loading` is gated at the AdvertisingTab level (NPPD-1684) — this section
 * only mounts on a resolved report, so neither branch can fire mid-load.
 * `hasWindowActivity` is additionally absent (not `false`) while loading or on
 * an errored metric, so the strict `=== false` check is belt-and-suspenders.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import type { AdvertisingProvider, InsightsWindow } from '../../../api/advertising';
import { Grid } from '../../../../../../packages/components/src';
import EmptyMetricSection from '../../components/EmptyMetricSection';
import MetricCard from '../../components/MetricCard';
import Scorecard from '../../components/Scorecard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { formatNumber } from '../../components/format';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
	/**
	 * Derived server signal: `false` only on a resolved window with zero ad
	 * activity. Absent while loading or on an errored metric (see api/advertising).
	 */
	hasWindowActivity?: boolean;
	lastUpdated?: ReactNode;
	/** Ad server backing the tab. Broadstreet is impressions-only (NPPD-2045). */
	activeProvider?: AdvertisingProvider | null;
}

const TITLE = __( 'Reach & Revenue', 'newspack-plugin' );
const CAPTION = __( 'Volume, revenue, and inventory quality for the period.', 'newspack-plugin' );

// Broadstreet has no revenue in its API (NPPD-2045), so its variant is impressions-only.
const BROADSTREET_TITLE = __( 'Reach', 'newspack-plugin' );
const BROADSTREET_CAPTION = __( 'Ad impressions in this timeframe', 'newspack-plugin' );

const ReachRevenueSection = ( { current, previous, hasWindowActivity, lastUpdated, activeProvider }: SectionProps ) => {
	// Broadstreet variant (NPPD-2045): impressions-only. Broadstreet's API carries no
	// revenue, so this renders four doable cards — total impressions, impressions per
	// session, overall CTR, and mobile share — with no revenue / RPM / eCPM / fill /
	// viewability. Overall CTR and mobile share are rate cards (percent, em-dash when
	// there were no impressions to divide by).
	if ( 'broadstreet' === activeProvider ) {
		if ( hasWindowActivity === false ) {
			return (
				<EmptyMetricSection
					title={ BROADSTREET_TITLE }
					caption={ BROADSTREET_CAPTION }
					state="no_opportunity"
					body={ __(
						'No ad impressions in this timeframe. Worth expanding the date range or checking your Broadstreet zone setup.',
						'newspack-plugin'
					) }
				/>
			);
		}
		return (
			<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-reach-revenue">
				<SectionHeading
					id="newspack-insights-advertising-reach-revenue"
					title={ BROADSTREET_TITLE }
					description={ BROADSTREET_CAPTION }
					actions={ lastUpdated }
				/>
				<Grid columns={ 4 } gutter={ 16 } noMargin>
					<Scorecard
						label={ __( 'Impressions', 'newspack-plugin' ) }
						description={ __( 'Total ad impressions served on your site', 'newspack-plugin' ) }
						current={ current.total_impressions }
						previous={ previous?.total_impressions }
					/>
					<Scorecard
						label={ __( 'Impressions per Session', 'newspack-plugin' ) }
						description={ __( 'Avg ad impressions a reader sees each session', 'newspack-plugin' ) }
						current={ current.avg_impressions_per_session }
						previous={ previous?.avg_impressions_per_session }
					/>
					<Scorecard
						label={ __( 'Overall CTR', 'newspack-plugin' ) }
						description={ __( 'Clicks per impression across all ads', 'newspack-plugin' ) }
						current={ current.overall_ctr }
						previous={ previous?.overall_ctr }
					/>
					<Scorecard
						label={ __( 'Mobile Share', 'newspack-plugin' ) }
						description={ __( 'Share of impressions served to mobile', 'newspack-plugin' ) }
						current={ current.mobile_share }
						previous={ previous?.mobile_share }
					/>
				</Grid>
			</Section>
		);
	}

	// Whole-section empty: the report resolved with no ad activity. Collapse the
	// grid to a single callout. Strict `=== false` so the absent-while-loading /
	// absent-on-error cases fall through to the normal render.
	if ( hasWindowActivity === false ) {
		return (
			<EmptyMetricSection
				title={ TITLE }
				caption={ CAPTION }
				state="no_opportunity"
				body={ __(
					'No ad impressions in this timeframe. Your ad server is configured, but the report shows no impressions for this timeframe. Could be a placement question, an off-season period, or a configuration issue. Worth expanding the date range or checking your ad unit setup.',
					'newspack-plugin'
				) }
			/>
		);
	}

	const impressions = current.total_impressions;
	const revenue = current.total_revenue;
	// Per-card no-revenue state: impressions are running but the report shows zero
	// revenue. Gated on both metrics being computable so an errored metric keeps
	// its own error treatment rather than reading as an honest zero.
	const noRevenue = !! impressions?.computable && !! revenue?.computable && ( impressions?.value ?? 0 ) > 0 && ( revenue?.value ?? 0 ) === 0;

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-reach-revenue">
			<SectionHeading id="newspack-insights-advertising-reach-revenue" title={ TITLE } description={ CAPTION } actions={ lastUpdated } />
			<VStack spacing={ 4 }>
				{ /* Row 1: the four headline scorecards. Volume + revenue (raw), then the
			     two cross-system derived cards (NPPD-1675) — RPM and impressions per
			     session — which join GAM figures with GA4 sessions. */ }
				<Grid columns={ 4 } gutter={ 16 } noMargin>
					<Scorecard
						label={ __( 'Impressions', 'newspack-plugin' ) }
						description={ __( 'Total ad impressions served on your site', 'newspack-plugin' ) }
						current={ current.total_impressions }
						previous={ previous?.total_impressions }
					/>
					{ noRevenue ? (
						<MetricCard
							label={ __( 'Revenue', 'newspack-plugin' ) }
							value={ 0 }
							format="currency"
							// No previousValue → the period delta is suppressed; a "↓ 100%" would
							// misread the honest zero against a prior window that had revenue.
							secondary={ sprintf(
								/* translators: %s: count of ad impressions in this timeframe */
								__( '%s impressions, but no revenue this timeframe', 'newspack-plugin' ),
								formatNumber( impressions?.value ?? 0 )
							) }
							description={ __( 'Total ad revenue earned, before fees', 'newspack-plugin' ) }
						/>
					) : (
						<Scorecard
							label={ __( 'Revenue', 'newspack-plugin' ) }
							description={ __( 'Total ad revenue earned, before fees', 'newspack-plugin' ) }
							current={ current.total_revenue }
							previous={ previous?.total_revenue }
						/>
					) }
					<Scorecard
						label={ __( 'RPM', 'newspack-plugin' ) }
						description={ __( 'Revenue per 1,000 sessions', 'newspack-plugin' ) }
						current={ current.rpm }
						previous={ previous?.rpm }
					/>
					<Scorecard
						label={ __( 'Impressions per Session', 'newspack-plugin' ) }
						description={ __( 'Avg ad impressions a reader sees each session', 'newspack-plugin' ) }
						current={ current.avg_impressions_per_session }
						previous={ previous?.avg_impressions_per_session }
					/>
				</Grid>
				{ /* Row 2: inventory-quality scorecards (moved from the former Inventory
			     performance section). The old Revenue mix card was retired in favor
			     of the Impressions by type breakdown (NPPD-1881). */ }
				<Grid columns={ 4 } gutter={ 16 } noMargin>
					<Scorecard
						label={ __( 'Average eCPM', 'newspack-plugin' ) }
						description={ __( 'Your ad rate', 'newspack-plugin' ) }
						current={ current.avg_ecpm }
						previous={ previous?.avg_ecpm }
					/>
					<Scorecard
						label={ __( 'Fill Rate', 'newspack-plugin' ) }
						description={ __( 'How often slots fill', 'newspack-plugin' ) }
						current={ current.fill_rate }
						previous={ previous?.fill_rate }
					/>
					<Scorecard
						label={ __( 'Viewability Rate', 'newspack-plugin' ) }
						description={ __( 'How often ads are seen', 'newspack-plugin' ) }
						current={ current.viewability_rate }
						previous={ previous?.viewability_rate }
					/>
				</Grid>
			</VStack>
		</Section>
	);
};

export default ReachRevenueSection;
