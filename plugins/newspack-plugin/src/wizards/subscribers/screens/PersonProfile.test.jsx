/**
 * A profile read fails three ways, and each has to say which one it was: a person
 * who does not exist, a request that errored, and one that came back empty.
 */

/**
 * External dependencies
 */
import { render, act, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import PersonProfile from './PersonProfile';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/person-profile' } ) );

// Only the failure branches are under test. Button is real enough to take a ref.
jest.mock( '../../../../packages/components/src', () => ( {
	Button: require( 'react' ).forwardRef( ( { children, ...props }, ref ) =>
		require( 'react' ).createElement( 'button', { ...props, ref }, children )
	),
	Card: () => null,
	Divider: () => null,
	Grid: () => null,
	SectionHeader: () => null,
	Waiting: () => null,
	Router: { useParams: () => ( { id: '1' } ), useLocation: () => ( { search: '', pathname: '/subscribers/1' } ) },
} ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );
jest.mock( '../use-portals', () => ( { useWizardNode: () => null } ) );
jest.mock( '../components/SubscriptionCard', () => () => null );

register(
	createReduxStore( 'test/person-profile', {
		reducer: ( state = {} ) => state,
		actions: { setHeaderData: () => ( { type: 'NOOP' } ) },
	} )
);

const renderProfile = async () => {
	await act( async () => {
		render( <PersonProfile /> );
	} );
};

const notFoundError = () => Object.assign( new Error( 'Not found.' ), { code: 'newspack_subscriber_not_found' } );

describe( 'the person profile failure states', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		speak.mockClear();
	} );

	it( 'names a read that failed, quoting the server', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await renderProfile();

		expect( screen.getByText( /Could not load this subscriber: nope/ ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( 'Could not load this subscriber: nope', 'assertive' );
	} );

	it( 'gives a read that came back empty its own sentence', async () => {
		apiFetch.mockResolvedValue( null );
		await renderProfile();

		expect( screen.getByText( /This subscriber could not be loaded\./ ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( 'This subscriber could not be loaded.', 'assertive' );
	} );

	it( 'tells a missing person apart from a failed read, and offers a way back', async () => {
		apiFetch.mockRejectedValue( notFoundError() );
		await renderProfile();

		expect( screen.getByText( /could not be found/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Back to the list' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Retry' } ) ).not.toBeInTheDocument();
	} );

	it( 'returns focus to Retry when a retry fails again', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await renderProfile();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		} );

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toHaveFocus();
	} );

	it( 'returns focus to the way back when a retry turns up a missing person', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await renderProfile();

		apiFetch.mockRejectedValue( notFoundError() );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		} );

		expect( screen.getByRole( 'button', { name: 'Back to the list' } ) ).toHaveFocus();
	} );

	it( 'leaves focus alone on the first failure', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await renderProfile();

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).not.toHaveFocus();
	} );
} );
