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
import type { TenureDistributionRow } from '../../api/subscribers';

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
			<section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
				<h2 id="newspack-insights-tenure-heading" className="newspack-insights__section-heading">
					{ __( 'Subscriber tenure', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">
					{ __( 'No subscribers yet — tenure data will appear once subscriptions exist.', 'newspack-plugin' ) }
				</p>
			</section>
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

	return (
		<section className="newspack-insights__section newspack-insights__section--tenure" aria-labelledby="newspack-insights-tenure-heading">
			<h2 id="newspack-insights-tenure-heading" className="newspack-insights__section-heading">
				{ __( 'Subscriber tenure', 'newspack-plugin' ) }
			</h2>
			<div className="newspack-insights__tenure-card">
				<dl className="newspack-insights__stats-summary">
					<div>
						<dt>{ __( 'Median tenure', 'newspack-plugin' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: %d: number of days */
								_n( '%d day', '%d days', stats.median, 'newspack-plugin' ),
								stats.median
							) }
						</dd>
					</div>
					<div>
						<dt>{ __( '25th percentile', 'newspack-plugin' ) }</dt>
						<dd>
							{
								/* translators: %d: number of days */
								sprintf( _n( '%d day', '%d days', stats.p25, 'newspack-plugin' ), stats.p25 )
							}
						</dd>
					</div>
					<div>
						<dt>{ __( '75th percentile', 'newspack-plugin' ) }</dt>
						<dd>
							{
								/* translators: %d: number of days */
								sprintf( _n( '%d day', '%d days', stats.p75, 'newspack-plugin' ), stats.p75 )
							}
						</dd>
					</div>
				</dl>
				<p className="newspack-insights__tenure-narrative">
					{ medianSentence }
					{ showSecondSentence && ' ' }
					{ showSecondSentence && p75Sentence }
				</p>
			</div>
		</section>
	);
};

export default TenureSection;
