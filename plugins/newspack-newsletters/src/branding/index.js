/**
 * Internal dependencies
 */
import './style.scss';

const jQuery = window && window.jQuery;

jQuery( document ).ready( () => {
	const wpBodyContentEl = document.getElementById( 'wpbody-content' );
	if ( wpBodyContentEl ) {
		const bannerEl = document.createElement( 'a' );
		bannerEl.setAttribute( 'href', 'https://newspack.com' );
		bannerEl.setAttribute( 'target', '_blank' );
		bannerEl.classList.add( 'newspack-newsletters__banner' );
		wpBodyContentEl.insertBefore( bannerEl, wpBodyContentEl.children[ 0 ] );
	}
} );
