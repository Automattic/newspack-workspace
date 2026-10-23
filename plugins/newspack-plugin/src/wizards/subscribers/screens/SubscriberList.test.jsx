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

// DataViews renders nothing; it only captures the props it was handed, so a
// column's render function can be exercised without a table. The list reads the
// router at module scope, so the proxy has to answer here too.
let mockDataViewsProps = null;
jest.mock( '../../../../packages/components/src', () => ( {
	DataViews: props => {
		mockDataViewsProps = props;
		return null;
	},
	// A real button, because the focus restoration needs a host node to land on.
	Button: require( 'react' ).forwardRef( ( { children, ...props }, ref ) =>
		require( 'react' ).createElement( 'button', { ...props, ref }, children )
	),
	Notice: () => null,
	// A plain stand-in: the status cell renders one per status.
	StatusIndicator: ( { children } ) => require( 'react' ).createElement( 'span', null, children ),
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

/**
 * A reader the network created gets a "Network" badge beside the name, and the
 * subscriptions another site manages sit in the same Subscription and Status
 * columns as this site's own: the endpoint merges them into `subscriptions`.
 */
describe( 'the network reader rows', () => {
	beforeEach( () => {
		headerCalls = [];
		mockDataViewsProps = null;
		apiFetch.mockReset();
	} );

	const remoteSubscription = {
		id: 500,
		plan: 'Fan Membership (Node Daily)',
		status: 'active',
		editUrl: 'https://node-b.example.test/wp-admin/post.php?post=500&action=edit',
		isComplimentary: false,
		managedOn: { name: 'Node Daily', url: 'https://node-b.example.test' },
	};

	/** Mount the list, then render one column's cell for the given row. */
	const renderCell = async ( fieldId, item ) => {
		apiFetch.mockResolvedValue( { items: [ item ], total: 1, pages: 1 } );
		await act( async () => {
			render( <SubscriberList /> );
		} );
		const column = mockDataViewsProps.fields.find( field => fieldId === field.id );
		return render( column.render( { item } ) );
	};

	it( 'badges a reader the network created, beside the name', async () => {
		const { container } = await renderCell( 'name', { ...subscriber( 1 ), isNetworkReader: true } );

		expect( container.textContent ).toContain( 'Reader 1' );
		expect( container.textContent ).toContain( 'Network' );
		expect( container.textContent.indexOf( 'Reader 1' ) ).toBeLessThan( container.textContent.indexOf( 'Network' ) );
		// The badge shares a line with the name, not the email.
		expect( container.querySelector( '.newspack-subscribers__email' ).textContent ).toBe( 'reader1@example.com' );
	} );

	it( 'leaves a reader this site created without the badge', async () => {
		const { container } = await renderCell( 'name', subscriber( 1 ) );

		expect( container.textContent ).toContain( 'Reader 1' );
		expect( container.textContent ).not.toContain( 'Network' );
	} );

	it( 'keeps the row id on the name cell for the badged reader', async () => {
		const { container } = await renderCell( 'name', { ...subscriber( 7 ), isNetworkReader: true } );

		expect( container.querySelector( '[data-subscriber-id="7"]' ) ).not.toBeNull();
	} );

	it( 'links a remote subscription in the Subscription column to the other site', async () => {
		await renderCell( 'plans', { ...subscriber( 1 ), subscriptions: [ remoteSubscription ] } );

		expect( screen.getByRole( 'link', { name: 'Fan Membership (Node Daily)' } ) ).toHaveAttribute( 'href', remoteSubscription.editUrl );
	} );

	it( 'reads a remote active subscription as Active in the Status column', async () => {
		const { container } = await renderCell( 'status', { ...subscriber( 1 ), subscriptions: [ remoteSubscription ] } );

		expect( container.textContent ).toBe( 'Active' );
	} );
} );
