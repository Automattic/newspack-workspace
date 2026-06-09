/**
 * Engagement › Reader segments (NPPD-1649, Section 3).
 *
 * Three takeaway cards (device, returning-vs-new, newsletter status): each a
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
import { formatDecimal, formatDuration } from '../../components/format';
import TakeawayCard from '../viz/TakeawayCard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const rowsOf = ( payload?: MetricPayload ): MetricRow[] => ( Array.isArray( payload?.rows ) ? ( payload as MetricPayload ).rows ?? [] : [] );
const num = ( row: MetricRow | undefined, key: string ): number => ( typeof row?.[ key ] === 'number' ? ( row[ key ] as number ) : 0 );
const findRow = ( rows: MetricRow[], key: string, value: string ): MetricRow | undefined => rows.find( row => row[ key ] === value );
const pctMore = ( a: number, b: number ): number => ( b > 0 ? Math.round( ( ( a - b ) / b ) * 100 ) : 0 );
const cap = ( s: string ): string => s.charAt( 0 ).toUpperCase() + s.slice( 1 );

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

/** Newsletter status: subscriber vs non-subscriber avg engaged time. */
const newsletterTakeaway = ( payload?: MetricPayload ): Takeaway => {
	const rows = rowsOf( payload );
	const subRow = findRow( rows, 'segment', 'Subscriber' );
	const nonRow = findRow( rows, 'segment', 'Non-subscriber' );
	const bars = [
		{ label: __( 'Subscriber', 'newspack-plugin' ), value: num( subRow, 'avg_engagement_seconds' ) },
		{ label: __( 'Non-subscriber', 'newspack-plugin' ), value: num( nonRow, 'avg_engagement_seconds' ) },
	];
	if ( ! subRow || ! nonRow ) {
		return { bars };
	}
	const subTime = num( subRow, 'avg_engagement_seconds' );
	const nonTime = num( nonRow, 'avg_engagement_seconds' );
	return {
		bars,
		headline:
			subTime >= nonTime
				? sprintf(
						/* translators: %d: percentage. */
						__( 'Subscribers engage %d%% longer than non-subscribers', 'newspack-plugin' ),
						pctMore( subTime, nonTime )
				  )
				: sprintf(
						/* translators: %d: percentage. */
						__( 'Non-subscribers engage %d%% longer than subscribers', 'newspack-plugin' ),
						pctMore( nonTime, subTime )
				  ),
		sub: sprintf(
			/* translators: 1: subscriber avg time, 2: non-subscriber avg time. */
			__( '%1$s per session vs %2$s', 'newspack-plugin' ),
			formatDuration( subTime ),
			formatDuration( nonTime )
		),
	};
};

const ReaderSegmentsSection = ( { current }: SectionProps ) => {
	const device = deviceTakeaway( current.engagement_by_device_type );
	const returning = returningTakeaway( current.engagement_by_returning_vs_new );
	const newsletter = newsletterTakeaway( current.engagement_by_newsletter_status );

	return (
		<section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-segments">
			<h2 id="newspack-insights-engagement-segments" className="newspack-insights__section-heading">
				{ __( 'Reader segments', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">{ __( 'How engagement varies by segment.', 'newspack-plugin' ) }</p>
			<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-3">
				<TakeawayCard
					title={ __( 'Engagement by device', 'newspack-plugin' ) }
					payload={ current.engagement_by_device_type }
					headline={ device.headline }
					sub={ device.sub }
					bars={ device.bars }
				/>
				<TakeawayCard
					title={ __( 'New vs returning readers', 'newspack-plugin' ) }
					payload={ current.engagement_by_returning_vs_new }
					headline={ returning.headline }
					sub={ returning.sub }
					bars={ returning.bars }
				/>
				<TakeawayCard
					title={ __( 'Engagement by newsletter status', 'newspack-plugin' ) }
					payload={ current.engagement_by_newsletter_status }
					headline={ newsletter.headline }
					sub={ newsletter.sub }
					bars={ newsletter.bars }
				/>
			</div>
		</section>
	);
};

export default ReaderSegmentsSection;
