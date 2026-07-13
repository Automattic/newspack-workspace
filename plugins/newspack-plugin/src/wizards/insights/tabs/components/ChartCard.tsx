/**
 * ChartCard (NPPD-1649).
 *
 * Frame around a visualization (pie / line / bar) that centralizes the
 * graceful-failure states: hidden_in_v1 (renders nothing), and the shared
 * MetricNote treatment for overlay / error / not-configured. The section
 * passes the built chart as children; ChartCard renders it only when the
 * payload is computable.
 */

/**
 * Internal dependencies
 */
import { Card } from '../../../../../packages/components/src';
import MetricNote from './MetricNote';
import type { MetricPayload } from './metrics';

export interface ChartCardProps {
	/**
	 * Chart heading. Optional: a single-chart section whose own SectionHeading
	 * already names it (e.g. Revenue trend) omits this to avoid a duplicate title.
	 */
	title?: string;
	caption?: string;
	payload?: MetricPayload;
	children: React.ReactNode;
	/** Inline style forwarded to the underlying CoreCard (e.g. a grid-column span). */
	style?: React.CSSProperties;
}

const ChartCard = ( { title, caption, payload, children, style }: ChartCardProps ) => {
	if ( ! payload || payload.hidden_in_v1 ) {
		return null;
	}

	let body: React.ReactNode = children;
	// A degraded payload's overlay is an informational note over a still-valid
	// chart, not a replacement — keep the chart (matches MetricTable's guard).
	if ( payload.overlay && ! payload.degraded ) {
		body = <MetricNote overlay={ payload.overlay } />;
	} else if ( payload.error ) {
		body = <MetricNote error />;
	} else if ( payload.not_configured ) {
		body = <MetricNote notConfigured />;
	}

	return (
		<Card __experimentalCoreCard className="newspack-insights__chart-card" style={ style }>
			{ title && <h3 className="newspack-insights__chart-card-title">{ title }</h3> }
			{ caption && <p className="newspack-insights__chart-card-caption">{ caption }</p> }
			<div className="newspack-insights__chart-card-body">{ body }</div>
		</Card>
	);
};

export default ChartCard;
