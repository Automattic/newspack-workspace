/**
 * The group list publishes its row count to the wizard header. A read still in
 * flight, or one that never landed, has no count to publish: "(0)" would assert
 * the site has no groups.
 *
 * Its search and Subscription filter run client-side over the loaded groups.
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

// DataViews renders nothing, but records its props so a test can drive the view
// and read the rows filterSortAndPaginate produced, which stays real. The list
// reads the router at module scope, so the proxy has to answer here too.
let dataViewsProps;
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: props => {
		dataViewsProps = props;
		return null;
	},
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
	product: 'Team Annual',
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

		// Presence, not just the value: only an explicit `undefined` clears a `false`.
		expect( 'fullWidth' in lastHeaderCall() ).toBe( true );
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

describe( 'the group list search', () => {
	beforeEach( () => {
		headerCalls = [];
		apiFetch.mockReset();
	} );

	const searchFor = async term => {
		await act( async () => {
			dataViewsProps.onChangeView( { ...dataViewsProps.view, search: term } );
		} );
		return dataViewsProps.data.map( item => item.id );
	};

	it( 'matches the owner name, owner email, group name and subscription', async () => {
		apiFetch.mockResolvedValue( {
			items: [
				{ ...group( 1 ), owner: { name: 'Ada Lovelace', email: 'ada@example.test' } },
				{ ...group( 2 ), owner: { name: 'Grace Hopper', email: 'grace@navy.example.test' } },
				{ ...group( 3 ), plan: 'Harbor Newsroom' },
				{ ...group( 4 ), product: 'Campus Site License' },
			],
		} );
		await act( async () => {
			render( <GroupList /> );
		} );

		expect( await searchFor( 'lovelace' ) ).toEqual( [ 1 ] );
		expect( await searchFor( 'navy.example' ) ).toEqual( [ 2 ] );
		expect( await searchFor( 'harbor' ) ).toEqual( [ 3 ] );
		expect( await searchFor( 'campus' ) ).toEqual( [ 4 ] );
	} );

	// The fixture's group name and product differ, so this fails if the filter
	// is keyed on the group name.
	it( 'filters the Subscription column by product, not group name', async () => {
		apiFetch.mockResolvedValue( { items: [ group( 1 ), { ...group( 2 ), product: 'Campus Site License' } ] } );
		await act( async () => {
			render( <GroupList /> );
		} );

		const subscriptionField = dataViewsProps.fields.find( field => field.id === 'product' );
		expect( subscriptionField.elements.map( element => element.value ) ).toEqual( [ 'Team Annual', 'Campus Site License' ] );

		await act( async () => {
			dataViewsProps.onChangeView( {
				...dataViewsProps.view,
				filters: [ ...dataViewsProps.view.filters, { field: 'product', operator: 'isAny', value: [ 'Campus Site License' ] } ],
			} );
		} );
		expect( dataViewsProps.data.map( item => item.id ) ).toEqual( [ 2 ] );
	} );
} );
