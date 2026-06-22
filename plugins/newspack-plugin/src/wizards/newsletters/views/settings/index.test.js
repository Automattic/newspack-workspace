import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { HashRouter } from 'react-router-dom';

import NewslettersSettings, { Settings, SubscriptionLists } from './index';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

// NewslettersSettings mounts useUnsavedChangesDialog → useConfirmDialog →
// ConfirmDialog, which calls `useHistory()`. In production it runs inside
// Wizard's HashRouter; in tests we provide the same Router context.
const renderWithRouter = ui => render( <HashRouter>{ ui }</HashRouter> );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const NN_EVENTS = {
	BRIDGE_MOUNTED: 'newspack-newsletters:bridge-mounted',
	OPEN_MODAL: 'newspack-newsletters:open-local-list-modal',
	OPEN_CONFIRM_DELETE: 'newspack-newsletters:open-local-list-confirm-delete',
	LOCAL_LIST_SAVED: 'newspack-newsletters:local-list-saved',
	LOCAL_LIST_DELETED: 'newspack-newsletters:local-list-deleted',
};

const SUBSCRIPTION_LISTS_FIXTURE = [
	{ id: 'tag-1', name: 'Local A', type: 'local', active: false, db_id: 1, edit_link: 'https://example.test/edit-local-a' },
	{ id: 'group-1', name: 'Remote group', type: 'group', active: true, db_id: 2, edit_link: 'https://example.test/edit-remote' },
];

const SETTINGS_FIXTURE = {
	configured: true,
	labels: { local_list_explanation: 'Mailchimp Group' },
	settings: {
		newspack_newsletters_service_provider: {
			key: 'newspack_newsletters_service_provider',
			description: 'Service Provider',
			value: '',
			type: 'select',
			options: [
				{ value: '', name: '-- Select --' },
				{ value: 'mailchimp', name: 'Mailchimp' },
				{ value: 'active_campaign', name: 'Active Campaign' },
				{ value: 'constant_contact', name: 'Constant Contact' },
				{ value: 'manual', name: 'Manual / Other' },
			],
		},
		newspack_newsletters_mailchimp_api_key: {
			key: 'newspack_newsletters_mailchimp_api_key',
			description: 'Mailchimp API Key',
			value: '',
			type: 'text',
			provider: 'mailchimp',
		},
	},
};

beforeAll( () => {
	global.newspack_newsletters_wizard = {
		new_subscription_lists_url: 'https://example.test/wp-admin/post-new.php?post_type=newspack_nl_list',
	};
} );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( SUBSCRIPTION_LISTS_FIXTURE );
	// Mark the bridge ready so the fallback timer doesn't navigate the test window.
	window.newspackNewslettersBridgeReady = true;
} );

afterEach( () => {
	delete window.newspackNewslettersBridgeReady;
	delete window.newspackNewslettersEvents;
} );

describe( 'SubscriptionLists — wizard-bridge wiring', () => {
	it( 'dispatches OPEN_MODAL with mode=add when Add New is clicked', async () => {
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		expect( listener ).toHaveBeenCalled();
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( { mode: 'add' } );
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
	} );

	it( 'dispatches OPEN_MODAL with mode=edit + kind=local when Edit is clicked on a local row', async () => {
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Local A' } ) );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual(
			expect.objectContaining( { mode: 'edit', kind: 'local', list: expect.objectContaining( { db_id: 1 } ) } )
		);
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
	} );

	it( 'dispatches OPEN_MODAL with mode=edit + kind=esp when Edit is clicked on a remote row', async () => {
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Remote group' ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Remote group' } ) );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual(
			expect.objectContaining( { mode: 'edit', kind: 'esp', list: expect.objectContaining( { db_id: 2 } ) } )
		);
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
	} );

	it( 'surfaces a friendlier error when the PATCH endpoint is missing (newsletters plugin too old)', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		// Simulate WP returning rest_no_route for the PATCH call.
		apiFetch.mockRejectedValueOnce( {
			code: 'rest_no_route',
			message: 'No route was found matching the URL and request method.',
		} );
		fireEvent.click( screen.getAllByRole( 'checkbox' )[ 0 ] );
		// Plugin should translate to a helpful upgrade prompt instead of
		// surfacing WordPress's generic message. (`a11y-speak` mirrors
		// the notice into a screen-reader region too, hence getAllByText.)
		await waitFor( () => expect( screen.getAllByText( /newer version of Newspack Newsletters/ ).length ).toBeGreaterThan( 0 ) );
		expect( screen.queryByText( /No route was found/ ) ).not.toBeInTheDocument();
	} );

	it( 'commits the active toggle immediately via PATCH /lists/{db_id}', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		apiFetch.mockResolvedValueOnce( { id: 'tag-1', db_id: 1, active: true } );
		fireEvent.click( screen.getAllByRole( 'checkbox' )[ 0 ] );
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenLastCalledWith( {
				path: '/newspack-newsletters/v1/lists/1',
				method: 'PATCH',
				data: { active: true },
			} )
		);
	} );

	it( 'does not render the bulk Save Subscription Lists button', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: /Save Subscription Lists/ } ) ).not.toBeInTheDocument();
	} );

	it( 'does not render inline title/description fields on remote rows', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Remote group' ) ).toBeInTheDocument() );
		expect( screen.queryByLabelText( /List title/ ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( /List description/ ) ).not.toBeInTheDocument();
	} );

	it( 'dispatches OPEN_CONFIRM_DELETE when Delete is clicked on a local row', async () => {
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_CONFIRM_DELETE, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Delete Local A' } ) );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( expect.objectContaining( { list: expect.objectContaining( { db_id: 1 } ) } ) );
		document.removeEventListener( NN_EVENTS.OPEN_CONFIRM_DELETE, listener );
	} );

	it( 'reloads lists when LOCAL_LIST_SAVED fires', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		document.dispatchEvent( new CustomEvent( NN_EVENTS.LOCAL_LIST_SAVED, { detail: { listId: 1, mode: 'edit' } } ) );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	} );

	it( 'reloads lists when LOCAL_LIST_DELETED fires', async () => {
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		document.dispatchEvent( new CustomEvent( NN_EVENTS.LOCAL_LIST_DELETED, { detail: { listId: 1 } } ) );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	} );

	it( 'does not redirect when the bridge mounted before the wizard listener registered', async () => {
		jest.useFakeTimers();
		const originalHref = window.location.href;
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		jest.advanceTimersByTime( 600 );
		expect( window.location.href ).toBe( originalHref );
		jest.useRealTimers();
	} );

	it( 'replays a queued dispatch with the live event name when the bridge mounts with renamed events', async () => {
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const renamedListener = jest.fn();
		const fallbackListener = jest.fn();
		document.addEventListener( 'custom:open-modal', renamedListener );
		document.addEventListener( NN_EVENTS.OPEN_MODAL, fallbackListener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		// Bridge mounts and exposes a renamed OPEN_MODAL — replay must
		// resolve the event name at replay time, not at click time.
		window.newspackNewslettersBridgeReady = true;
		window.newspackNewslettersEvents = { ...NN_EVENTS, OPEN_MODAL: 'custom:open-modal' };
		document.dispatchEvent( new CustomEvent( NN_EVENTS.BRIDGE_MOUNTED ) );
		expect( renamedListener ).toHaveBeenCalledTimes( 1 );
		expect( fallbackListener ).not.toHaveBeenCalled();
		document.removeEventListener( 'custom:open-modal', renamedListener );
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, fallbackListener );
		jest.useRealTimers();
	} );

	it( 'does not replay a stale queued action after a newer click dispatches immediately', async () => {
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		// First click: bridge not ready → queues action A, arms 500ms timer.
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		expect( listener ).not.toHaveBeenCalled();
		// Bridge becomes ready but BRIDGE_MOUNTED never reaches our handler.
		window.newspackNewslettersBridgeReady = true;
		// Second click: bridge ready → dispatches immediately. The prior
		// queued action and armed timer must be cleared, or the timer
		// would fire a stale duplicate.
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		jest.advanceTimersByTime( 600 );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
		jest.useRealTimers();
	} );

	it( 'reattaches reload listeners to live event names when the bridge mounts via a renamed event missed by our handler', async () => {
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const renamedSavedListener = jest.fn();
		const fallbackSavedListener = jest.fn();
		const { default: apiFetchMock } = await import( '@wordpress/api-fetch' );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( apiFetchMock ).toHaveBeenCalled() );
		// Bridge appears with renamed events; our BRIDGE_MOUNTED handler
		// won't fire because the new mounted-event name was unknown at
		// mount time. The fallback-timer recovery path should re-resolve
		// the reload listeners.
		window.newspackNewslettersBridgeReady = true;
		window.newspackNewslettersEvents = {
			...NN_EVENTS,
			LOCAL_LIST_SAVED: 'custom:local-list-saved',
		};
		// Click Add new local list to trigger the queue → fallback timer path.
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		// Spy on a future bridge save event under the renamed name.
		document.addEventListener( 'custom:local-list-saved', renamedSavedListener );
		document.addEventListener( NN_EVENTS.LOCAL_LIST_SAVED, fallbackSavedListener );
		// Fallback timer fires — flushes queue + reattaches reload listeners.
		jest.advanceTimersByTime( 600 );
		// Bridge emits a save event under the renamed name; our reload
		// listener must be on this live name now.
		const reloadCallsBefore = apiFetchMock.mock.calls.length;
		document.dispatchEvent( new CustomEvent( 'custom:local-list-saved' ) );
		// fetchLists should have been called again.
		await waitFor( () => expect( apiFetchMock.mock.calls.length ).toBeGreaterThan( reloadCallsBefore ) );
		document.removeEventListener( 'custom:local-list-saved', renamedSavedListener );
		document.removeEventListener( NN_EVENTS.LOCAL_LIST_SAVED, fallbackSavedListener );
		jest.useRealTimers();
	} );

	it( 'flushes the queue on the fallback timeout when the bridge is ready but its mounted event was renamed', async () => {
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		// Bridge becomes ready but announces itself under a renamed event
		// that our listener (registered on the fallback name only at mount)
		// doesn't catch.
		window.newspackNewslettersBridgeReady = true;
		// Timer fires — should flush instead of navigating since
		// `isBridgeReady()` is now true.
		const originalHref = window.location.href;
		jest.advanceTimersByTime( 600 );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( window.location.href ).toBe( originalHref );
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
		jest.useRealTimers();
	} );

	it( 'queues the dispatch when the bridge is not ready and replays it on BRIDGE_MOUNTED', async () => {
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const listener = jest.fn();
		document.addEventListener( NN_EVENTS.OPEN_MODAL, listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		// Initial dispatch fires into the void (bridge listener doesn't exist) — listener captures nothing yet.
		expect( listener ).not.toHaveBeenCalled();
		// Bridge becomes ready and announces itself within the fallback window.
		window.newspackNewslettersBridgeReady = true;
		document.dispatchEvent( new CustomEvent( NN_EVENTS.BRIDGE_MOUNTED ) );
		// Replay fires the queued OPEN_MODAL event so the bridge can handle it.
		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( { mode: 'add' } );
		// Timer fires — should be cleared and not navigate.
		const originalHref = window.location.href;
		jest.advanceTimersByTime( 600 );
		expect( window.location.href ).toBe( originalHref );
		document.removeEventListener( NN_EVENTS.OPEN_MODAL, listener );
		jest.useRealTimers();
	} );

	it( 'clears the fallback timer on unmount so a redirect cannot fire after the component is gone', async () => {
		// Simulate the bridge NOT being ready so the fallback timer arms.
		delete window.newspackNewslettersBridgeReady;
		jest.useFakeTimers();
		const originalHref = window.location.href;
		const { unmount } = render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		unmount();
		jest.advanceTimersByTime( 600 );
		expect( window.location.href ).toBe( originalHref );
		jest.useRealTimers();
	} );

	it( 'reads event names from window.newspackNewslettersEvents when the bridge exposes them', async () => {
		window.newspackNewslettersEvents = {
			...NN_EVENTS,
			OPEN_MODAL: 'custom:open-modal',
		};
		const listener = jest.fn();
		document.addEventListener( 'custom:open-modal', listener );
		render( <SubscriptionLists lockedLists={ false } provider="mailchimp" /> );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /Add new local list/ } ) ).toBeEnabled() );
		fireEvent.click( screen.getByRole( 'button', { name: /Add new local list/ } ) );
		expect( listener ).toHaveBeenCalled();
		document.removeEventListener( 'custom:open-modal', listener );
	} );
} );

describe( 'Settings — ESP card grid', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( SETTINGS_FIXTURE );
	} );

	const renderSettings = ( overrides = {} ) => {
		const props = {
			isOnboarding: false,
			newslettersConfig: { newspack_newsletters_service_provider: '' },
			onUpdate: jest.fn(),
			onConfigured: jest.fn(),
			onLabels: jest.fn(),
			onLetterheadSetting: jest.fn(),
			setProvider: jest.fn(),
			setAuthUrl: jest.fn(),
			setLockedLists: jest.fn(),
			...overrides,
		};
		return { props, ...render( <Settings { ...props } /> ) };
	};

	it( 'renders all four ESP cards in the configured order', async () => {
		renderSettings();
		await waitFor( () => expect( screen.getByText( 'Mailchimp' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Active Campaign' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Constant Contact' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Manual / Other' ) ).toBeInTheDocument();
	} );

	it( 'fires onConfigured(true) once the wizard endpoint reports configured=true', async () => {
		const { props } = renderSettings();
		await waitFor( () => expect( props.onConfigured ).toHaveBeenCalledWith( true ) );
	} );

	it( 'fires onLabels with the label payload from the response', async () => {
		const { props } = renderSettings();
		await waitFor( () =>
			expect( props.onLabels ).toHaveBeenCalledWith( expect.objectContaining( { local_list_explanation: 'Mailchimp Group' } ) )
		);
	} );

	it( 'selecting a provider card calls onUpdate with the chosen provider', async () => {
		const { props } = renderSettings();
		await waitFor( () => expect( screen.getByText( 'Mailchimp' ) ).toBeInTheDocument() );
		// CardSettingsGroup wraps the icon+title in a clickable header.
		fireEvent.click( screen.getByText( 'Mailchimp' ) );
		await waitFor( () =>
			expect( props.onUpdate ).toHaveBeenCalledWith( expect.objectContaining( { newspack_newsletters_service_provider: 'mailchimp' } ) )
		);
	} );
} );

describe( 'NewslettersSettings — dirty tracking, save flow, snackbar', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( SETTINGS_FIXTURE );
		// Reset wizard store between tests so prior notices/header don't leak.
		dispatch( WIZARD_STORE_NAMESPACE ).resetNotices();
		dispatch( WIZARD_STORE_NAMESPACE ).resetHeaderData();
	} );

	const getSaveAction = () => select( WIZARD_STORE_NAMESPACE ).getHeaderData()?.actions?.[ 0 ];

	it( 'registers a Save header action that is initially disabled (no dirty state)', async () => {
		renderWithRouter( <NewslettersSettings /> );
		await waitFor( () => expect( getSaveAction() ).toEqual( expect.objectContaining( { label: 'Save', disabled: true } ) ) );
	} );

	it( 'fires a success snackbar on a successful save', async () => {
		renderWithRouter( <NewslettersSettings /> );
		await waitFor( () => expect( getSaveAction() ).toBeDefined() );
		// Save endpoint echoes the response on POST.
		apiFetch.mockResolvedValueOnce( SETTINGS_FIXTURE );
		await act( async () => {
			await getSaveAction().action();
		} );
		const notices = select( WIZARD_STORE_NAMESPACE ).getNotices();
		expect( notices ).toEqual(
			expect.arrayContaining( [ expect.objectContaining( { type: 'success', message: expect.stringMatching( /saved/i ) } ) ] )
		);
	} );

	it( 'does not render Tracking until the wizard endpoint reports configured=true', async () => {
		// Unconfigured response — Tracking should stay hidden so it doesn't
		// hit the tracking endpoint on installs without the newsletters plugin.
		apiFetch.mockResolvedValue( { ...SETTINGS_FIXTURE, configured: false } );
		renderWithRouter( <NewslettersSettings /> );
		await waitFor( () => expect( getSaveAction() ).toBeDefined() );
		expect( screen.queryByText( /Ads tracking/i ) ).not.toBeInTheDocument();
	} );

	it( 'locks the subscription lists and shows the warning when the saved provider has no key', async () => {
		const configuredFixture = {
			...SETTINGS_FIXTURE,
			esp_connected: true,
			settings: {
				...SETTINGS_FIXTURE.settings,
				newspack_newsletters_service_provider: {
					...SETTINGS_FIXTURE.settings.newspack_newsletters_service_provider,
					value: 'mailchimp',
				},
				newspack_newsletters_mailchimp_api_key: {
					...SETTINGS_FIXTURE.settings.newspack_newsletters_mailchimp_api_key,
					value: 'abc-key',
				},
			},
		};
		// The backend reports the ESP disconnected once the key is removed.
		const unconfiguredFixture = { ...configuredFixture, esp_connected: false };
		apiFetch.mockImplementation( config => {
			if ( config?.path === '/newspack-newsletters/v1/lists' ) {
				return Promise.resolve( SUBSCRIPTION_LISTS_FIXTURE );
			}
			if ( config?.method === 'POST' ) {
				return Promise.resolve( unconfiguredFixture );
			}
			return Promise.resolve( configuredFixture );
		} );
		renderWithRouter( <NewslettersSettings /> );
		const ignore = 'script, style, .a11y-speak-region';
		// Lists load with a connected provider — no warning yet.
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		expect( screen.queryByText( /Please save your ESP settings/i, { ignore } ) ).not.toBeInTheDocument();

		// Remove the API key, then save.
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Mailchimp API Key' ), { target: { value: '' } } );
		} );
		await act( async () => {
			await getSaveAction().action();
		} );

		await waitFor( () =>
			expect( screen.getByText( /Please save your ESP settings before changing your subscription lists/i, { ignore } ) ).toBeInTheDocument()
		);
	} );

	it( 'reloads the subscription lists when the saved provider key is rotated', async () => {
		const configuredFixture = {
			...SETTINGS_FIXTURE,
			esp_connected: true,
			settings: {
				...SETTINGS_FIXTURE.settings,
				newspack_newsletters_service_provider: {
					...SETTINGS_FIXTURE.settings.newspack_newsletters_service_provider,
					value: 'mailchimp',
				},
				newspack_newsletters_mailchimp_api_key: {
					...SETTINGS_FIXTURE.settings.newspack_newsletters_mailchimp_api_key,
					value: 'abc-key',
				},
			},
		};
		apiFetch.mockImplementation( config => {
			if ( config?.path === '/newspack-newsletters/v1/lists' ) {
				return Promise.resolve( SUBSCRIPTION_LISTS_FIXTURE );
			}
			return Promise.resolve( configuredFixture );
		} );
		const listCalls = () => apiFetch.mock.calls.filter( ( [ c ] ) => c?.path === '/newspack-newsletters/v1/lists' ).length;
		renderWithRouter( <NewslettersSettings /> );
		await waitFor( () => expect( screen.getByText( 'Local A' ) ).toBeInTheDocument() );
		const before = listCalls();

		// Rotate the key to a different valid value, then save.
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Mailchimp API Key' ), { target: { value: 'xyz-key' } } );
		} );
		await act( async () => {
			await getSaveAction().action();
		} );

		await waitFor( () => expect( listCalls() ).toBeGreaterThan( before ) );
	} );

	it( 'clears the dirty flag after save even if a fetch resolves during the request', async () => {
		// Captures the payload at save-call time so the saved snapshot reflects
		// what was actually sent, not a later edit.
		let resolveSave;
		const savePromise = new Promise( resolve => {
			resolveSave = resolve;
		} );
		apiFetch.mockImplementation( config => {
			if ( config?.method === 'POST' ) {
				return savePromise;
			}
			return Promise.resolve( SETTINGS_FIXTURE );
		} );
		renderWithRouter( <NewslettersSettings /> );
		await waitFor( () => expect( getSaveAction() ).toBeDefined() );
		const pending = act( async () => {
			await getSaveAction().action();
		} );
		resolveSave( SETTINGS_FIXTURE );
		await pending;
		// After save, header Save action should be disabled again.
		await waitFor( () => expect( getSaveAction() ).toEqual( expect.objectContaining( { disabled: true } ) ) );
	} );
} );
