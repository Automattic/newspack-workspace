/**
 * EngagementTab (Tab 2, NPPD-1649).
 *
 * GA4-backed Engagement tab. Fetches the Engagement orchestrator endpoint and
 * renders three sections — Overall engagement quality, Reader segments, Content
 * engagement — with the same connect-banner / loading / error lifecycle as
 * AudienceTab.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import useEngagementData from '../hooks/useEngagementData';
import ConnectBanner from './components/ConnectBanner';
import QualitySection from './engagement/sections/QualitySection';
import ContentEngagementSection from './engagement/sections/ContentEngagementSection';
import ReaderSegmentsSection from './engagement/sections/ReaderSegmentsSection';
import './engagement/engagement.scss';

export interface EngagementTabProps {
	range: DateRange;
	previousRange: DateRange | null;
}

const EngagementTab = ( { range, previousRange }: EngagementTabProps ) => {
	const { status, data, error } = useEngagementData( range, previousRange );

	if ( status === 'loading' && ! data ) {
		return (
			<div className="newspack-insights__tab-loading" role="status" aria-live="polite">
				{ __( 'Loading engagement data…', 'newspack-plugin' ) }
			</div>
		);
	}

	if ( status === 'error' ) {
		return (
			<div className="newspack-insights__tab-error" role="alert">
				<p>{ __( 'Could not load engagement data.', 'newspack-plugin' ) }</p>
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
	// Fixture mode returns a `previous` window unconditionally, so gate here.
	const previous = previousRange ? data.previous ?? null : null;

	// A refetch (date range / comparison toggle change) keeps the prior window's
	// values on screen while the new ones load. Mute the populated sections and
	// float a spinner so the change is clearly registering (NPPD fix #8).
	const isRefreshing = status === 'loading' && !! data;

	return (
		<div className={ classnames( 'newspack-insights__engagement-tab', { 'is-refreshing': isRefreshing } ) } aria-busy={ isRefreshing }>
			{ isRefreshing && (
				<div className="newspack-insights__tab-refreshing" role="status" aria-live="polite">
					<Spinner />
					<span className="screen-reader-text">{ __( 'Updating…', 'newspack-plugin' ) }</span>
				</div>
			) }
			<QualitySection current={ current } previous={ previous } />
			<ReaderSegmentsSection current={ current } previous={ previous } />
			<ContentEngagementSection current={ current } previous={ previous } />
		</div>
	);
};

export default EngagementTab;
