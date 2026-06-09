/**
 * Section-level tests for the Advertising tab (Tab 8, NPPD-1618): each section
 * wires the orchestrator's metric payloads to the shared scorecard / table /
 * chart components with the right keys, formats, and graceful-failure states.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import ReachRevenueSection from './ReachRevenueSection';
import InventoryPerformanceSection from './InventoryPerformanceSection';
import RevenueBreakdownSection from './RevenueBreakdownSection';
import AudienceReachSection from './AudienceReachSection';

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
	performance_by_device: {
		type: 'table',
		computable: true,
		rows: [ { device: 'Smartphone', impressions: 1400000, revenue: 2400, ecpm: 1.7 } ],
	},
	top_countries: {
		type: 'table',
		computable: true,
		rows: [ { country: 'United States', impressions: 2000000, revenue: 3400, ecpm: 1.7 } ],
	},
};

describe( 'Advertising sections', () => {
	it( 'ReachRevenueSection shows impressions and currency revenue', () => {
		render( <ReachRevenueSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Total Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '2,400,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '$4,200.00' ) ).toBeInTheDocument();
	} );

	it( 'InventoryPerformanceSection shows eCPM/fill and the viewability overlay', () => {
		render( <InventoryPerformanceSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( '$1.75' ) ).toBeInTheDocument();
		expect( screen.getByText( '87%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
	} );

	it( 'RevenueBreakdownSection renders the split pie legend and both tables', () => {
		render( <RevenueBreakdownSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'direct' ) ).toBeInTheDocument();
		expect( screen.getByText( 'programmatic' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Homepage Leaderboard' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Acme Co' ) ).toBeInTheDocument();
	} );

	it( 'AudienceReachSection renders the device pie and countries table', () => {
		render( <AudienceReachSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Smartphone' ) ).toBeInTheDocument();
		expect( screen.getByText( 'United States' ) ).toBeInTheDocument();
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
