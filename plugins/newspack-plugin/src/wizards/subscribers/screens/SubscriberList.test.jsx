/**
 * The subscriber list publishes its row count to the wizard header. A read still
 * in flight, or one that never landed, has no count to publish: "(0)" would
 * assert the site has no subscribers.
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
import SubscriberList from './SubscriberList';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../packages/components/src/wizard/store', () => ( { WIZARD_STORE_NAMESPACE: 'test/subscriber-list' } ) );

// DataViews renders nothing; it only captures the props it was handed, so a
// column's render function can be exercised without a table. The list reads the
// router at module scope, so the proxy has to answer here too.
let mockDataViewsProps = null;
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: props => {
		mockDataViewsProps = props;
		return null;
	},
	Button: () => null,
	Notice: () => null,
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

/**
 * The Newsletters column shows what the site records a reader as subscribed to.
 * The endpoint sends `{ id, title }` per list and reports an unresolved one — a
 * list the site holds no definition for, routinely an ESP's own ID — as a null
 * title, leaving the wording to this column.
 */
describe( 'the newsletters column', () => {
	beforeEach( () => {
		headerCalls = [];
		mockDataViewsProps = null;
		apiFetch.mockReset();
	} );

	/** Mount the list, then render the newsletters cell for one reader. */
	const renderNewsletters = async newsletters => {
		apiFetch.mockResolvedValue( { items: [ { ...subscriber( 1 ), newsletters } ], total: 1, pages: 1 } );
		await act( async () => {
			render( <SubscriberList /> );
		} );
		const column = mockDataViewsProps.fields.find( field => 'newsletters' === field.id );
		return render( column.render( { item: { newsletters } } ) ).container.textContent;
	};

	it( 'names a resolved list by its title and an unresolved one by the ID a filter would match on', async () => {
		const cell = await renderNewsletters( [
			{ id: 'newspack-42', title: 'Daily Brief' },
			{ id: 'abc123def', title: null },
		] );

		expect( cell ).toBe( 'Daily Brief, Unknown list (abc123def)' );
	} );
} );
