/**
 * TakeawayCard (NPPD-1649) — Engagement Reader Segments.
 *
 * Replaces a dense comparison table with a scannable card: a one-line headline
 * comparison, a muted sub-line with the raw figures, and a small inline bar
 * chart. Matches the scorecard chrome (border, padding, top accent). Routes the
 * orchestrator payload's graceful-failure states through MetricNote, and shows a
 * muted note when there isn't enough data to compute a comparison.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import MetricNote from './MetricNote';
import type { MetricPayload } from './metrics';
import BarChart from './BarChart';

export interface TakeawayCardProps {
	/** Card title, rendered even in the empty / failure states. */
	title: string;
	payload?: MetricPayload;
	/** One-line comparison statement (may contain emphasized spans). */
	headline?: React.ReactNode;
	/** Muted secondary line with the underlying figures. */
	sub?: string;
	/** Bars for the inline mini chart. */
	bars: { label: string; value: number }[];
	/** Render the bar hover value with its unit, e.g. "98 seconds". */
	formatValue?: ( value: number ) => string;
}

const TakeawayCard = ( { title, payload, headline, sub, bars, formatValue }: TakeawayCardProps ) => {
	if ( ! payload || payload.hidden_in_v1 ) {
		return null;
	}

	let body: React.ReactNode;
	if ( payload.overlay ) {
		body = <MetricNote overlay={ payload.overlay } />;
	} else if ( payload.error ) {
		body = <MetricNote error />;
	} else if ( payload.not_configured ) {
		body = <MetricNote notConfigured />;
	} else if ( ! headline || bars.length === 0 ) {
		body = <p className="newspack-insights__takeaway-empty">{ __( 'Not enough data in this timeframe.', 'newspack-plugin' ) }</p>;
	} else {
		body = (
			<>
				<p className="newspack-insights__takeaway-headline">{ headline }</p>
				{ sub && <p className="newspack-insights__takeaway-sub">{ sub }</p> }
				<div className="newspack-insights__takeaway-chart">
					<BarChart bars={ bars } formatValue={ formatValue } />
				</div>
			</>
		);
	}

	return (
		<div className="newspack-insights__takeaway-card">
			<h3 className="newspack-insights__chart-card-title newspack-insights__takeaway-title">{ title }</h3>
			{ body }
		</div>
	);
};

export default TakeawayCard;
