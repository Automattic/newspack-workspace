/**
 * The group list publishes its row count to the wizard header. A read still in
 * flight, or one that never landed, has no count to publish: "(0)" would assert
 * the site has no groups.
 */

/**
 * External dependencies
 */
import { render, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import GroupList from './GroupList';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/group-list' } ) );

// Only the header count is under test, so DataViews renders nothing; the count
// itself comes from filterSortAndPaginate, which stays real.
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: () => null,
	Button: () => null,
	Notice: () => null,
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
