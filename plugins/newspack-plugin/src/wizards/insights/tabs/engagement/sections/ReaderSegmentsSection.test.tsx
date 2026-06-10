/**
 * Tests for ReaderSegmentsSection — specifically the "Engagement by traffic
 * source" card, which matches orchestrator rows on the stable, non-translated
 * segment keys ('newsletter' / 'other'). A regression guard: when the keys don't
 * line up, the card falls through to its empty state.
 */

/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReaderSegmentsSection from './ReaderSegmentsSection';
import type { InsightsWindow } from '../../../api/audience';

const table = ( rows: unknown[], extra: Record< string, unknown > = {} ) => ( { computable: true, type: 'table', rows, ...extra } );

const trafficSource = ( segmentKeys: [ string, string ], [ newsletterSec, otherSec ]: [ number, number ], extra: Record< string, unknown > = {} ) =>
	table(
		[
			{ segment: segmentKeys[ 0 ], sessions: 38200, avg_engagement_seconds: newsletterSec },
			{ segment: segmentKeys[ 1 ], sessions: 243600, avg_engagement_seconds: otherSec },
		],
		extra
	);

const windowOf = ( engagement_by_traffic_source: unknown ): InsightsWindow => ( { engagement_by_traffic_source } ) as unknown as InsightsWindow;

/** The card body is a sibling of the title within the same takeaway card. */
const trafficSourceCard = (): HTMLElement => {
	const title = screen.getByText( 'Engagement by traffic source' );
	return title.closest( '.newspack-insights__takeaway-card' ) as HTMLElement;
};

describe( 'ReaderSegmentsSection — traffic source card', () => {
	it( 'renders the populated takeaway when newsletter traffic leads', () => {
		render( <ReaderSegmentsSection current={ windowOf( trafficSource( [ 'newsletter', 'other' ], [ 98, 49 ] ) ) } previous={ null } /> );
		const card = trafficSourceCard();
		// 98s vs 49s → newsletter leads by 100%.
		expect( within( card ).getByText( 'Newsletter traffic engages 100% longer than other sources' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '1:38 per session vs 0:49' ) ).toBeInTheDocument();
		expect( within( card ).queryByText( 'Not enough data in this timeframe.' ) ).not.toBeInTheDocument();
		// Bar-hover values carry the "seconds" unit.
		expect( within( card ).getByText( '98 seconds' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '49 seconds' ) ).toBeInTheDocument();
	} );

	it( 'inverts the headline when other sources lead', () => {
		render( <ReaderSegmentsSection current={ windowOf( trafficSource( [ 'newsletter', 'other' ], [ 49, 98 ] ) ) } previous={ null } /> );
		const card = trafficSourceCard();
		// 49s vs 98s → other leads, so newsletter engages 100% shorter.
		expect( within( card ).getByText( 'Newsletter traffic engages 100% shorter than other sources' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '0:49 per session vs 1:38' ) ).toBeInTheDocument();
	} );

	it( 'shows the needs-data state when the newsletter cohort is below the floor', () => {
		render(
			<ReaderSegmentsSection
				current={ windowOf( trafficSource( [ 'newsletter', 'other' ], [ 98, 49 ], { needs_data: true } ) ) }
				previous={ null }
			/>
		);
		const card = trafficSourceCard();
		expect( within( card ).getByText( 'Not enough data in this timeframe.' ) ).toBeInTheDocument();
		expect( within( card ).queryByText( /Newsletter traffic engages/ ) ).not.toBeInTheDocument();
	} );

	it( 'falls through to the empty state when segment keys do not match (regression guard)', () => {
		// The orchestrator's stable keys no longer line up.
		render( <ReaderSegmentsSection current={ windowOf( trafficSource( [ 'Newsletter', 'Other' ], [ 98, 49 ] ) ) } previous={ null } /> );
		const card = trafficSourceCard();
		expect( within( card ).getByText( 'Not enough data in this timeframe.' ) ).toBeInTheDocument();
	} );
} );
