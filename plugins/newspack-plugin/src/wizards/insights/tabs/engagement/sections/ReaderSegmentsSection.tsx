/**
 * Engagement › Reader segments (NPPD-1649, Section 3).
 *
 * Three takeaway cards (device, returning-vs-new, traffic source): each a
 * one-line comparison + an inline mini bar chart, derived from the same
 * orchestrator metrics that previously rendered as dense tables.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import type { MetricPayload, MetricRow } from '../../components/metrics';
import { formatDecimal, formatDuration, formatNumber } from '../../components/format';
import SectionHeading from '../../components/SectionHeading';
import TakeawayCard from '../viz/TakeawayCard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

// Stable segment keys emitted by the orchestrator
// (Engagement_Metric::engagement_by_traffic_source_via_ga4). Kept here as the
// single JS-side reference for the PHP↔JS contract; a regression test
// (ReaderSegmentsSection.test.tsx) guards the match.
const TRAFFIC_SEGMENT = {
	newsletter: 'newsletter',
	other: 'other',
} as const;

const rowsOf = ( payload?: MetricPayload ): MetricRow[] => ( Array.isArray( payload?.rows ) ? ( payload as MetricPayload ).rows ?? [] : [] );
const num = ( row: MetricRow | undefined, key: string ): number => ( typeof row?.[ key ] === 'number' ? ( row[ key ] as number ) : 0 );
const findRow = ( rows: MetricRow[], key: string, value: string ): MetricRow | undefined => rows.find( row => row[ key ] === value );
const pctMore = ( a: number, b: number ): number => ( b > 0 ? Math.round( ( ( a - b ) / b ) * 100 ) : 0 );
const cap = ( s: string ): string => s.charAt( 0 ).toUpperCase() + s.slice( 1 );

// Bar-hover value formatters: append the unit so a hovered bar reads "98 seconds"
// or "2.4 pages" rather than a bare number. Engagement values are aggregate
// averages (always well above 1), so a single plural form is sufficient.
const formatSeconds = ( value: number ): string =>
	/* translators: %s: a number of seconds. */
	sprintf( __( '%s seconds', 'newspack-plugin' ), formatNumber( value ) );
const formatPages = ( value: number ): string =>
	/* translators: %s: a number of pages. */
	sprintf( __( '%s pages', 'newspack-plugin' ), formatDecimal( value ) );

interface Takeaway {
	headline?: string;
	sub?: string;
	bars: { label: string; value: number }[];
}

/** Device: longest-avg-time device vs mobile (or the shortest, if mobile is the longest). */
const deviceTakeaway = ( payload?: MetricPayload ): Takeaway => {
	const rows = rowsOf( payload );
	const bars = rows.map( r => ( { label: cap( String( r.device ?? '' ) ), value: num( r, 'avg_engagement_seconds' ) } ) );
	if ( rows.length < 2 ) {
		return { bars };
	}
	const subject = rows.reduce( ( best, r ) => ( num( r, 'avg_engagement_seconds' ) > num( best, 'avg_engagement_seconds' ) ? r : best ) );
	const mobile = findRow( rows, 'device', 'mobile' );
	const baseline =
		mobile && mobile.device !== subject.device
			? mobile
			: rows.reduce( ( least, r ) => ( num( r, 'avg_engagement_seconds' ) < num( least, 'avg_engagement_seconds' ) ? r : least ) );
	if ( baseline.device === subject.device ) {
		return { bars };
	}
	return {
		bars,
		headline: sprintf(
			/* translators: 1: device name (e.g. "Desktop"), 2: percentage. */
			__( '%1$s readers spend %2$d%% longer per session', 'newspack-plugin' ),
			cap( String( subject.device ) ),
			pctMore( num( subject, 'avg_engagement_seconds' ), num( baseline, 'avg_engagement_seconds' ) )
		),
		sub: sprintf(
			/* translators: 1: device name, 2: subject avg time, 3: baseline avg time. */
			__( 'than %1$s readers (%2$s vs %3$s)', 'newspack-plugin' ),
			String( baseline.device ),
			formatDuration( num( subject, 'avg_engagement_seconds' ) ),
			formatDuration( num( baseline, 'avg_engagement_seconds' ) )
		),
	};
};

/** Returning vs new: pages-per-session comparison (whichever is higher leads). */
const returningTakeaway = ( payload?: MetricPayload ): Takeaway => {
	const rows = rowsOf( payload );
	const newRow = findRow( rows, 'reader_type', 'new' );
	const retRow = findRow( rows, 'reader_type', 'returning' );
	const bars = [
		{ label: __( 'New', 'newspack-plugin' ), value: num( newRow, 'avg_pages_per_session' ) },
		{ label: __( 'Returning', 'newspack-plugin' ), value: num( retRow, 'avg_pages_per_session' ) },
	];
	if ( ! newRow || ! retRow ) {
		return { bars };
	}
	const newPages = num( newRow, 'avg_pages_per_session' );
	const retPages = num( retRow, 'avg_pages_per_session' );
	const returningLeads = retPages >= newPages;
	return {
		bars,
		headline: returningLeads
			? sprintf(
					/* translators: %d: percentage. */
					__( 'Returning readers view %d%% more pages per session', 'newspack-plugin' ),
					pctMore( retPages, newPages )
			  )
			: sprintf(
					/* translators: %d: percentage. */
					__( 'New readers view %d%% more pages per session', 'newspack-plugin' ),
					pctMore( newPages, retPages )
			  ),
		sub: returningLeads
			? sprintf(
					/* translators: 1: returning pages, 2: new pages. */
					__( 'than new readers (%1$s vs %2$s)', 'newspack-plugin' ),
					formatDecimal( retPages ),
					formatDecimal( newPages )
			  )
			: sprintf(
					/* translators: 1: new pages, 2: returning pages. */
					__( 'than returning readers (%1$s vs %2$s)', 'newspack-plugin' ),
					formatDecimal( newPages ),
					formatDecimal( retPages )
			  ),
	};
};

/** Traffic source: newsletter vs other avg engaged time per session. */
const trafficSourceTakeaway = ( payload?: MetricPayload ): Takeaway => {
	const rows = rowsOf( payload );
	// Match on the orchestrator's stable, non-translated segment keys; the bar
	// labels below carry the user-facing translated strings.
	const newsletterRow = findRow( rows, 'segment', TRAFFIC_SEGMENT.newsletter );
	const otherRow = findRow( rows, 'segment', TRAFFIC_SEGMENT.other );
	const bars = [
		{ label: __( 'Newsletter traffic', 'newspack-plugin' ), value: num( newsletterRow, 'avg_engagement_seconds' ) },
		{ label: __( 'Other traffic', 'newspack-plugin' ), value: num( otherRow, 'avg_engagement_seconds' ) },
	];
	// Below the orchestrator's minimum-sessions floor the comparison is too noisy
	// to render; suppress the headline so the card shows its "needs data" state.
	if ( ! newsletterRow || ! otherRow || payload?.needs_data ) {
		return { bars };
	}
	const newsletterTime = num( newsletterRow, 'avg_engagement_seconds' );
	const otherTime = num( otherRow, 'avg_engagement_seconds' );
	const newsletterLeads = newsletterTime >= otherTime;
	// Always phrase the comparison as "<leader> engages X% longer than <other>",
	// flipping the subject rather than inverting to "shorter" — matching the
	// device / new-vs-returning takeaways. "X% shorter" would divide by the
	// smaller value and overstate the gap (e.g. 49s vs 98s reads as "100% shorter"
	// when newsletter is only half as long).
	return {
		bars,
		headline: newsletterLeads
			? sprintf(
					/* translators: %d: percentage. */
					__( 'Newsletter traffic engages %d%% longer than other sources', 'newspack-plugin' ),
					pctMore( newsletterTime, otherTime )
			  )
			: sprintf(
					/* translators: %d: percentage. */
					__( 'Other sources engage %d%% longer than newsletter traffic', 'newspack-plugin' ),
					pctMore( otherTime, newsletterTime )
			  ),
		sub: newsletterLeads
			? sprintf(
					/* translators: 1: newsletter avg time, 2: other avg time. */
					__( '%1$s per session vs %2$s', 'newspack-plugin' ),
					formatDuration( newsletterTime ),
					formatDuration( otherTime )
			  )
			: sprintf(
					/* translators: 1: other avg time, 2: newsletter avg time. */
					__( '%1$s per session vs %2$s', 'newspack-plugin' ),
					formatDuration( otherTime ),
					formatDuration( newsletterTime )
			  ),
	};
};

const ReaderSegmentsSection = ( { current }: SectionProps ) => {
	const device = deviceTakeaway( current.engagement_by_device_type );
	const returning = returningTakeaway( current.engagement_by_returning_vs_new );
	const trafficSource = trafficSourceTakeaway( current.engagement_by_traffic_source );

	return (
		<section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-segments">
			<SectionHeading
				id="newspack-insights-engagement-segments"
				title={ __( 'Reader segments', 'newspack-plugin' ) }
				description={ __( 'How engagement varies by segment.', 'newspack-plugin' ) }
			/>
			<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-3">
				<TakeawayCard
					title={ __( 'Engagement by device', 'newspack-plugin' ) }
					payload={ current.engagement_by_device_type }
					headline={ device.headline }
					sub={ device.sub }
					bars={ device.bars }
					formatValue={ formatSeconds }
				/>
				<TakeawayCard
					title={ __( 'New vs returning readers', 'newspack-plugin' ) }
					payload={ current.engagement_by_returning_vs_new }
					headline={ returning.headline }
					sub={ returning.sub }
					bars={ returning.bars }
					formatValue={ formatPages }
				/>
				<TakeawayCard
					title={ __( 'Engagement by traffic source', 'newspack-plugin' ) }
					payload={ current.engagement_by_traffic_source }
					headline={ trafficSource.headline }
					sub={ trafficSource.sub }
					bars={ trafficSource.bars }
					formatValue={ formatSeconds }
				/>
			</div>
		</section>
	);
};

export default ReaderSegmentsSection;
