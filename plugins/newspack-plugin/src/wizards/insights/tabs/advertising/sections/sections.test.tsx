/**
 * Section-level tests for the Advertising tab (Tab 8, NPPD-1618): each section
 * wires the orchestrator's metric payloads to the shared scorecard / table /
 * chart components with the right keys, formats, and graceful-failure states.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import ReachRevenueSection from './ReachRevenueSection';
import InventoryPerformanceSection from './InventoryPerformanceSection';
import TopPerformersSection from './TopPerformersSection';

const metrics: InsightsWindow = {
	total_impressions: { value: 2400000, computable: true, type: 'count' },
	total_revenue: { value: 4200, computable: true, type: 'currency' },
	avg_ecpm: { value: 1.75, computable: true, type: 'currency' },
	fill_rate: { value: 0.87, computable: true, type: 'rate' },
	viewability_rate: { value: null, computable: false, overlay: { type: 'data_unavailable' } },
	direct_vs_programmatic: {
		type: 'breakdown',
		computable: true,
		rows: [
			{ label: 'direct', revenue: 2520, impressions: 1320000 },
			{ label: 'programmatic', revenue: 1680, impressions: 1080000 },
		],
	},
	top_ad_units: {
		type: 'table',
		computable: true,
		rows: [ { ad_unit: 'Homepage Leaderboard', impressions: 500000, revenue: 900, ecpm: 1.8 } ],
	},
	top_advertisers: {
		type: 'table',
		computable: true,
		rows: [ { advertiser: 'Acme Co', impressions: 300000, revenue: 600 } ],
	},
};

describe( 'Advertising sections', () => {
	it( 'ReachRevenueSection shows impressions, revenue, and the revenue-mix card', () => {
		render( <ReachRevenueSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Total Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '2,400,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
		// Definitional descriptions fill the third slot (no short caption).
		expect( screen.getByText( 'Total ad impressions served on your site in this timeframe.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total ad revenue earned in this timeframe, before fees.' ) ).toBeInTheDocument();
		// Revenue Mix scorecard (60% direct of 2520/4200).
		expect( screen.getByText( 'Revenue Mix' ) ).toBeInTheDocument();
		expect( screen.getByText( '60%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'from direct sales' ) ).toBeInTheDocument();
	} );

	it( 'InventoryPerformanceSection shows eCPM/fill and the viewability overlay', () => {
		render( <InventoryPerformanceSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( '$1.75' ) ).toBeInTheDocument();
		expect( screen.getByText( '87%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
	} );

	it( 'TopPerformersSection renders the two tables only (no device pie)', () => {
		render( <TopPerformersSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Homepage Leaderboard' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Acme Co' ) ).toBeInTheDocument();
		// Performance by Device pie is no longer rendered on Tab 8.
		expect( screen.queryByText( 'Performance by Device' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Smartphone' ) ).not.toBeInTheDocument();
	} );

	it( 'Top Advertisers collapses to 5 rows behind a See more toggle', () => {
		const many: InsightsWindow = {
			...metrics,
			top_advertisers: {
				type: 'table',
				computable: true,
				rows: Array.from( { length: 8 }, ( _, i ) => ( { advertiser: `Adv ${ i + 1 }`, impressions: 100, revenue: 10 } ) ),
			},
		};
		render( <TopPerformersSection current={ many } previous={ null } /> );
		expect( screen.getByText( 'Adv 5' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Adv 6' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: /See more/ } ) );

		expect( screen.getByText( 'Adv 6' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Adv 8' ) ).toBeInTheDocument();
	} );

	it( 'Top Ad Units collapses to 5 rows behind a See more toggle', () => {
		const many: InsightsWindow = {
			...metrics,
			top_ad_units: {
				type: 'table',
				computable: true,
				rows: Array.from( { length: 8 }, ( _, i ) => ( { ad_unit: `Unit ${ i + 1 }`, impressions: 100, revenue: 10, ecpm: 1 } ) ),
			},
		};
		render( <TopPerformersSection current={ many } previous={ null } /> );
		expect( screen.getByText( 'Unit 5' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Unit 6' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: /See more/ } ) );

		expect( screen.getByText( 'Unit 6' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Unit 8' ) ).toBeInTheDocument();
	} );

	it( 'handles a zero-impressions window without erroring', () => {
		const zero: InsightsWindow = {
			total_impressions: { value: 0, computable: true, type: 'count' },
			total_revenue: { value: 0, computable: true, type: 'currency' },
		};
		render( <ReachRevenueSection current={ zero } previous={ null } /> );
		expect( screen.getByText( '0' ) ).toBeInTheDocument();
		expect( screen.getByText( '$0.00' ) ).toBeInTheDocument();
	} );
} );
