/**
 * A profile read fails three ways, and each has to say which one it was: a person
 * who does not exist, a request that errored, and one that came back empty.
 *
 * When the read succeeds, the cards carry what a gifted subscription needs: who
 * gave it, a way to open it, and no offer to touch the giver's saved cards. The
 * refund and payment-link confirmations name the giver, who still pays, rather
 * than the reader holding the gift.
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

// Button is real enough to take a ref and to name the card's "more" toggle. Card
// and Grid pass their content through so the real SubscriptionCard, with its real
// "more" menu, renders inside the profile.
jest.mock( '../../../../packages/components/src', () => ( {
	Button: require( 'react' ).forwardRef( ( { children, label, ...props }, ref ) => {
		// Component-only props that a DOM button would warn about.
		[ 'icon', 'size', 'showTooltip', 'isBusy', 'isDestructive' ].forEach( key => delete props[ key ] );
		return require( 'react' ).createElement( 'button', { ...props, 'aria-label': label || props[ 'aria-label' ], ref }, children );
	} ),
	Card: ( { children, __experimentalCoreProps } ) => require( 'react' ).createElement( 'div', null, __experimentalCoreProps?.header, children ),
	Divider: () => null,
	Grid: ( { children } ) => require( 'react' ).createElement( 'div', null, children ),
	// The money flows open in a modal; this one renders inline so its copy is queryable.
	Modal: ( { title, children } ) => require( 'react' ).createElement( 'div', { role: 'dialog', 'aria-label': title }, children ),
	Notice: ( { noticeText } ) => require( 'react' ).createElement( 'div', { role: 'alert' }, noticeText ),
	SectionHeader: () => null,
	Waiting: () => null,
	Router: { useParams: () => ( { id: '1' } ), useLocation: () => ( { search: '', pathname: '/subscribers/1' } ) },
} ) );

jest.mock( '../data/use-avatars', () => ( { SHOW_AVATARS: false, useAvatars: () => ( { avatars: {}, loading: false } ) } ) );
jest.mock( '../use-portals', () => ( { useWizardNode: () => null } ) );

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

// An active, refundable plan whose data would normally allow a payment-method
// change: it charges a saved card and the reader holds a second usable one.
const subscription = overrides => ( {
	id: 31,
	plan: 'Weekly digest',
	status: 'active',
	startDate: '2026-01-05',
	lastPayment: '2026-02-05',
	nextBillingDate: '2026-03-05',
	amount: 10,
	currency: 'USD',
	billingPeriod: 'month',
	billingInterval: 1,
	refundableAmount: 10,
	paymentTokenId: 11,
	paymentGatewayId: 'stripe',
	isManual: false,
	editUrl: '/wp-admin/post.php?post=31&action=edit',
	giftedBy: null,
	...overrides,
} );

const profile = overrides => ( {
	id: 1,
	name: 'Rae Reader',
	email: 'rae@example.com',
	status: 'active',
	groups: [],
	subscriptions: [ subscription() ],
	paymentMethods: [
		{ id: 11, gatewayId: 'stripe', isExpired: false, isDefault: true },
		{ id: 12, gatewayId: 'stripe', isExpired: false, isDefault: false },
	],
	...overrides,
} );

const giver = { id: 7, name: 'Gale Giver', editUrl: '/wp-admin/user-edit.php?user_id=7' };

const renderCards = async data => {
	apiFetch.mockResolvedValue( data );
	await renderProfile();
};

/** Open a card's "more" menu and return its items. */
const openCardMenu = async name => {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name } ) );
	} );
	return screen.queryAllByRole( 'menuitem' );
};

describe( 'a gifted subscription on the profile', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'renders a Gifted by row linking to the giver', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { giftedBy: giver } ) ] } ) );

		expect( screen.getByText( 'Gifted by' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Gale Giver' } ) ).toHaveAttribute( 'href', giver.editUrl );
	} );

	it( 'names the giver without a link when their edit page is unknown', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { giftedBy: { ...giver, editUrl: '' } } ) ] } ) );

		expect( screen.getByText( 'Gale Giver' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'Gale Giver' } ) ).not.toBeInTheDocument();
	} );

	it( 'omits the Gifted by row when giftedBy is null', async () => {
		await renderCards( profile() );

		expect( screen.getByText( 'First subscribed' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Gifted by' ) ).not.toBeInTheDocument();
	} );

	it( 'never offers to change the payment method, since the saved cards are the giver’s', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { giftedBy: giver } ) ] } ) );

		const labels = ( await openCardMenu( 'Subscription actions: Weekly digest' ) ).map( item => item.textContent );
		expect( labels ).toEqual( [ 'View subscription', 'Refund or cancel' ] );
	} );

	it( 'still offers the change on the same data when the subscription is not a gift', async () => {
		await renderCards( profile() );

		const labels = ( await openCardMenu( 'Subscription actions: Weekly digest' ) ).map( item => item.textContent );
		expect( labels ).toEqual( [ 'View subscription', 'Change payment method', 'Refund or cancel' ] );
	} );
} );

/** Open a card's "more" menu and choose the item with the given label. */
const chooseCardAction = async ( card, label ) => {
	const items = await openCardMenu( `Subscription actions: ${ card }` );
	await act( async () => {
		fireEvent.click( items.find( item => item.textContent === label ) );
	} );
};

describe( 'the money flows on a gifted subscription', () => {
	const gift = overrides => subscription( { giftedBy: giver, billingEmail: 'gale-billing@example.com', ...overrides } );
	const onHold = overrides => gift( { status: 'on-hold', canReactivate: true, canCharge: false, ...overrides } );

	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'says the refund goes to the giver, not to the reader holding the gift', async () => {
		await renderCards( profile( { subscriptions: [ gift() ] } ) );
		await chooseCardAction( 'Weekly digest', 'Refund or cancel' );

		expect( screen.getByText( /^Gale Giver will be refunded .* Their access continues/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /Rae Reader will be refunded/ ) ).not.toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Refund and cancel subscription' } ) );
		} );
		expect( screen.getByText( /^Gale Giver will be refunded .* their access ends immediately\./ ) ).toBeInTheDocument();
	} );

	it( 'still names the reader where the line is about their access ending', async () => {
		await renderCards( profile( { subscriptions: [ gift() ] } ) );
		await chooseCardAction( 'Weekly digest', 'Refund or cancel' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Cancel only (no refund)' } ) );
		} );
		expect( screen.getByText( 'Rae Reader’s access ends immediately. No refund is issued.' ) ).toBeInTheDocument();
	} );

	it( 'names the reader as refunded when they paid for the subscription themselves', async () => {
		await renderCards( profile() );
		await chooseCardAction( 'Weekly digest', 'Refund or cancel' );

		expect( screen.getByText( /^Rae Reader will be refunded .* Their access continues/ ) ).toBeInTheDocument();
	} );

	it( "shows the giver's billing email as where the payment link goes, and confirms it", async () => {
		apiFetch.mockImplementation( ( { method } ) =>
			Promise.resolve( 'POST' === method ? { emailSent: true } : profile( { subscriptions: [ onHold() ] } ) )
		);
		await renderProfile();
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		} );
		expect( screen.getByText( /A payment link will be emailed to gale-billing@example\.com\./ ) ).toBeInTheDocument();
		expect( screen.queryByText( /rae@example\.com/ ) ).not.toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Confirm' } ) );
		} );
		expect( screen.getByText( 'Payment link sent to gale-billing@example.com.' ) ).toBeInTheDocument();
	} );

	it( "falls back to the reader's email when the subscription reports no billing email", async () => {
		await renderCards( profile( { subscriptions: [ subscription( { status: 'on-hold', canReactivate: true, billingEmail: '' } ) ] } ) );
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		} );
		expect( screen.getByText( /A payment link will be emailed to rae@example\.com\./ ) ).toBeInTheDocument();
	} );
} );

describe( 'the View subscription menu item', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'leads the card menu as a link to the subscription', async () => {
		const sub = subscription();
		await renderCards( profile( { subscriptions: [ sub ] } ) );

		const [ first, ...rest ] = await openCardMenu( 'Subscription actions: Weekly digest' );
		expect( first ).toHaveTextContent( 'View subscription' );
		expect( first ).toHaveAttribute( 'href', sub.editUrl );
		expect( rest[ rest.length - 1 ] ).toHaveTextContent( 'Refund or cancel' );
	} );

	it( 'is left out when the subscription has no edit screen', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { editUrl: '' } ) ] } ) );

		const labels = ( await openCardMenu( 'Subscription actions: Weekly digest' ) ).map( item => item.textContent );
		expect( labels ).not.toContain( 'View subscription' );
	} );

	it( 'is the one item on a group card that has an editUrl', async () => {
		const group = {
			id: 5,
			plan: 'Team plan',
			status: 'active',
			role: 'owner',
			members: 2,
			seatLimit: 5,
			createdAt: '2026-01-01',
			editUrl: '/wp-admin/post.php?post=5&action=edit',
		};
		await renderCards( profile( { groups: [ group ], subscriptions: [] } ) );

		const items = await openCardMenu( 'Subscription actions: Team plan' );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toHaveTextContent( 'View subscription' );
		expect( items[ 0 ] ).toHaveAttribute( 'href', group.editUrl );
	} );

	it( 'gives a group card without an editUrl no menu at all', async () => {
		const group = { id: 5, plan: 'Team plan', status: 'active', role: 'owner', members: 2, seatLimit: 5, createdAt: '2026-01-01' };
		await renderCards( profile( { groups: [ group ], subscriptions: [] } ) );

		expect( screen.queryByRole( 'button', { name: 'Subscription actions: Team plan' } ) ).not.toBeInTheDocument();
	} );
} );
