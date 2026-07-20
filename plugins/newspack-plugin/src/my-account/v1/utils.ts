/* global newspackMyAccountV1 */

import { onOverlaysClose, queuePageReload } from '../../reader-activation/utils';

window.newspackRAS = window.newspackRAS || [];

let modalCheckoutRedirectUrl: string | null = null;

/**
 * Handle the checkout complete event.
 *
 * @param data The order details object.
 */
function handleCheckoutComplete( data: NewspackModalCheckoutOrderDetails ) {
	const { subscription_renewal, subscription_ids, order_id } = data;
	if ( subscription_ids?.length ) {
		modalCheckoutRedirectUrl = `${ newspackMyAccountV1.myAccountUrl }/view-subscription/${ subscription_ids[ 0 ] }`;
	} else if ( subscription_renewal ) {
		modalCheckoutRedirectUrl = `${ newspackMyAccountV1.myAccountUrl }/view-subscription/${ subscription_renewal }`;
	} else if ( order_id ) {
		modalCheckoutRedirectUrl = `${ newspackMyAccountV1.myAccountUrl }/view-order/${ order_id }`;
	}
}

/**
 * Handle the modal close event.
 */
function handleClose() {
	// If there's a redirect URL, navigate to it; otherwise reload the page.
	if ( modalCheckoutRedirectUrl ) {
		const redirectUrl = modalCheckoutRedirectUrl;
		modalCheckoutRedirectUrl = null;
		onOverlaysClose( () => {
			window.location.href = redirectUrl;
		} );
	} else {
		queuePageReload();
	}
}

/**
 * Register a modal checkout button.
 *
 * Must receive a link element with a `href` attribute that points to a cart
 * generation URL.
 *
 * @param element               The element to register.
 * @param title                 The modal title.
 * @param actionType            The action type.
 * @param onCheckoutComplete    The function to call when the checkout is complete.
 * @param onClose               The function to call when the modal is closed. Default is `handleClose`.
 * @param redirectToResponseUrl Whether to redirect to the response URL. Default is `true`.
 */
export function registerModalCheckoutButton(
	element: Element,
	title: string | null,
	actionType: string,
	onCheckoutComplete?: ( data: NewspackModalCheckoutOrderDetails ) => void,
	onClose?: ( () => void ) | null,
	redirectToResponseUrl = true
) {
	const spinner = document.createElement( 'div' );
	spinner.classList.add( 'newspack-ui' );
	spinner.innerHTML = '<div class="newspack-ui__spinner"><span></span></div>';

	const openCheckout = async ( url: string ) => {
		const response = await fetch( url );
		window.newspackOpenModalCheckout( {
			url: redirectToResponseUrl ? response.url : null,
			title,
			actionType,
			onCheckoutComplete: ( data: NewspackModalCheckoutOrderDetails ) => {
				handleCheckoutComplete( data );
				if ( onCheckoutComplete ) {
					onCheckoutComplete( data );
				}
			},
			onClose: onClose || handleClose,
		} );
	};

	element.addEventListener( 'click', ev => {
		document.body.appendChild( spinner );

		const url = element.getAttribute( 'href' );
		if ( ! url ) {
			return;
		}

		try {
			openCheckout( url );
			ev.preventDefault();
		} catch ( error ) {
			document.body.removeChild( spinner );
			console.error( error ); // eslint-disable-line no-console
		}
	} );
}
