/**
 * A profile read fails three ways, and each has to say which one it was: a person
 * who does not exist, a request that errored, and one that came back empty.
 *
 * A reader can also hold subscriptions that other sites of the network manage.
 * Those render as read-only cards, and nothing on them may reach a write flow:
 * the write endpoints resolve a subscription ID against this site's own
 * subscriptions, and a network item's ID belongs to another site.
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

// The shared Button is real enough to take a ref and name itself from `label`.
// Card, Grid and SectionHeader render just their content, so the cards and the
// section headings the profile lays out can be read.
jest.mock( '../../../../packages/components/src', () => {
	const { createElement, forwardRef } = require( 'react' );
	return {
		Button: forwardRef( ( { children, label, ...props }, ref ) => {
			// Props a DOM button would warn about.
			[ 'icon', 'size', 'showTooltip' ].forEach( key => delete props[ key ] );
			return createElement( 'button', { ...props, 'aria-label': props[ 'aria-label' ] || label, ref }, children );
		} ),
		Card: ( { children, __experimentalCoreProps } ) => createElement( 'div', null, __experimentalCoreProps?.header, children ),
		Divider: () => null,
		Grid: ( { children } ) => createElement( 'div', null, children ),
		SectionHeader: ( { title } ) => createElement( 'h2', null, title ),
		Waiting: () => null,
		Router: { useParams: () => ( { id: '1' } ), useLocation: () => ( { search: '', pathname: '/subscribers/1' } ) },
	};
} );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );
jest.mock( '../use-portals', () => ( { useWizardNode: () => null } ) );

const setHeaderData = jest.fn( () => ( { type: 'NOOP' } ) );

register(
	createReduxStore( 'test/person-profile', {
		reducer: ( state = {} ) => state,
		actions: { setHeaderData },
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

const REMOTE_EDIT_URL = 'https://node-b.example.test/wp-admin/post.php?post=500&action=edit';

// A subscription another site of the network manages, as the profile endpoint
// shapes it: no billing fields, no capability flags, and a `managedOn` that the
// client tells it apart by.
const networkSubscription = {
	id: 500,
	plan: 'Fan Membership (Node Daily)',
	status: 'active',
	editUrl: REMOTE_EDIT_URL,
	isComplimentary: false,
	managedOn: { name: 'Node Daily', url: 'https://node-b.example.test' },
};

// A subscription on this site that happens to share the network one's number.
const localSubscription = {
	id: 500,
	plan: 'Fan Membership',
	status: 'active',
	startDate: '2026-01-05',
	editUrl: 'https://hub.example.test/wp-admin/post.php?post=500&action=edit',
	amount: 12,
	currency: 'USD',
	billingPeriod: 'month',
	billingInterval: 1,
	isManual: true,
	canReactivate: false,
	canChangePlan: true,
	refundableAmount: 12,
};

const reader = overrides => ( {
	id: 1,
	name: 'Casey Reader',
	email: 'casey@example.test',
	status: 'active',
	subscriptions: [],
	groups: [],
	paymentMethods: [],
	...overrides,
} );

const renderProfileFor = async subscriber => {
	apiFetch.mockResolvedValue( subscriber );
	await renderProfile();
};

const openCardMenu = async planName => {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: `Subscription actions: ${ planName }` } ) );
	} );
	return screen.queryAllByRole( 'menuitem' );
};

describe( 'a subscription managed on another network site', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		setHeaderData.mockClear();
	} );

	it( 'badges a reader the network created, in the header', async () => {
		await renderProfileFor( reader( { isNetworkReader: true, subscriptions: [ networkSubscription ] } ) );

		const { badges } = setHeaderData.mock.calls.at( -1 )[ 0 ];
		expect( badges ).toContainEqual( { label: 'Network', intent: 'informational' } );
	} );

	it( 'leaves the Network badge off a reader the network did not create', async () => {
		await renderProfileFor( reader( { subscriptions: [ localSubscription ] } ) );

		const { badges } = setHeaderData.mock.calls.at( -1 )[ 0 ];
		expect( badges.map( badge => badge.label ) ).not.toContain( 'Network' );
	} );

	it( 'links the card title to the subscription on the site that manages it', async () => {
		await renderProfileFor( reader( { subscriptions: [ networkSubscription ] } ) );

		const title = screen.getByRole( 'button', { name: 'View subscription: Fan Membership (Node Daily)' } );
		expect( title ).toHaveAttribute( 'href', REMOTE_EDIT_URL );
	} );

	it( 'offers one menu item, a link to the remote subscription, and no write action', async () => {
		await renderProfileFor( reader( { subscriptions: [ networkSubscription ] } ) );

		const items = await openCardMenu( 'Fan Membership (Node Daily)' );

		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toHaveTextContent( 'View subscription' );
		expect( items[ 0 ] ).toHaveAttribute( 'href', REMOTE_EDIT_URL );
		expect( screen.queryByRole( 'menuitem', { name: /refund|cancel|change|reactivate/i } ) ).not.toBeInTheDocument();
	} );

	it( 'names the site that manages it, linked to the same subscription and named for what it opens', async () => {
		await renderProfileFor( reader( { subscriptions: [ networkSubscription ] } ) );

		expect( screen.getByText( 'Managed on' ) ).toBeInTheDocument();
		const siteLink = screen.getByRole( 'link', { name: 'View subscription: Fan Membership (Node Daily)' } );
		expect( siteLink ).toHaveTextContent( 'Node Daily' );
		expect( siteLink ).toHaveAttribute( 'href', REMOTE_EDIT_URL );
	} );

	it( 'shows no billing rows it has no data for', async () => {
		await renderProfileFor( reader( { subscriptions: [ networkSubscription ] } ) );

		expect( screen.queryByText( 'Billing' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'First subscribed' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Last payment' ) ).not.toBeInTheDocument();
	} );

	it( 'drops the Payment methods section for a reader with only network subscriptions and no card', async () => {
		await renderProfileFor( reader( { subscriptions: [ networkSubscription ] } ) );

		// The section headings render, so the absence below is not just a blank mock.
		expect( screen.getByRole( 'heading', { name: 'Subscriptions' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Payment methods' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders a local and a network subscription with the same number as two cards without a duplicate key', async () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			await renderProfileFor( reader( { subscriptions: [ localSubscription, networkSubscription ] } ) );

			expect( screen.getByRole( 'button', { name: 'View subscription: Fan Membership' } ) ).toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'View subscription: Fan Membership (Node Daily)' } ) ).toBeInTheDocument();
			const keyWarnings = consoleError.mock.calls.filter( ( [ message ] ) => /same key/.test( String( message ) ) );
			expect( keyWarnings ).toHaveLength( 0 );
		} finally {
			consoleError.mockRestore();
		}
	} );

	it( 'keys cards from two sites apart by site name when neither carries a site URL', async () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			await renderProfileFor(
				reader( {
					subscriptions: [
						{ ...networkSubscription, managedOn: { name: 'Node Daily', url: '' } },
						{ ...networkSubscription, plan: 'Fan Membership (Hub Gazette)', managedOn: { name: 'Hub Gazette', url: '' } },
					],
				} )
			);

			expect( screen.getByRole( 'button', { name: 'View subscription: Fan Membership (Node Daily)' } ) ).toBeInTheDocument();
			expect( screen.getByRole( 'button', { name: 'View subscription: Fan Membership (Hub Gazette)' } ) ).toBeInTheDocument();
			const keyWarnings = consoleError.mock.calls.filter( ( [ message ] ) => /same key/.test( String( message ) ) );
			expect( keyWarnings ).toHaveLength( 0 );
		} finally {
			consoleError.mockRestore();
		}
	} );

	it( 'keeps the write actions off a network card even when the item carries write flags', async () => {
		// The server sends none of these for a network item. If a flag ever reached the
		// client, it must still not open a flow: the write endpoints would resolve this
		// remote ID against a local subscription that happens to share the number.
		const flagged = {
			...networkSubscription,
			canReactivate: true,
			canChangePlan: true,
			refundableAmount: 12,
			isManual: false,
			paymentTokenId: 'pm_1',
			paymentGatewayId: 'stripe',
		};
		const paymentMethods = [
			{ id: 'pm_1', gatewayId: 'stripe', brand: 'visa', last4: '4242', isDefault: true },
			{ id: 'pm_2', gatewayId: 'stripe', brand: 'visa', last4: '1881' },
		];
		await renderProfileFor( reader( { subscriptions: [ flagged ], paymentMethods } ) );

		const items = await openCardMenu( 'Fan Membership (Node Daily)' );

		expect( items.map( item => item.textContent ) ).toEqual( [ 'View subscription' ] );
		// Only the profile read went out; no write was sent.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'method' );
	} );

	it( 'leaves a dead link off the card when the other site gave no subscription URL', async () => {
		await renderProfileFor( reader( { subscriptions: [ { ...networkSubscription, editUrl: '' } ] } ) );

		expect( screen.queryByRole( 'button', { name: 'Subscription actions: Fan Membership (Node Daily)' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'View subscription: Fan Membership (Node Daily)' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Node Daily' ) ).toBeInTheDocument();
	} );
} );

describe( 'a subscription on this site', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		setHeaderData.mockClear();
	} );

	it( 'keeps its own actions, and no link out to another site', async () => {
		await renderProfileFor( reader( { subscriptions: [ localSubscription ] } ) );

		const items = await openCardMenu( 'Fan Membership' );

		expect( items.map( item => item.textContent ) ).toEqual( [ 'Change subscription', 'Refund or cancel' ] );
		expect( screen.queryByText( 'Managed on' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the Payment methods section', async () => {
		await renderProfileFor( reader( { subscriptions: [ localSubscription ] } ) );

		expect( screen.getByRole( 'heading', { name: 'Payment methods' } ) ).toBeInTheDocument();
	} );
} );
