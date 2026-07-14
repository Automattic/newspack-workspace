/**
 * TenureSection (NPPD-1616).
 *
 * Subscriber tenure summary — median + 25th / 75th percentiles computed
 * client-side from the raw per-active-subscription tenure_days payload
 * returned by the REST endpoint. The histogram that previously rendered
 * below these callouts was removed (it duplicated the same information
 * in chart form without adding insight); the backend method is kept in
 * place for potential v1.1 revival of a richer tenure visualization.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import type { TenureDistributionRow } from '../../api/subscribers';
import MetricCard from '../components/MetricCard';
import Section from '../components/Section';
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';

export interface TenureSectionProps {
	rows: TenureDistributionRow[];
}

const percentile = ( sorted: number[], p: number ): number => {
	if ( sorted.length === 0 ) {
		return 0;
	}
	if ( sorted.length === 1 ) {
		return sorted[ 0 ];
	}
	const rank = ( sorted.length - 1 ) * p;
	const lower = Math.floor( rank );
	const upper = Math.ceil( rank );
	const weight = rank - lower;
	return sorted[ lower ] * ( 1 - weight ) + sorted[ upper ] * weight;
};

const TenureSection = ( { rows }: TenureSectionProps ) => {
	const stats = useMemo( () => {
		if ( rows.length === 0 ) {
			return null;
		}
		const days = rows
			.map( r => r.tenure_days )
			.filter( ( d ): d is number => Number.isFinite( d ) )
			.sort( ( a, b ) => a - b );
		return {
			p25: Math.round( percentile( days, 0.25 ) ),
			median: Math.round( percentile( days, 0.5 ) ),
			p75: Math.round( percentile( days, 0.75 ) ),
		};
	}, [ rows ] );

	if ( ! stats ) {
		return (
			<Section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
				<SectionHeading
					id="newspack-insights-tenure-heading"
					title={ __( 'Subscriber Tenure', 'newspack-plugin' ) }
					description={ __( 'Median subscription length, plus the 25th and 75th percentiles.', 'newspack-plugin' ) }
				/>
				<SectionEmpty>{ __( 'No subscribers yet — tenure data will appear once subscriptions exist.', 'newspack-plugin' ) }</SectionEmpty>
			</Section>
		);
	}

	// Narrative below the callouts. Translates the percentile numbers
	// into plain language so the section reads as more than three bare
	// numbers. The second sentence is suppressed when the 75th
	// percentile collapses to the median (a degenerate case with very
	// few subscribers — saying "a quarter have been here longer than X"
	// when X equals the median is redundant).
	const showSecondSentence = stats.p75 > stats.median;
	const medianSentence = sprintf(
		/* translators: %d: median tenure in days */
		_n(
			'Half of your subscribers have been here longer than %d day.',
			'Half of your subscribers have been here longer than %d days.',
			stats.median,
			'newspack-plugin'
		),
		stats.median
	);
	const p75Sentence = sprintf(
		/* translators: %d: 75th-percentile tenure in days */
		_n( 'A quarter have been here longer than %d day.', 'A quarter have been here longer than %d days.', stats.p75, 'newspack-plugin' ),
		stats.p75
	);

	// The plain-language narrative now rides in the section description, above the
	// tiles (DSGNEWS-188 — the three percentiles render as standard scorecard
	// tiles rather than a bespoke stats list).
	const narrative = showSecondSentence ? `${ medianSentence } ${ p75Sentence }` : medianSentence;

	return (
		<Section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
			<SectionHeading id="newspack-insights-tenure-heading" title={ __( 'Subscriber Tenure', 'newspack-plugin' ) } description={ narrative } />
			<Grid columns={ 4 } gutter={ 16 } noMargin>
				<MetricCard
					label={ __( 'Median Tenure', 'newspack-plugin' ) }
					value={ stats.median }
					format="number"
					secondary={ _n( 'day', 'days', stats.median, 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( '25th Percentile', 'newspack-plugin' ) }
					value={ stats.p25 }
					format="number"
					secondary={ _n( 'day', 'days', stats.p25, 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( '75th Percentile', 'newspack-plugin' ) }
					value={ stats.p75 }
					format="number"
					secondary={ _n( 'day', 'days', stats.p75, 'newspack-plugin' ) }
				/>
			</Grid>
		</Section>
	);
};

export default TenureSection;
