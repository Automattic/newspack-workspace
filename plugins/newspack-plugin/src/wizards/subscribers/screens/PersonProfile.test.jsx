/**
 * A profile read fails three ways, and each has to say which one it was: a person
 * who does not exist, a request that errored, and one that came back empty.
 *
 * When the read succeeds, the cards carry what a gifted subscription needs: who
 * gave it, a way to open it, and no offer to touch the giver's saved cards. The
 * refund and payment-link confirmations name the giver, who still pays, rather
 * than the reader holding the gift.
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

// Button is real enough to take a ref and to name the card's "more" toggle. Card
// and Grid pass their content through so the real SubscriptionCard, with its real
// "more" menu, renders inside the profile. SectionHeader renders its title as a
// heading, so the sections the profile lays out can be found.
jest.mock( '../../../../packages/components/src', () => ( {
	Button: require( 'react' ).forwardRef( ( { children, label, ...props }, ref ) => {
		// Component-only props that a DOM button would warn about.
		[ 'icon', 'size', 'showTooltip', 'isBusy', 'isDestructive' ].forEach( key => delete props[ key ] );
		return require( 'react' ).createElement( 'button', { ...props, 'aria-label': label || props[ 'aria-label' ], ref }, children );
	} ),
	// Like the real core Card, the body is drawn only for truthy children.
	Card: ( { children, __experimentalCoreProps } ) =>
		require( 'react' ).createElement(
			'div',
			null,
			__experimentalCoreProps?.header,
			children ? require( 'react' ).createElement( 'div', { 'data-testid': 'card-body' }, children ) : null
		),
	Divider: () => null,
	Grid: ( { children } ) => require( 'react' ).createElement( 'div', null, children ),
	// The money flows open in a modal; this one renders inline so its copy is queryable.
	Modal: ( { title, children } ) => require( 'react' ).createElement( 'div', { role: 'dialog', 'aria-label': title }, children ),
	Notice: ( { noticeText } ) => require( 'react' ).createElement( 'div', { role: 'alert' }, noticeText ),
	SectionHeader: ( { title } ) => require( 'react' ).createElement( 'h2', null, title ),
	Waiting: () => null,
	Router: { useParams: () => ( { id: '1' } ), useLocation: () => ( { search: '', pathname: '/subscribers/1' } ) },
} ) );

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

	it( 'renders a Gifted by row on the gift only, linking to the giver', async () => {
		const own = subscription( { id: 32, plan: 'Annual supporter' } );
		await renderCards( profile( { subscriptions: [ subscription( { giftedBy: giver } ), own ] } ) );

		expect( screen.getByText( 'Annual supporter' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Gifted by' ) ).toHaveLength( 1 );
		expect( screen.getByRole( 'link', { name: 'Gale Giver' } ) ).toHaveAttribute( 'href', giver.editUrl );
	} );

	it( 'names the giver without a link when their edit page is unknown', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { giftedBy: { ...giver, editUrl: '' } } ) ] } ) );

		expect( screen.getByText( 'Gale Giver' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'Gale Giver' } ) ).not.toBeInTheDocument();
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

		expect(
			screen.getByText( /^Gale Giver will be refunded .*\. Rae Reader’s access continues and the gift renews normally\.$/ )
		).toBeInTheDocument();
		expect( screen.queryByText( /Rae Reader will be refunded/ ) ).not.toBeInTheDocument();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'radio', { name: 'Refund and cancel subscription' } ) );
		} );
		expect( screen.getByText( /^Gale Giver will be refunded .* and Rae Reader’s access ends immediately\.$/ ) ).toBeInTheDocument();
	} );

	it( 'names the reader whose gift is already ending when it is set to cancel', async () => {
		await renderCards( profile( { subscriptions: [ gift( { isPendingCancel: true } ) ] } ) );
		await chooseCardAction( 'Weekly digest', 'Refund or cancel' );

		expect(
			screen.getByText( /^Gale Giver will be refunded .*\. Rae Reader’s gift is already ending; a full refund may cancel it immediately\.$/ )
		).toBeInTheDocument();
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

	it( 'names the giver in the manual-return note when the gateway cannot refund on its own', async () => {
		apiFetch.mockImplementation( ( { method } ) =>
			Promise.resolve( 'POST' === method ? { refunded: 10, cancelled: false, gatewayRefund: false } : profile( { subscriptions: [ gift() ] } ) )
		);
		await renderProfile();
		await chooseCardAction( 'Weekly digest', 'Refund or cancel' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Confirm' } ) );
		} );
		expect(
			screen.getByText( /The gateway does not refund automatically, so the payment must be returned to Gale Giver manually\./ )
		).toBeInTheDocument();
		expect( screen.queryByText( /returned to the subscriber manually/ ) ).not.toBeInTheDocument();
	} );

	it( 'names the giver in the help text of the payment-link option', async () => {
		await renderCards( profile( { subscriptions: [ onHold() ] } ) );
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		expect( screen.getByText( 'Email Gale Giver a link to pay. The subscription stays on hold until they pay.' ) ).toBeInTheDocument();
		expect( screen.queryByText( /Email the subscriber a link to pay/ ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the subscriber wording in the payment-link option when the subscription is not a gift', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { status: 'on-hold', canReactivate: true } ) ] } ) );
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		expect( screen.getByText( 'Email the subscriber a link to pay. Stays on hold until they pay.' ) ).toBeInTheDocument();
	} );

	it( 'names the giver when the payment link email could not be sent', async () => {
		apiFetch.mockImplementation( ( { method } ) =>
			Promise.resolve(
				'POST' === method ? { emailSent: false, paymentUrl: 'https://example.test/pay/31' } : profile( { subscriptions: [ onHold() ] } )
			)
		);
		await renderProfile();
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Confirm' } ) );
		} );
		expect(
			screen.getByText( 'The email could not be sent. Share this payment link with Gale Giver directly: https://example.test/pay/31' )
		).toBeInTheDocument();
	} );

	it( 'names no address when the subscription reports no billing email', async () => {
		await renderCards( profile( { subscriptions: [ subscription( { status: 'on-hold', canReactivate: true, billingEmail: '' } ) ] } ) );
		await chooseCardAction( 'Weekly digest', 'Reactivate' );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		} );
		expect( screen.getByText( /No billing email is on file, so you'll get a payment link to share instead\./ ) ).toBeInTheDocument();
		expect( screen.queryByText( /rae@example\.com/ ) ).not.toBeInTheDocument();
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

const REMOTE_EDIT_URL = 'https://node-b.example.test/wp-admin/post.php?post=500&action=edit';

// A subscription another site of the network manages, as the profile endpoint
// shapes it before the network has stored its billing: no billing fields, no
// capability flags, and a `managedOn` that the client tells it apart by.
const networkSubscription = {
	id: 500,
	plan: 'Fan Membership (Node Daily)',
	planName: 'Fan Membership',
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

describe( 'a subscription managed on another network site', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		setHeaderData.mockClear();
	} );

	it( 'names the site a reader signed up on in the header, with no badge for it', async () => {
		await renderCards(
			reader( { signedUpOn: { name: 'Node Daily', url: 'https://node-b.example.test' }, subscriptions: [ networkSubscription ] } )
		);

		const { badges, sectionDescription } = setHeaderData.mock.calls.at( -1 )[ 0 ];
		expect( sectionDescription.split( '\n' ) ).toContain( 'Signed up on Node Daily' );
		expect( badges.map( badge => badge.label ) ).toEqual( [ 'Active' ] );
	} );

	it( 'leaves the signed-up line off a reader who signed up on this site', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		const { sectionDescription } = setHeaderData.mock.calls.at( -1 )[ 0 ];
		expect( sectionDescription ).not.toContain( 'Signed up on' );
	} );

	it( 'titles the card with the plan alone, linked to the subscription on the site that manages it', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		const title = screen.getByRole( 'button', { name: 'View subscription: Fan Membership (Node Daily)' } );
		expect( title ).toHaveTextContent( /^Fan Membership$/ );
		expect( title ).toHaveAttribute( 'href', REMOTE_EDIT_URL );
	} );

	it( 'names the managing site once, as plain text under the title', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		expect( screen.getByText( 'Managed on Node Daily' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: /Node Daily/ } ) ).not.toBeInTheDocument();
	} );

	it( 'offers one menu item, a link to the subscription named for its site, and no write action', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		const items = await openCardMenu( 'Subscription actions: Fan Membership (Node Daily)' );

		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ] ).toHaveTextContent( 'View on Node Daily' );
		expect( items[ 0 ] ).toHaveAttribute( 'href', REMOTE_EDIT_URL );
		expect( screen.queryByRole( 'menuitem', { name: /refund|cancel|change|reactivate/i } ) ).not.toBeInTheDocument();
	} );

	it( 'shows the billing rows a local card shows once the other site has sent them', async () => {
		await renderCards(
			reader( {
				subscriptions: [
					{
						...networkSubscription,
						startDate: '2026-03-14',
						amount: 12,
						currency: 'GBP',
						billingPeriod: 'month',
						billingInterval: 1,
						lastPayment: '2026-10-14',
						nextBillingDate: '2026-11-14',
					},
				],
			} )
		);

		[ 'First subscribed', 'Billing', 'Last payment', 'Next billing' ].forEach( label => expect( screen.getByText( label ) ).toBeInTheDocument() );
		expect( screen.getByText( '£12.00 / month' ) ).toBeInTheDocument();
	} );

	it( 'shows the card header alone when the other site sent no billing', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		expect( screen.getByText( 'Managed on Node Daily' ) ).toBeInTheDocument();
		expect( screen.queryByTestId( 'card-body' ) ).not.toBeInTheDocument();

		expect( screen.queryByText( 'Billing' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'First subscribed' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Last payment' ) ).not.toBeInTheDocument();
	} );

	it( 'drops the Payment methods section for a reader with only network subscriptions and no card', async () => {
		await renderCards( reader( { subscriptions: [ networkSubscription ] } ) );

		// The section headings render, so the absence below is not just a blank mock.
		expect( screen.getByRole( 'heading', { name: 'Subscriptions' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Payment methods' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders a local and a network subscription with the same number as two cards without a duplicate key', async () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			await renderCards( reader( { subscriptions: [ localSubscription, networkSubscription ] } ) );

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
			await renderCards(
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
		await renderCards( reader( { subscriptions: [ flagged ], paymentMethods } ) );

		const items = await openCardMenu( 'Subscription actions: Fan Membership (Node Daily)' );

		expect( items.map( item => item.textContent ) ).toEqual( [ 'View on Node Daily' ] );
		// Only the profile read went out; no write was sent.
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'method' );
	} );

	it( 'leaves a dead link off the card when the other site gave no subscription URL', async () => {
		await renderCards( reader( { subscriptions: [ { ...networkSubscription, editUrl: '' } ] } ) );

		expect( screen.queryByRole( 'button', { name: 'Subscription actions: Fan Membership (Node Daily)' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'View subscription: Fan Membership (Node Daily)' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Managed on Node Daily' ) ).toBeInTheDocument();
	} );
} );

describe( 'a subscription on this site', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		setHeaderData.mockClear();
	} );

	it( 'keeps its own actions, and no link out to another site', async () => {
		await renderCards( reader( { subscriptions: [ localSubscription ] } ) );

		const items = await openCardMenu( 'Subscription actions: Fan Membership' );

		expect( items.map( item => item.textContent ) ).toEqual( [ 'View subscription', 'Change subscription', 'Refund or cancel' ] );
		expect( screen.queryByText( /Managed on/ ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the Payment methods section', async () => {
		await renderCards( reader( { subscriptions: [ localSubscription ] } ) );

		expect( screen.getByRole( 'heading', { name: 'Payment methods' } ) ).toBeInTheDocument();
	} );
} );
