/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Nextdoor from './nextdoor';

const mockResetError = jest.fn();
let mockApiData;
let mockErrorMessage = null;
let mockIsFetching = false;
// Assigned on every render of the mocked hook, so a save can push the new payload into
// state the way the real hook does. Nothing re-renders otherwise.
let pushApiData = () => {};
// What the card hands the hook, which is also what it renders from until the request
// comes back.
let mockHookOptions;

// A save mirrors its payload into `apiData`, which is what the real hook does and what
// the card reads back to tell an enabled integration from a disabled one.
const mockApiFetchToggle = jest.fn( payload => {
	pushApiData( current => ( { ...current, ...payload } ) );
	return Promise.resolve();
} );

jest.mock( '../../../../hooks/use-wizard-api-fetch-toggle', () => {
	const { useState } = require( '@wordpress/element' );
	const useWizardApiFetchToggle = options => {
		mockHookOptions = options;
		const [ apiData, setApiData ] = useState( mockApiData );
		pushApiData = setApiData;
		return {
			description: 'Share posts directly to your Nextdoor community.',
			apiData,
			isFetching: mockIsFetching,
			apiFetchToggle: mockApiFetchToggle,
			errorMessage: mockErrorMessage,
			resetError: mockResetError,
		};
	};
	return { __esModule: true, default: useWizardApiFetchToggle };
} );

const mockNotify = jest.fn();
jest.mock( './context', () => ( {
	useSocialCards: () => ( { notify: mockNotify } ),
	useErrorAnnouncement: () => {},
} ) );

// The card's own orchestration is what is under test, so the form is a marker for
// whether the card is open.
jest.mock( './nextdoor/form', () => {
	const { createElement } = require( '@wordpress/element' );
	return {
		NextdoorForm: ( { renderSecondaryActions } ) =>
			createElement( 'div', { 'data-testid': 'nextdoor-form' }, renderSecondaryActions ? renderSecondaryActions() : null ),
	};
} );

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn( () => Promise.resolve( {} ) ) } ) );

const CONNECTION = {
	is_connected: false,
	has_credentials: false,
	has_centralized_credentials: false,
	has_tokens: false,
	has_page: false,
	token_valid: false,
};

const CONNECTED = { ...CONNECTION, is_connected: true, has_credentials: true, has_tokens: true, has_page: true, token_valid: true };

/**
 * Build the payload the settings endpoint returns.
 *
 * @param {Object} overrides Fields to override on the payload.
 * @return {Object} Endpoint payload.
 */
const data = ( overrides = {} ) => ( {
	module_enabled_nextdoor: false,
	is_connected: false,
	connection_status: CONNECTION,
	settings: { client_id: '', publication_url: '', allowed_roles: [] },
	...overrides,
} );

/**
 * Point the card at a URL, which it reads once on its first render.
 *
 * @param {string} search Query string, including the leading `?`.
 */
const arriveAt = search => {
	delete window.location;
	window.location = { href: `https://example.com/wp-admin/admin.php${ search }`, pathname: '/wp-admin/admin.php', search, hash: '#social' };
};

beforeEach( () => {
	jest.clearAllMocks();
	mockApiData = data();
	mockErrorMessage = null;
	mockIsFetching = false;
	arriveAt( '' );
} );

describe( 'Nextdoor card', () => {
	it( 'shows no badge while the integration is off', () => {
		render( <Nextdoor /> );

		expect( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Not connected' ) ).not.toBeInTheDocument();
	} );

	it( 'reports an enabled integration that has not been connected', () => {
		mockApiData = data( { module_enabled_nextdoor: true } );

		render( <Nextdoor /> );

		expect( screen.getByText( 'Not connected' ) ).toBeInTheDocument();
	} );

	it( 'reports a connection whose token has stopped working', () => {
		mockApiData = data( {
			module_enabled_nextdoor: true,
			is_connected: true,
			connection_status: { ...CONNECTED, token_valid: false },
		} );

		render( <Nextdoor /> );

		expect( screen.getByText( 'Reconnect needed' ) ).toBeInTheDocument();
	} );

	it( 'reports a working connection', () => {
		mockApiData = data( { module_enabled_nextdoor: true, is_connected: true, connection_status: CONNECTED } );

		render( <Nextdoor /> );

		expect( screen.getByText( 'Enabled' ) ).toBeInTheDocument();
	} );

	it( 'starts from the shape the server sends while the integration is off', () => {
		render( <Nextdoor /> );

		// The endpoint answers with an empty array until the module is on, and the card has
		// to be able to tell that from a status, so its own starting point says the same.
		expect( Array.isArray( mockHookOptions.data.connection_status ) ).toBe( true );
	} );

	it( 'reads a lapsed token off the answer as it arrives', async () => {
		// The card starts from its own defaults and the answer lands a render later, so a
		// badge reading state mirrored from that answer would call the connection healthy
		// for a render before turning red.
		render( <Nextdoor /> );

		await act( async () => {
			pushApiData(
				data( {
					module_enabled_nextdoor: true,
					is_connected: true,
					connection_status: { ...CONNECTED, token_valid: false },
				} )
			);
		} );

		expect( screen.getByText( 'Reconnect needed' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
	} );

	it( 'reports a failed request ahead of the connection state', () => {
		mockErrorMessage = 'Could not reach the server.';
		mockApiData = data( { module_enabled_nextdoor: true, is_connected: true, connection_status: CONNECTED } );

		render( <Nextdoor /> );

		expect( screen.getByText( 'Error' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Enabled' ) ).not.toBeInTheDocument();
	} );

	it( 'turns the integration on and opens the form', async () => {
		render( <Nextdoor /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) );

		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );
		expect( mockApiFetchToggle ).toHaveBeenCalledWith( { module_enabled_nextdoor: true }, true );
	} );

	it( 'leaves the integration off when enabling it fails', async () => {
		mockApiFetchToggle.mockRejectedValueOnce( new Error( 'nope' ) );

		render( <Nextdoor /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) );

		await waitFor( () => expect( mockApiFetchToggle ).toHaveBeenCalled() );
		expect( screen.queryByTestId( 'nextdoor-form' ) ).not.toBeInTheDocument();
	} );

	it( 'rolls the integration back off when a fresh enable is cancelled', async () => {
		render( <Nextdoor /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) );
		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );

		// The action becomes Cancel once the card is open, and the module is only on
		// because Enable put it there a moment ago.
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel editing Nextdoor' } ) );

		await waitFor( () => expect( mockApiFetchToggle ).toHaveBeenCalledWith( { module_enabled_nextdoor: false }, true ) );
	} );

	it( 'keeps the integration on when the form is dismissed with Escape', async () => {
		render( <Nextdoor /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) );
		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );
		mockApiFetchToggle.mockClear();

		fireEvent.keyDown( screen.getByRole( 'region', { name: 'Nextdoor' } ), { key: 'Escape' } );

		await waitFor( () => expect( screen.queryByTestId( 'nextdoor-form' ) ).not.toBeInTheDocument() );
		// Credentials may already have been saved into it, so dismissing the form must
		// never deactivate the module.
		expect( mockApiFetchToggle ).not.toHaveBeenCalled();
	} );

	it( 'offers no Disable while a fresh enable is still open', async () => {
		render( <Nextdoor /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Nextdoor' } ) );

		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: 'Disable' } ) ).not.toBeInTheDocument();
	} );

	it( 'opens itself on the return from Nextdoor when setup is unfinished', async () => {
		arriveAt( '?oauth_success=1' );
		mockApiData = data( { module_enabled_nextdoor: true } );

		render( <Nextdoor /> );

		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );
	} );

	it( 'opens itself on a failed return even when already connected', async () => {
		arriveAt( '?nextdoor_oauth_error=Something%20went%20wrong' );
		mockApiData = data( { module_enabled_nextdoor: true, is_connected: true, connection_status: CONNECTED } );

		render( <Nextdoor /> );

		await waitFor( () => expect( screen.getByTestId( 'nextdoor-form' ) ).toBeInTheDocument() );
	} );

	it( 'stays shut on an ordinary load, where opening would take focus', () => {
		mockApiData = data( { module_enabled_nextdoor: true } );

		render( <Nextdoor /> );

		expect( screen.queryByTestId( 'nextdoor-form' ) ).not.toBeInTheDocument();
	} );
} );
