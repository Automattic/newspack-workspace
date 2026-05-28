import { domReady } from '../../utils';

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	const switchSubscription = document.querySelectorAll( '.wcs-switch-link' );
	[ ...switchSubscription ].forEach( button => {
		button.addEventListener( 'click', ev => {
			const url = new URL( button.getAttribute( 'href' ) );
			const subscriptionId = url.searchParams.get( 'switch-subscription' );
			const modal = document.querySelector( '.newspack__subscription-tiers[data-subscription-id="' + subscriptionId + '"]' );
			if ( modal ) {
				ev.preventDefault();
				modal.setAttribute( 'data-state', 'open' );
			}
		} );
	} );
} );
