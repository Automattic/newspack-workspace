/**
 * Tests for TrafficSourcesSection (NPPD): the Top Campaigns table drops only the
 * rows where source AND medium AND campaign are all "(not set)"; rows with data
 * in any column are kept (including their "(not set)" cells). The table and the
 * rest of the section always render.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TrafficSourcesSection from './TrafficSourcesSection';
import type { InsightsWindow } from '../../../api/audience';

const breakdown = {
	computable: true,
	type: 'breakdown',
	rows: [ { channel: 'Organic Search', readers: 5120 } ],
};

const table = ( rows: unknown[] ) => ( { computable: true, type: 'table', rows } );

const windowOf = ( top_campaigns: unknown ): InsightsWindow =>
	( { top_campaigns, traffic_sources_breakdown: breakdown } ) as unknown as InsightsWindow;

describe( 'TrafficSourcesSection — Top Campaigns row filtering', () => {
	it( 'drops fully-unattributed (all "(not set)") rows but keeps real ones', () => {
		render(
			<TrafficSourcesSection
				current={ windowOf(
					table( [
						{ source: 'newsletter', medium: 'email', campaign: 'weekly-digest', readers: 820, sessions: 1140 },
						{ source: '(not set)', medium: '(not set)', campaign: '(not set)', readers: 300, sessions: 360 },
					] )
				) }
				previous={ null }
			/>
		);
		// The real row survives; the all-"(not set)" row is gone (no "(not set)" cell left).
		expect( screen.getByText( 'weekly-digest' ) ).toBeInTheDocument();
		expect( screen.queryByText( '(not set)' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps a row that has data in any column, including its "(not set)" cells', () => {
		render(
			<TrafficSourcesSection
				current={ windowOf( table( [ { source: 'google', medium: '(not set)', campaign: '(not set)', readers: 500, sessions: 700 } ] ) ) }
				previous={ null }
			/>
		);
		expect( screen.getByText( 'google' ) ).toBeInTheDocument();
		// The partially-set row stays, so its "(not set)" cells render alongside.
		expect( screen.getAllByText( '(not set)' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'shows the empty message (table still renders) when every row is "(not set)"', () => {
		render(
			<TrafficSourcesSection
				current={ windowOf(
					table( [
						{ source: '(not set)', medium: '(not set)', campaign: '(not set)', readers: 4200, sessions: 5100 },
						{ source: '', medium: '', campaign: '', readers: 1800, sessions: 2000 },
					] )
				) }
				previous={ null }
			/>
		);
		expect( screen.getByText( 'No campaign traffic in this timeframe.' ) ).toBeInTheDocument();
		// Section + breakdown stay visible.
		expect( screen.getByText( 'Traffic sources' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Traffic Sources Breakdown' ) ).toBeInTheDocument();
	} );
} );
