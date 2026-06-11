/**
 * Scorecard (NPPD-1649).
 *
 * Thin wrapper that maps a metric payload + section copy to MetricCard props
 * via {@see payloadToCard} and renders the card — or nothing, when the metric
 * is hidden in v1. Keeps section components declarative.
 */

/**
 * Internal dependencies
 */
import MetricCard from './MetricCard';
import { payloadToCard, type PayloadToCardArgs } from './metrics';

const Scorecard = ( props: PayloadToCardArgs ) => {
	const cardProps = payloadToCard( props );
	if ( ! cardProps ) {
		return null;
	}
	return <MetricCard { ...cardProps } />;
};

export default Scorecard;
