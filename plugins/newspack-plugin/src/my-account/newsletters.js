/**
 * Initialize functions for the Newsletters page.
 */

import { domReady } from '../utils';

domReady( function () {
	// Dispatch a newsletter_signup activity when the user subscribes to a newsletter via My Account.
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( readerActivation => {
		const reader = readerActivation.getReader();
		const params = new URLSearchParams( window.location.search );
		const subscribed = params.get( 'newspack_newsletters_subscription_subscribed' );
		if ( subscribed && reader?.email && reader?.authenticated ) {
			readerActivation.dispatchActivity( 'newsletter_signup', {
				email: reader.email,
				lists: subscribed.split( ',' ),
				newsletters_subscription_method: 'my-account',
			} );
		}
		params.delete( 'newspack_newsletters_subscription_subscribed' );
		const newQueryString = params.toString() ? '?' + params.toString() : '';
		window.history.replaceState( {}, '', window.location.pathname + newQueryString );
	} );
} );
