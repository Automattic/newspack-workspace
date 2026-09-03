/**
 * The subscriber list publishes its row count to the wizard header. A read still
 * in flight, or one that never landed, has no count to publish: "(0)" would
 * assert the site has no subscribers.
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
import SubscriberList from './SubscriberList';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/subscriber-list' } ) );

// Only the header count is under test, so DataViews renders nothing. The list
// reads the router at module scope, so the proxy has to answer here too.
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: () => null,
	// A real button, because the focus restoration needs a host node to land on.
	Button: require( 'react' ).forwardRef( ( { children, ...props }, ref ) =>
		require( 'react' ).createElement( 'button', { ...props, ref }, children )
	),
	Waiting: () => null,
	Router: { useHistory: () => ( { push: jest.fn() } ), useLocation: () => ( { pathname: '/' } ) },
} ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );

let headerCalls = [];

register(
	createReduxStore( 'test/subscriber-list', {
		reducer: ( state = {} ) => state,
		actions: {
			setHeaderData: data => {
				headerCalls.push( data );
				return { type: 'NOOP' };
			},
		},
	} )
);

const subscriber = id => ( {
	id,
	name: `Reader ${ id }`,
	email: `reader${ id }@example.com`,
	status: 'active',
	groups: [],
	subscriptions: [],
	memberSince: '2026-01-01T00:00:00Z',
	lastPayment: null,
} );

const page = total => ( { items: Array.from( { length: total }, ( _, i ) => subscriber( i + 1 ) ), total, pages: 1 } );

/** The leaf of the last header payload that named the section. */
const publishedSection = () => {
	const named = headerCalls.filter( data => data.sectionName );
	return named[ named.length - 1 ].sectionName[ 0 ];
};

const lastHeaderCall = () => headerCalls[ headerCalls.length - 1 ];

describe( 'the subscriber list header count', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'publishes the count once the subscribers land', async () => {
		apiFetch.mockResolvedValue( page( 2 ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( publishedSection().label ).toBe( 'Subscribers' );
		expect( publishedSection().count ).toBe( 2 );
	} );

	it( 'publishes no count while the read is in flight', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <SubscriberList /> );

		expect( publishedSection().label ).toBe( 'Subscribers' );
		expect( publishedSection().count ).toBeUndefined();

		await act( async () => {
			land( page( 1 ) );
		} );

		expect( publishedSection().count ).toBe( 1 );
	} );

	it( 'publishes no count when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( publishedSection().label ).toBe( 'Subscribers' );
		expect( publishedSection().count ).toBeUndefined();
	} );

	it( 'inflects the spoken count phrase', async () => {
		apiFetch.mockResolvedValue( page( 1 ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );
		expect( publishedSection().countLabel ).toBe( '1 subscriber' );

		headerCalls = [];
		apiFetch.mockResolvedValue( page( 2 ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );
		expect( publishedSection().countLabel ).toBe( '2 subscribers' );
	} );
} );

describe( 'the subscriber list width override', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'leaves the section width alone once the subscribers land', async () => {
		apiFetch.mockResolvedValue( page( 1 ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		// Presence, not just the value: only an explicit `undefined` clears a `false`.
		expect( 'fullWidth' in lastHeaderCall() ).toBe( true );
		expect( lastHeaderCall().fullWidth ).toBeUndefined();
	} );

	it( 'narrows the width when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( lastHeaderCall().fullWidth ).toBe( false );
	} );
} );

describe( 'the SubscriberList load-failure announcement', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
		speak.mockClear();
	} );

	it( 'announces the failure assertively', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( speak ).toHaveBeenCalledWith( 'Could not load subscribers: nope', 'assertive' );
	} );

	it( 'says nothing when the read succeeds', async () => {
		apiFetch.mockResolvedValue( page( 1 ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( speak ).not.toHaveBeenCalled();
	} );
} );

describe( 'the SubscriberList retry affordance', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'returns focus to Retry when a retry fails again', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		} );

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toHaveFocus();
	} );

	it( 'leaves focus where the reader moved it while the retry was in flight', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		const elsewhere = document.createElement( 'button' );
		document.body.appendChild( elsewhere );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
			elsewhere.focus();
		} );

		expect( elsewhere ).toHaveFocus();
		elsewhere.remove();
	} );

	it( 'leaves focus alone on the first failure', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).not.toHaveFocus();
	} );
} );
