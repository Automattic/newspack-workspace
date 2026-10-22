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
import { resetSavedListView } from '../data/list-view-state';

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

// The list keeps its last view at module scope, so one test's view change would
// otherwise carry into the next.
beforeEach( () => {
	resetSavedListView();
} );

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
 * A refetch with rows already showing veils the list with a spinner overlay.
 * DataViews' own spinner sits below the table there, where it's easy to miss, so
 * it's kept only for a refetch with no rows to cover.
 */
describe( 'the subscriber list loading overlay', () => {
	beforeEach( () => {
		headerCalls = [];
		mockDataViewsProps = null;
		apiFetch.mockReset();
	} );

	const overlayIn = container => container.querySelector( '.newspack-subscribers__loading-overlay' );
	const listIn = container => container.querySelector( '.newspack-subscribers__clickable-rows' );

	/** Mount once `rows` subscribers have landed. */
	const mountWith = async rows => {
		apiFetch.mockResolvedValue( page( rows ) );
		let container;
		await act( async () => {
			( { container } = render( <SubscriberList /> ) );
		} );
		return container;
	};

	/** Mount, then start a refetch that lands only when `land` is called. */
	const mountThenRefetch = async rows => {
		const container = await mountWith( rows );
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		await act( async () => {
			mockDataViewsProps.onChangeView( { ...mockDataViewsProps.view, page: 2 } );
		} );
		return { container, land };
	};

	it( 'covers the rows while a refetch is in flight, and clears once it lands', async () => {
		const { container, land } = await mountThenRefetch( 2 );

		expect( overlayIn( container ) ).not.toBeNull();
		expect( listIn( container ) ).toHaveAttribute( 'aria-busy', 'true' );
		expect( mockDataViewsProps.isLoading ).toBe( false );

		await act( async () => {
			land( { ...page( 2 ), pages: 2 } );
		} );

		expect( overlayIn( container ) ).toBeNull();
		expect( listIn( container ) ).not.toHaveAttribute( 'aria-busy' );
	} );

	it( "leaves a refetch with no rows to DataViews' own spinner", async () => {
		const { container } = await mountThenRefetch( 0 );

		expect( overlayIn( container ) ).toBeNull();
		expect( mockDataViewsProps.isLoading ).toBe( true );
	} );
} );

/**
 * Opening a profile unmounts the list. Coming back by any route (back button,
 * the profile's back link, an edited URL) remounts it, and the reader should find
 * the filters, search, sort and page they left.
 */
describe( 'the subscriber list view across profile visits', () => {
	beforeEach( () => {
		headerCalls = [];
		mockDataViewsProps = null;
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( page( 2 ) );
	} );

	it( 'restores the view the reader left when the list remounts', async () => {
		let unmount;
		await act( async () => {
			( { unmount } = render( <SubscriberList /> ) );
		} );
		const changedView = {
			...mockDataViewsProps.view,
			page: 3,
			search: 'reader',
			filters: [ { field: 'status', operator: 'isAny', value: [ 'active' ] } ],
		};
		await act( async () => {
			mockDataViewsProps.onChangeView( changedView );
		} );
		unmount();

		apiFetch.mockClear();
		await act( async () => {
			render( <SubscriberList /> );
		} );

		expect( mockDataViewsProps.view ).toEqual( changedView );
		// The remount's first subscribers read asks for the restored view, not the
		// default one.
		const subscriberReads = apiFetch.mock.calls
			.map( ( [ { path } ] ) => decodeURIComponent( path ) )
			.filter( path => path.includes( '/subscribers?' ) );
		const requestedPath = subscriberReads[ 0 ];
		expect( subscriberReads ).toHaveLength( 1 );
		expect( requestedPath ).toContain( 'page=3' );
		expect( requestedPath ).toContain( 'search=reader' );
		expect( requestedPath ).toContain( 'status[0]=active' );
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
