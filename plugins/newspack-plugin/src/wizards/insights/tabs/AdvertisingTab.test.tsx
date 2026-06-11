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
		expect( screen.queryByText( 'Reach & revenue' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the progressive GAM messages while a ready window is still being cached', () => {
		mockData( baseWindow( { is_loading: true } ) );
		render( <AdvertisingTab range={ range } previousRange={ null } /> );
		// The async is_loading wait carries the progressive copy (NPPD-1684); the
		// first message renders immediately.
		expect( screen.getByText( 'Loading ad performance…' ) ).toBeInTheDocument();
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

		expect( screen.getByText( 'Reach & revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( '2,400,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '$4,200.00' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Sidebar' ) ).toBeInTheDocument();
		// Viewability degrades to the data-unavailable note.
		expect( screen.getByText( 'Not available for this site.' ) ).toBeInTheDocument();
		// Data-as-of indicator present.
		expect( screen.getByText( /Data as of/ ) ).toBeInTheDocument();
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
} );
