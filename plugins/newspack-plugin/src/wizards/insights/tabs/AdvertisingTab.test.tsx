/**
 * Tab-level tests for AdvertisingTab (Tab 8, NPPD-1618): the visibility /
 * readiness / loading / ready render states.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AdvertisingTab from './AdvertisingTab';
import useAdvertisingData from '../hooks/useAdvertisingData';
import type { DateRange } from '../state/useDateRange';
import type { AdvertisingWindow } from '../api/advertising';

jest.mock( '../hooks/useAdvertisingData' );

const mockHook = useAdvertisingData as jest.Mock;
const range = { start: '2026-05-01', end: '2026-05-31', preset: 'last-30' } as unknown as DateRange;

const baseWindow = ( overrides: Partial< AdvertisingWindow > = {} ): AdvertisingWindow => ( {
	is_tab_visible: true,
	is_report_ready: true,
	readiness_issues: [],
	data_as_of: '2026-05-30',
	has_estimated_data: false,
	estimated_window_start_date: null,
	metrics: {},
	...overrides,
} );

const mockData = ( current: AdvertisingWindow ) =>
	mockHook.mockReturnValue( {
		status: 'success',
		data: { current, previous: null },
		error: null,
		refetch: () => {},
		computedAt: null,
		source: null,
		cooldownUntil: null,
	} );

describe( 'AdvertisingTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders nothing when the tab is not visible (GAM inactive)', () => {
		mockData( baseWindow( { is_tab_visible: false } ) );
		const { container } = render( <AdvertisingTab range={ range } previousRange={ null } /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the finish-connecting diagnostic with every readiness issue', () => {
		mockData(
			baseWindow( {
				is_report_ready: false,
				readiness_issues: [
					{
						code: 'oauth_scope_missing',
						message: 'Reconnect Google to grant the Ad Manager scope.',
						remediation_url: 'http://example.test/settings',
					},
					{
						code: 'network_code_missing',
						message: 'No Google Ad Manager network is configured.',
						remediation_url: 'http://example.test/advertising',
					},
				],
			} )
		);
		render( <AdvertisingTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( /Finish connecting Google Ad Manager/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Reconnect Google to grant the Ad Manager scope.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'No Google Ad Manager network is configured.' ) ).toBeInTheDocument();
		// No section content while not ready.
		expect( screen.queryByText( 'Reach & Revenue' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the progressive GAM messages while a ready window is still being cached', () => {
		mockData( baseWindow( { is_loading: true } ) );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		// The async is_loading wait carries the progressive copy (NPPD-1684); the
		// first message renders immediately.
		expect( screen.getByText( 'Loading ad performance…' ) ).toBeInTheDocument();
	} );

	it( 'does NOT render the empty state while loading, even when has_window_activity is false (NPPD-1697 regression)', () => {
		// The make-or-break: a still-running report must short-circuit to the
		// progressive loading messages BEFORE the no_opportunity branch can evaluate,
		// even though the payload would otherwise trigger it.
		mockData( baseWindow( { is_loading: true, has_window_activity: false, metrics: {} } ) );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Loading ad performance…' ) ).toBeInTheDocument();
		expect( screen.queryByText( /No ad impressions in this timeframe/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Reach & Revenue' ) ).not.toBeInTheDocument();
	} );

	it( 'collapses the Reach & Revenue section to no_opportunity on a resolved zero window', () => {
		mockData(
			baseWindow( {
				has_window_activity: false,
				metrics: {
					total_impressions: { value: 0, computable: true, type: 'count' },
					total_revenue: { value: 0, computable: true, type: 'currency' },
					direct_vs_programmatic: { type: 'breakdown', computable: false, rows: [] },
				},
			} )
		);
		const { container } = render( <AdvertisingTab range={ range } previousRange={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'No ad impressions in this timeframe' );
		// The headline scorecards are gone; the other sections still render.
		expect( screen.queryByText( 'Impressions' ) ).not.toBeInTheDocument();
	} );

	it( 'renders all sections with values when ready', () => {
		mockData(
			baseWindow( {
				metrics: {
					total_impressions: { value: 2400000, computable: true, type: 'count' },
					total_revenue: { value: 4200, computable: true, type: 'currency' },
					viewability_rate: { value: null, computable: false, overlay: { type: 'data_unavailable' } },
					top_ad_units: { type: 'table', computable: true, rows: [ { ad_unit: 'Sidebar', impressions: 1000, revenue: 12.5, ecpm: 12.5 } ] },
				},
			} )
		);
		render( <AdvertisingTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Reach & Revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( '2.4M' ) ).toBeInTheDocument(); // 7-digit impressions abbreviate (NPPD-1684)
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Sidebar' ) ).toBeInTheDocument();
		// Viewability degrades to the data-unavailable note.
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
		// Data-as-of indicator present. `@wordpress/components` Notice
		// duplicates the text into a hidden a11y-speak region, so allow
		// multiple matches.
		expect( screen.getAllByText( /Data as of/ ).length ).toBeGreaterThan( 0 );
	} );

	it( 'shows the initial loading state before any data arrives', () => {
		mockHook.mockReturnValue( {
			status: 'loading',
			data: null,
			error: null,
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		// Now routed through the shared TabStateView loading frame (NPPD-1684).
		// Advertising keeps the spinner-only frame; its progressive messages live
		// on the async `is_loading` state instead.
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
	} );

	it( 'shows the error state with detail when the fetch fails', () => {
		mockHook.mockReturnValue( {
			status: 'error',
			data: null,
			error: 'HTTP 500',
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Could not load advertising data.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'HTTP 500' ) ).toBeInTheDocument();
	} );

	it( 'renders comparison deltas when a comparison range is active', () => {
		const previousRange = { start: '2026-04-01', end: '2026-04-30', preset: 'last-30' } as unknown as DateRange;
		mockHook.mockReturnValue( {
			status: 'success',
			error: null,
			refetch: () => {},
			data: {
				current: baseWindow( { metrics: { total_impressions: { value: 120, computable: true, type: 'count' } } } ),
				previous: baseWindow( { metrics: { total_impressions: { value: 100, computable: true, type: 'count' } } } ),
			},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <AdvertisingTab range={ range } previousRange={ previousRange } /> );
		// +20% vs the prior window → up arrow + magnitude.
		expect( screen.getByText( '20%' ) ).toBeInTheDocument();
		expect( screen.getByText( '↑' ) ).toBeInTheDocument();
	} );

	it( 'renders the per-site breakdown for network members (NPPD-1671)', () => {
		mockData(
			baseWindow( {
				is_network_member: true,
				metrics: {
					top_sites: {
						type: 'table',
						computable: true,
						rows: [ { site: 'almanacnews.com', impressions: 980000, revenue: 1720, ecpm: 1.76 } ],
					},
				},
			} )
		);
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Performance by Site' ) ).toBeInTheDocument();
		expect( screen.getByText( 'almanacnews.com' ) ).toBeInTheDocument();
	} );

	it( 'hides the per-site breakdown for non-network publishers', () => {
		mockData( baseWindow( { is_network_member: false } ) );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		expect( screen.queryByText( 'Performance by Site' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the impressions-only Broadstreet variant, hiding every revenue/GAM section (NPPD-2045)', () => {
		mockData(
			baseWindow( {
				active_provider: 'broadstreet',
				metrics: {
					total_impressions: { value: 2400000, computable: true, type: 'count' },
					avg_impressions_per_session: { value: 3, computable: true, type: 'count' },
					overall_ctr: { value: 0.0018, computable: true, type: 'rate', numerator: 4320, denominator: 2400000 },
					mobile_share: { value: 0.63, computable: true, type: 'rate', numerator: 1512000, denominator: 2400000 },
					top_advertisers: {
						type: 'table',
						computable: true,
						rows: [ { advertiser: 'Hometown Hardware', impressions: 120000, clicks: 480, ctr: 0.004 } ],
					},
					top_zones: {
						type: 'table',
						computable: true,
						rows: [ { zone: 'Homepage Leaderboard', impressions: 90000, clicks: 360, ctr: 0.004 } ],
					},
					top_campaigns: {
						type: 'table',
						computable: true,
						rows: [ { campaign: 'Riverside Credit Union — Summer', impressions: 70000, clicks: 280, ctr: 0.004 } ],
					},
				},
			} )
		);
		const { container } = render( <AdvertisingTab range={ range } previousRange={ null } /> );

		// Impressions-side sections render.
		expect( screen.getByText( 'Reach' ) ).toBeInTheDocument();
		expect( screen.getByText( '2.4M' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Impressions per Session' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Top Advertisers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Hometown Hardware' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Top Zones' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Homepage Leaderboard' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Top Campaigns' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Riverside Credit Union — Summer' ) ).toBeInTheDocument();

		// GAM-only sections + the revenue card are absent (no revenue in Broadstreet).
		expect( screen.queryByText( 'Reach & Revenue' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Revenue' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'RPM' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Average eCPM' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Impressions by Type' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Performance by Device' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Top Ad Units' ) ).not.toBeInTheDocument();
		// The GAM data-lag note is hidden for Broadstreet. Scope to the render
		// container — the global a11y-speak region (document.body) can retain stale
		// announcements from earlier tests in this suite.
		expect( container ).not.toHaveTextContent( /Data as of/ );
	} );
} );
