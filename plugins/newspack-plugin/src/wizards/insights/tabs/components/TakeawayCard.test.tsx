/**
 * Tests for TakeawayCard (NPPD): the card title renders in every non-hidden
 * state, including the empty "Not enough data" state, so a blank card always
 * says what it would show.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TakeawayCard from './TakeawayCard';

const populated = { computable: true, type: 'table', rows: [ { device: 'desktop', avg_engagement_seconds: 120 } ] };

describe( 'TakeawayCard', () => {
	it( 'renders the title alongside the headline when populated', () => {
		render(
			<TakeawayCard
				title="Engagement by device"
				payload={ populated }
				headline="Desktop readers spend 40% longer per session"
				sub="than mobile readers (2:00 vs 1:25)"
				bars={ [
					{ label: 'Desktop', value: 120 },
					{ label: 'Mobile', value: 85 },
				] }
			/>
		);
		expect( screen.getByText( 'Engagement by device' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Desktop readers spend 40% longer per session' ) ).toBeInTheDocument();
	} );

	it( 'renders the title even in the empty state (no headline / no bars)', () => {
		render( <TakeawayCard title="Engagement by traffic source" payload={ { computable: true, type: 'table', rows: [] } } bars={ [] } /> );
		expect( screen.getByText( 'Engagement by traffic source' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not enough data in this timeframe.' ) ).toBeInTheDocument();
	} );

	it( 'renders nothing for a hidden_in_v1 metric', () => {
		const { container } = render(
			<TakeawayCard title="Engagement by device" payload={ { value: null, computable: false, hidden_in_v1: true } } bars={ [] } />
		);
		expect( container ).toBeEmptyDOMElement();
	} );
} );
