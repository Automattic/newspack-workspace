/**
 * Tab-level tests for AppTab (Tab 10, NPPD-1882): the connect → select → render
 * state machine, driven by the /app/config response, plus the ready state driven
 * by the shared useAppMetricsData cache hook.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AppTab from './AppTab';
import { fetchAppConfig, saveAppProperty, type AppConfig, type AppMetrics } from '../api/app';
import useAppMetricsData from '../hooks/useAppMetricsData';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../api/app' );
jest.mock( '../hooks/useAppMetricsData' );

const mockFetch = fetchAppConfig as jest.Mock;
const mockSave = saveAppProperty as jest.Mock;
const mockUseData = useAppMetricsData as jest.Mock;

const range = { start: '2026-05-01', end: '2026-05-31', preset: 'last-30' } as unknown as DateRange;

/** A minimal current-window metrics map so the ready state renders every section. */
const metricsMap = (): AppMetrics => ( {
	active_users: { value: 892, computable: true, type: 'count' },
	new_users: { value: 150, computable: true, type: 'count' },
	sessions: { value: 12790, computable: true, type: 'count' },
	platform: { rows: [ { platform: 'iOS', active_users: 590 } ], computable: true, type: 'breakdown' },
	app_version: { rows: [ { app_version: '1.2', active_users: 840 } ], computable: true, type: 'breakdown' },
	avg_engagement_time: { value: 1130, computable: true, type: 'duration' },
	engagement_rate: { value: 0.83, computable: true, type: 'rate' },
	engaged_sessions: { value: 10600, computable: true, type: 'count' },
	screens_per_session: { value: 6.2, computable: true, type: 'decimal' },
	screen_views: { value: 70473, computable: true, type: 'count' },
	retention: {
		rows: [
			{ week: 0, retention: 1.0 },
			{ week: 1, retention: 0.55 },
			{ week: 2, retention: 0.32 },
		],
		computable: true,
		type: 'timeseries',
	},
	notification_open_rate: { value: 0.228, computable: true, type: 'rate' },
	notifications_received: { value: 614, computable: true, type: 'count' },
	notification_opt_changes: { value: 123, computable: true, type: 'count' },
	downloads_started: { value: 35495, computable: true, type: 'count' },
	downloads_completed: { value: 33805, computable: true, type: 'count' },
	download_completion_rate: { value: 0.952, computable: true, type: 'rate' },
	top_sections: { rows: [ { section: 'News', views: 7078 } ], computable: true, type: 'breakdown' },
	top_authors: { rows: [ { author: 'Alex Rivera', views: 3120 } ], computable: true, type: 'breakdown' },
	subscriber_mix: { rows: [ { status: 'ExistingSubscriber', users: 483 } ], computable: true, type: 'breakdown' },
	content_cost: { rows: [ { cost: 'Free', views: 52140 } ], computable: true, type: 'breakdown' },
} );

/** The useAppMetricsData return for a resolved window (overridable per test). */
const readyState = ( overrides: Record< string, unknown > = {} ) => ( {
	status: 'success',
	data: { current: metricsMap(), previous: null },
	error: null,
	computedAt: '2026-05-31T00:00:00Z',
	refetch: jest.fn(),
	...overrides,
} );

const baseConfig = ( overrides: Partial< AppConfig > = {} ): AppConfig => ( {
	is_app_publisher: true,
	connected: true,
	selected_property: null,
	selected_is_visible: false,
	properties: [],
	properties_error: null,
	settings_url: 'https://example.test/wp-admin/admin.php?page=newspack-settings',
	...overrides,
} );

const renderTab = () => render( <AppTab range={ range } previousRange={ null } /> );

describe( 'AppTab', () => {
	beforeEach( () => {
		mockUseData.mockReturnValue( readyState() );
	} );

	afterEach( () => {
		mockFetch.mockReset();
		mockSave.mockReset();
		mockUseData.mockReset();
	} );

	it( 'shows the Connections CTA (not Site Kit) when not connected', async () => {
		mockFetch.mockResolvedValue( baseConfig( { connected: false } ) );
		renderTab();

		expect( await screen.findByText( /Connect Google in Newspack/i ) ).toBeInTheDocument();
		const link = screen.getByRole( 'link', { name: /Go to Connections/i } );
		expect( link ).toHaveAttribute( 'href', 'https://example.test/wp-admin/admin.php?page=newspack-settings' );
		expect( screen.queryByText( /Site Kit/i ) ).not.toBeInTheDocument();
	} );

	it( 'shows the property picker when connected with no property chosen', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		renderTab();

		expect( await screen.findByText( /Choose the Google Analytics property/i ) ).toBeInTheDocument();
		// Option label combines account → property (id).
		expect( screen.getByText( /YubaNet → yubanetapp \(533212292\)/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /Save property/i } ) ).toBeInTheDocument();
	} );

	it( 'surfaces an enumeration error in the picker', async () => {
		mockFetch.mockResolvedValue( baseConfig( { properties_error: 'permission denied' } ) );
		renderTab();

		expect( await screen.findByText( /permission denied/i ) ).toBeInTheDocument();
	} );

	it( 'persists the chosen property on Save', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		mockSave.mockResolvedValue( baseConfig( { selected_property: '533212292', selected_is_visible: true } ) );
		renderTab();

		fireEvent.change( await screen.findByRole( 'combobox' ), { target: { value: '533212292' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /Save property/i } ) );

		await waitFor( () => expect( mockSave ).toHaveBeenCalledWith( '533212292' ) );
		// After saving, the ready state reads the metrics cache and renders the Reach section.
		expect( await screen.findByText( 'Reach' ) ).toBeInTheDocument();
	} );

	it( 'renders the Reach section when a visible property is selected', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				selected_property: '533212292',
				selected_is_visible: true,
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		renderTab();

		expect( await screen.findByText( 'Reach' ) ).toBeInTheDocument();
		// The property-context line names the property the tab is reading.
		expect( screen.getByText( /App property: YubaNet → yubanetapp \(533212292\)/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Active users' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Engagement' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Avg. engagement time' ) ).toBeInTheDocument();
		// Assert later sections by a unique card label (the section titles like
		// "Notifications" collide with card labels such as "Notifications received"
		// under the text matcher).
		expect( screen.getByText( 'Weekly retention' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Notification open rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Download completion rate' ) ).toBeInTheDocument();
		// Tier-2a content + composition sections (unique card titles).
		expect( screen.getByText( 'Top sections' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Top authors' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber mix' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Free vs. paid content' ) ).toBeInTheDocument();
		// Raw KG status values are humanized for display ("ExistingSubscriber" → "Existing subscriber").
		expect( screen.getByText( 'Existing subscriber' ) ).toBeInTheDocument();
		// The tab reads through the shared cache hook for the current window (no comparison).
		expect( mockUseData ).toHaveBeenCalledWith( range, null );
	} );

	it( 'threads the comparison window into the data hook', async () => {
		const previousRange = { start: '2026-04-01', end: '2026-04-30', preset: 'last-30' } as unknown as DateRange;
		mockFetch.mockResolvedValue( baseConfig( { selected_property: '533212292', selected_is_visible: true } ) );
		mockUseData.mockReturnValue(
			readyState( {
				data: {
					current: metricsMap(),
					previous: { ...metricsMap(), active_users: { value: 800, computable: true, type: 'count' } },
				},
			} )
		);
		render( <AppTab range={ range } previousRange={ previousRange } /> );

		expect( await screen.findByText( 'Reach' ) ).toBeInTheDocument();
		// The comparison window flows to the cache hook, which the server uses to
		// return a `previous` window that Scorecards turn into deltas.
		expect( mockUseData ).toHaveBeenCalledWith( range, previousRange );
	} );

	it( 'shows the not-configured note for Tier-2 cards whose KG dimension is unregistered', async () => {
		mockFetch.mockResolvedValue( baseConfig( { selected_property: '533212292', selected_is_visible: true } ) );
		const current = metricsMap();
		// Runtime tiering: the backend emits not_configured for KG breakdowns that
		// aren't registered on the property. The card renders the unlock note
		// instead of data, and the rest of the tab still renders.
		current.top_sections = { rows: [], computable: false, not_configured: true, type: 'breakdown' };
		current.subscriber_mix = { rows: [], computable: false, not_configured: true, type: 'breakdown' };
		mockUseData.mockReturnValue( readyState( { data: { current, previous: null } } ) );
		renderTab();

		// The section titles still render (the cards are present, just gated).
		expect( await screen.findByText( 'Top sections' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber mix' ) ).toBeInTheDocument();
		expect( screen.getAllByText( /Not configured for this site/i ).length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'falls back to the picker when the saved property is no longer visible', async () => {
		mockFetch.mockResolvedValue(
			baseConfig( {
				selected_property: '999',
				selected_is_visible: false,
				properties: [ { account_id: '1', account_name: 'YubaNet', property_id: '533212292', property_name: 'yubanetapp' } ],
			} )
		);
		renderTab();

		expect( await screen.findByText( /Choose the Google Analytics property/i ) ).toBeInTheDocument();
	} );

	it( 'shows an error state when the config fetch fails', async () => {
		mockFetch.mockRejectedValue( new Error( 'boom' ) );
		renderTab();

		expect( await screen.findByText( /boom/i ) ).toBeInTheDocument();
	} );
} );
