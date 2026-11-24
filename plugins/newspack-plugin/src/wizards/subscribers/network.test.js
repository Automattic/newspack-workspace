/**
 * Network helpers for the Subscribers profile: telling a subscription another site
 * manages from one this site does, and deciding when the saved-cards section has
 * nothing to say about a reader.
 */

/**
 * Internal dependencies
 */
import { isNetworkSubscription, shouldShowPaymentMethods } from './network';

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

	it.each( [
		[ 'the reader has a saved card', { subscriptions: [ networkSubscription ], groups: [], paymentMethods: [ card ] } ],
		[
			'a local subscription sits beside the network one',
			{ subscriptions: [ localSubscription, networkSubscription ], groups: [], paymentMethods: [] },
		],
		[ 'the reader is in a group', { subscriptions: [ networkSubscription ], groups: [ { id: 3 } ], paymentMethods: [] } ],
		[ 'the reader has nothing anywhere', { subscriptions: [], groups: [], paymentMethods: [] } ],
		[ 'the lists are missing', {} ],
	] )( 'shows the section when %s', ( _case, subscriber ) => {
		expect( shouldShowPaymentMethods( subscriber ) ).toBe( true );
	} );
} );
