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
import TopPerformersSection from './TopPerformersSection';
import ChannelDeviceSection from './ChannelDeviceSection';

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
	by_channel: {
		type: 'breakdown',
		computable: true,
		rows: [
			// Shares are impressions-weighted (fractions of the 2.4M total).
			{ channel: 'Programmatic', revenue: 2310, impressions: 1440000, share: 0.6 },
			{ channel: 'Direct-sold', revenue: 1260, impressions: 528000, share: 0.22 },
			{ channel: 'House', revenue: 504, impressions: 360000, share: 0.15 },
			{ channel: 'Other', revenue: 126, impressions: 72000, share: 0.03 },
		],
	},
	by_device: {
		type: 'table',
		computable: true,
		rows: [
			{ device: 'Smartphone', impressions: 1392000, revenue: 2184, ecpm: 1.57 },
			{ device: 'Desktop', impressions: 720000, revenue: 1596, ecpm: 2.22 },
			{ device: 'Tablet', impressions: 216000, revenue: 336, ecpm: 1.56 },
			{ device: 'Connected TV', impressions: 0, revenue: 0, ecpm: null },
		],
	},
	top_ad_units: {
		type: 'table',
		computable: true,
		rows: [ { ad_unit: 'Homepage Leaderboard', impressions: 500000, clicks: 2000, ctr: 0.004, revenue: 900, ecpm: 1.8 } ],
	},
	top_advertisers: {
		type: 'table',
		computable: true,
		rows: [ { advertiser: 'Acme Co', impressions: 300000, clicks: 900, ctr: 0.003, revenue: 600 } ],
	},
	top_campaigns: {
		type: 'table',
		computable: true,
		rows: [
			{
				campaign: 'Hometown Hardware — Spring Flight',
				advertiser: 'Hometown Hardware',
				impressions: 120000,
				clicks: 480,
				ctr: 0.004,
				revenue: 350,
			},
			{
				campaign: 'Riverside Credit Union — Auto Loans',
				advertiser: 'Riverside Credit Union',
				impressions: 90000,
				clicks: 0,
				ctr: null,
				revenue: 280,
			},
		],
	},
};

describe( 'Advertising sections', () => {
	it( 'ReachRevenueSection shows impressions and revenue, without the retired revenue-mix card', () => {
		render( <ReachRevenueSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '2.4M' ) ).toBeInTheDocument(); // 7-digit impressions abbreviate (NPPD-1684)
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
		// Definitional descriptions fill the third slot (no short caption).
		expect( screen.getByText( 'Total ad impressions served on your site' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Total ad revenue earned, before fees' ) ).toBeInTheDocument();
		// The Revenue Mix card was retired in favor of Impressions by type (NPPD-1881).
		expect( screen.queryByText( 'Revenue Mix' ) ).not.toBeInTheDocument();
	} );

	it( 'ReachRevenueSection shows the inventory-quality cards (eCPM/fill and the viewability overlay)', () => {
		// Folded in from the former Inventory performance section.
		render( <ReachRevenueSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Average eCPM' ) ).toBeInTheDocument();
		expect( screen.getByText( '$1.75' ) ).toBeInTheDocument();
		expect( screen.getByText( '87%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
	} );

	it( 'TopPerformersSection renders the three tables (no device pie)', () => {
		render( <TopPerformersSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Homepage Leaderboard' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Acme Co' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Hometown Hardware — Spring Flight' ) ).toBeInTheDocument();
		// The device breakdown lives in ChannelDeviceSection, not here.
		expect( screen.queryByText( 'Performance by device' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Smartphone' ) ).not.toBeInTheDocument();
		// The ad-units table no longer carries an eCPM column (payload field is
		// still present but unrendered).
		expect( screen.queryByText( 'eCPM' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( '$1.80' ) ).not.toBeInTheDocument();
	} );

	it( 'TopPerformersSection shows clicks and CTR on ad units and advertisers', () => {
		render( <TopPerformersSection current={ metrics } previous={ null } /> );
		expect( screen.getAllByText( 'Clicks' ) ).toHaveLength( 3 ); // Ad units + advertisers + campaigns.
		expect( screen.getAllByText( 'CTR' ) ).toHaveLength( 3 );
		expect( screen.getByText( '2,000' ) ).toBeInTheDocument(); // Ad unit clicks.
		expect( screen.getByText( '900' ) ).toBeInTheDocument(); // Advertiser clicks.
		expect( screen.getByText( '0.3%' ) ).toBeInTheDocument(); // Advertiser CTR.
	} );

	it( 'Top campaigns lists direct-sold orders and em-dashes a null CTR', () => {
		render( <TopPerformersSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Top campaigns' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Riverside Credit Union' ) ).toBeInTheDocument();
		expect( screen.getByText( '$350.00' ) ).toBeInTheDocument();
		// Null CTR (no impressions basis) renders an em-dash, never 0%.
		expect( screen.getByText( '—' ) ).toBeInTheDocument();
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

	it( 'ChannelDeviceSection renders impressions-weighted ad-type slices with plain-number legend values', () => {
		render( <ChannelDeviceSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Impressions by type' ) ).toBeInTheDocument();
		// One legend entry per ad-type bucket, impressions-weighted with plain
		// numbers — no currency, since house inventory is unpaid by definition.
		expect( screen.getByText( 'Programmatic' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Direct-sold' ) ).toBeInTheDocument();
		expect( screen.getByText( 'House' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Other' ) ).toBeInTheDocument();
		expect( screen.getByText( /1,440,000/ ) ).toBeInTheDocument();
		expect( screen.getByText( /60%/ ) ).toBeInTheDocument();
		// The unpaid house share stays visible — the point of impressions weighting.
		expect( screen.getByText( /360,000/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /\$2,310/ ) ).not.toBeInTheDocument();
	} );

	it( 'ChannelDeviceSection renders the device table with eCPM (em-dash when no impressions)', () => {
		render( <ChannelDeviceSection current={ metrics } previous={ null } /> );
		expect( screen.getByText( 'Performance by device' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Smartphone' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Desktop' ) ).toBeInTheDocument();
		expect( screen.getByText( '1,392,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '$2.22' ) ).toBeInTheDocument();
		// Connected TV served nothing: its eCPM is null → em-dash, never $0.00.
		expect( screen.getByText( '—' ) ).toBeInTheDocument();
	} );

	it( 'ChannelDeviceSection shows the pie empty state when no ad type has activity', () => {
		const empty: InsightsWindow = {
			...metrics,
			by_channel: { type: 'breakdown', computable: false, rows: [] },
		};
		render( <ChannelDeviceSection current={ empty } previous={ null } /> );
		expect( screen.getByText( 'No ad type activity in this timeframe.' ) ).toBeInTheDocument();
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
