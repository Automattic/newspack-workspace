/**
 * Tests for TrafficSourcesSection (NPPD): the Top Campaigns table hides when
 * every row is "(not set)" across source/medium/campaign; the section as a whole
 * hides only when the breakdown pie is also empty.
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

const breakdownWithData = {
	computable: true,
	type: 'breakdown',
	rows: [
		{ channel: 'Organic Search', readers: 5120 },
		{ channel: 'Direct', readers: 3380 },
	],
};

const breakdownEmpty = { computable: true, type: 'breakdown', rows: [] };

const realCampaigns = {
	computable: true,
	type: 'table',
	rows: [
		{ source: 'newsletter', medium: 'email', campaign: 'weekly-digest', readers: 820, sessions: 1140 },
		{ source: '(not set)', medium: '(not set)', campaign: '(not set)', readers: 300, sessions: 360 },
	],
};

const allNotSetCampaigns = {
	computable: true,
	type: 'table',
	rows: [
		{ source: '(not set)', medium: '(not set)', campaign: '(not set)', readers: 4200, sessions: 5100 },
		{ source: '', medium: '', campaign: '', readers: 1800, sessions: 2000 },
	],
};

const windowOf = ( top_campaigns: unknown, traffic_sources_breakdown: unknown ): InsightsWindow =>
	( { top_campaigns, traffic_sources_breakdown } ) as unknown as InsightsWindow;

describe( 'TrafficSourcesSection', () => {
	it( 'renders the Top Campaigns table when at least one row has real data', () => {
		render( <TrafficSourcesSection current={ windowOf( realCampaigns, breakdownWithData ) } previous={ null } /> );
		expect( screen.getByText( 'Top Campaigns' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Traffic Sources Breakdown' ) ).toBeInTheDocument();
	} );

	it( 'hides the Top Campaigns table when every row is (not set), keeping the section for the breakdown', () => {
		render( <TrafficSourcesSection current={ windowOf( allNotSetCampaigns, breakdownWithData ) } previous={ null } /> );
		expect( screen.queryByText( 'Top Campaigns' ) ).not.toBeInTheDocument();
		// Section stays visible because the breakdown pie still has data.
		expect( screen.getByText( 'Traffic sources' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Traffic Sources Breakdown' ) ).toBeInTheDocument();
	} );

	it( 'hides the whole section when campaigns are all (not set) and the breakdown is empty', () => {
		const { container } = render( <TrafficSourcesSection current={ windowOf( allNotSetCampaigns, breakdownEmpty ) } previous={ null } /> );
		expect( container ).toBeEmptyDOMElement();
		expect( screen.queryByText( 'Traffic sources' ) ).not.toBeInTheDocument();
	} );
} );
