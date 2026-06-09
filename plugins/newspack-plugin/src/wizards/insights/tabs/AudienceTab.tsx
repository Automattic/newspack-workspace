/**
 * AudienceTab (Tab 1, NPPD-1649).
 *
 * GA4-backed Audience tab. Fetches the Audience orchestrator endpoint and
 * renders six sections. When GA4 isn't connected the orchestrator returns a
 * tab-level error and the whole tab becomes a single connect banner. Mirrors
 * the GatesTab loading/error/success lifecycle.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useAudienceData from '../hooks/useAudienceData';
import ConnectBanner from './components/ConnectBanner';
import ReachSection from './audience/sections/ReachSection';
import CompositionSection from './audience/sections/CompositionSection';
import TimeTrendsSection from './audience/sections/TimeTrendsSection';
import TrafficSourcesSection from './audience/sections/TrafficSourcesSection';
import GeographicSection from './audience/sections/GeographicSection';
import ContentPerformanceSection from './audience/sections/ContentPerformanceSection';
import './audience/audience.scss';

export interface AudienceTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const AudienceTab = ( { range, previousRange }: AudienceTabProps ) => {
	const { status, data, error } = useAudienceData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading audience data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load audience data.', 'newspack-plugin' ) }</p>
				{ error && <p className="newspack-insights__tab-error-detail">{ error }</p> }
			</div>
		);
	}

	if ( ! data ) {
		return null;
	}

	if ( data.tab_error || ! data.current ) {
		return <ConnectBanner text={ data.banner_text } />;
	}

	const current = data.current;
	// Only surface comparison deltas when the toggle is on (previousRange set).
	// Fixture mode returns a `previous` window unconditionally, so gate on the
	// toggle here rather than on the response — matches Gates/Subscribers/Donors.
	const previous = previousRange ? data.previous ?? null : null;

	return (
		<div className="newspack-insights__audience-tab">
			<ReachSection current={ current } previous={ previous } />
			<CompositionSection current={ current } previous={ previous } />
			<TrafficSourcesSection current={ current } previous={ previous } />
			<GeographicSection current={ current } previous={ previous } />
			<ContentPerformanceSection current={ current } previous={ previous } />
			<TimeTrendsSection current={ current } previous={ previous } />
		</div>
	);
};

export default AudienceTab;
