/**
 * Tests for ReaderSegmentsSection — specifically the "Engagement by newsletter
 * status" card, which matches orchestrator rows on the stable, non-translated
 * segment keys ('subscriber' / 'not_subscribed'). A regression guard: when the
 * keys don't line up, the card falls through to its empty state.
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

const table = ( rows: unknown[] ) => ( { computable: true, type: 'table', rows } );

const newsletterStatus = ( segmentKeys: [ string, string ] ) =>
	table( [
		{ segment: segmentKeys[ 0 ], sessions: 61200, avg_pages_per_session: 3.4, avg_engagement_seconds: 224 },
		{ segment: segmentKeys[ 1 ], sessions: 219800, avg_pages_per_session: 2.0, avg_engagement_seconds: 121 },
	] );

const windowOf = ( engagement_by_newsletter_status: unknown ): InsightsWindow => ( { engagement_by_newsletter_status } ) as unknown as InsightsWindow;

/** The card body is a sibling of the title within the same takeaway card. */
const newsletterCard = (): HTMLElement => {
	const title = screen.getByText( 'Engagement by newsletter status' );
	return title.closest( '.newspack-insights__takeaway-card' ) as HTMLElement;
};

describe( 'ReaderSegmentsSection — newsletter status card', () => {
	it( 'renders the populated takeaway when rows use the stable segment keys', () => {
		render( <ReaderSegmentsSection current={ windowOf( newsletterStatus( [ 'subscriber', 'not_subscribed' ] ) ) } previous={ null } /> );
		const card = newsletterCard();
		// 224s vs 121s → subscribers lead by 85%.
		expect( within( card ).getByText( 'Subscribers engage 85% longer than non-subscribers' ) ).toBeInTheDocument();
		expect( within( card ).getByText( '3:44 per session vs 2:01' ) ).toBeInTheDocument();
		expect( within( card ).queryByText( 'Not enough data in this timeframe.' ) ).not.toBeInTheDocument();
	} );

	it( 'falls through to the empty state when segment keys do not match (regression guard)', () => {
		// The translated labels the orchestrator used to emit no longer match.
		render(
			<ReaderSegmentsSection current={ windowOf( newsletterStatus( [ 'Newsletter subscriber', 'Not subscribed' ] ) ) } previous={ null } />
		);
		const card = newsletterCard();
		expect( within( card ).getByText( 'Not enough data in this timeframe.' ) ).toBeInTheDocument();
	} );
} );
