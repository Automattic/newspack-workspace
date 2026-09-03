/**
 * The group list publishes its row count to the wizard header. A read still in
 * flight, or one that never landed, has no count to publish: "(0)" would assert
 * the site has no groups.
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
import GroupList from './GroupList';
import { groupLoadFailedLabel } from '../labels';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/group-list' } ) );

// Only the header count is under test, so DataViews renders nothing; the count
// itself comes from filterSortAndPaginate, which stays real.
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: () => null,
	// Renders a real button: the load-failure notice puts a ref on Retry to restore
	// focus, which needs a host node to land on.
	Button: require( 'react' ).forwardRef( ( { children, ...props }, ref ) =>
		require( 'react' ).createElement( 'button', { ...props, ref }, children )
	),
	Waiting: () => null,
} ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );

let headerCalls = [];

register(
	createReduxStore( 'test/group-list', {
		reducer: ( state = {} ) => state,
		actions: {
			setHeaderData: data => {
				headerCalls.push( data );
				return { type: 'NOOP' };
			},
		},
	} )
);

const group = id => ( {
	id,
	owner: { name: `Owner ${ id }`, email: `owner${ id }@example.com`, editUrl: '' },
	plan: 'Team plan',
	members: 3,
	seatLimit: 5,
	status: 'active',
	createdAt: '2026-01-01T00:00:00Z',
	editUrl: '',
} );

/** The leaf of the last header payload that named the section. */
const publishedSection = () => {
	const named = headerCalls.filter( data => data.sectionName );
	return named[ named.length - 1 ].sectionName[ 0 ];
};

const lastHeaderCall = () => headerCalls[ headerCalls.length - 1 ];

describe( 'the group list header count', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'publishes the count once the groups land', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ), group( 2 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( publishedSection().label ).toBe( 'Groups' );
		expect( publishedSection().count ).toBe( 2 );
	} );

	it( 'publishes no count while the read is in flight', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <GroupList /> );

		expect( publishedSection().label ).toBe( 'Groups' );
		expect( publishedSection().count ).toBeUndefined();

		await act( async () => {
			land( { items: [ group( 1 ) ] } );
		} );

		expect( publishedSection().count ).toBe( 1 );
	} );

	it( 'publishes no count when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( publishedSection().label ).toBe( 'Groups' );
		expect( publishedSection().count ).toBeUndefined();
	} );

	it( 'inflects the spoken count phrase', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );
		expect( publishedSection().countLabel ).toBe( '1 Group' );

		headerCalls = [];
		apiFetch.mockResolvedValue( { items: [ group( 1 ), group( 2 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );
		expect( publishedSection().countLabel ).toBe( '2 Groups' );
	} );
} );

describe( 'the group list width override', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'leaves the section width alone once the groups land', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( lastHeaderCall().fullWidth ).toBeUndefined();
	} );

	it( 'narrows the width when the read fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( lastHeaderCall().fullWidth ).toBe( false );
	} );
} );

describe( 'the GroupList load-failure announcement', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
		speak.mockClear();
	} );

	it( 'announces the failure assertively', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( speak ).toHaveBeenCalledWith( groupLoadFailedLabel( 'nope' ), 'assertive' );
	} );

	it( 'says nothing when the read succeeds', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ) ] } );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( speak ).not.toHaveBeenCalled();
	} );
} );

describe( 'the GroupList retry affordance', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	it( 'returns focus to Retry when a retry fails again', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <GroupList /> );
		} );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		} );

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toHaveFocus();
	} );

	it( 'leaves focus alone on the first failure', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( screen.getByRole( 'button', { name: 'Retry' } ) ).not.toHaveFocus();
	} );
} );
