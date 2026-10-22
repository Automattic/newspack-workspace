/**
 * Network helpers for the Subscribers profile: telling a subscription another site
 * manages from one this site does, and deciding when the saved-cards section has
 * nothing to say about a reader.
 */

/**
 * Internal dependencies
 */
import { isNetworkSubscription, shouldShowPaymentMethods, networkReaderBadge } from './network';

const networkSubscription = {
	id: 500,
	plan: 'Fan Membership (Node Daily)',
	status: 'active',
	editUrl: 'https://node-b.example.test/wp-admin/post.php?post=500&action=edit',
	isComplimentary: false,
	managedOn: { name: 'Node Daily', url: 'https://node-b.example.test' },
};

const localSubscription = {
	id: 500,
	plan: 'Fan Membership',
	status: 'active',
	editUrl: 'https://hub.example.test/wp-admin/post.php?post=500&action=edit',
};

const card = { id: 'pm_1', brand: 'visa', last4: '4242' };

describe( 'isNetworkSubscription', () => {
	it( 'is true for a subscription another site manages', () => {
		expect( isNetworkSubscription( networkSubscription ) ).toBe( true );
	} );

	it( 'is false for a local subscription', () => {
		expect( isNetworkSubscription( localSubscription ) ).toBe( false );
	} );

	it( 'is false for nothing at all', () => {
		expect( isNetworkSubscription( undefined ) ).toBe( false );
		expect( isNetworkSubscription( null ) ).toBe( false );
	} );
} );

describe( 'shouldShowPaymentMethods', () => {
	it( 'hides the section for a reader whose only subscriptions are on other sites and who has no card', () => {
		expect( shouldShowPaymentMethods( { subscriptions: [ networkSubscription ], groups: [], paymentMethods: [] } ) ).toBe( false );
	} );

	it( 'shows the section when the reader has a saved card', () => {
		expect( shouldShowPaymentMethods( { subscriptions: [ networkSubscription ], groups: [], paymentMethods: [ card ] } ) ).toBe( true );
	} );

	it( 'shows the section when a local subscription sits beside the network one', () => {
		expect( shouldShowPaymentMethods( { subscriptions: [ localSubscription, networkSubscription ], groups: [], paymentMethods: [] } ) ).toBe(
			true
		);
	} );

	it( 'shows the section when the reader is in a group', () => {
		expect( shouldShowPaymentMethods( { subscriptions: [ networkSubscription ], groups: [ { id: 3 } ], paymentMethods: [] } ) ).toBe( true );
	} );

	it( 'shows the section for a reader with nothing anywhere', () => {
		expect( shouldShowPaymentMethods( { subscriptions: [], groups: [], paymentMethods: [] } ) ).toBe( true );
	} );

	it( 'shows the section when the lists are missing', () => {
		expect( shouldShowPaymentMethods( {} ) ).toBe( true );
	} );
} );

describe( 'networkReaderBadge', () => {
	it( 'is an informational badge labelled Network', () => {
		expect( networkReaderBadge ).toEqual( { label: 'Network', intent: 'informational' } );
	} );
} );
