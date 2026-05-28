import './style.scss';

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
function domReady( callback ) {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

/**
 * A lightweight lightbox.
 */
const createLightbox = url => {
	const lightboxContainer = document.createElement( 'div' );
	lightboxContainer.classList.add( 'newspack-img-lightbox' );
	const lightboxImgEl = document.createElement( 'img' );
	const lightboxCloseEl = document.createElement( 'div' );
	lightboxCloseEl.classList.add( 'newspack-img-lightbox__close' );
	lightboxCloseEl.innerHTML = '+';
	lightboxCloseEl.onclick = () => {
		document.body.removeChild( lightboxContainer );
		document.body.classList.remove( 'newspack-has-img-lightbox' );
	};
	lightboxImgEl.src = url;
	lightboxContainer.appendChild( lightboxImgEl );
	lightboxContainer.appendChild( lightboxCloseEl );
	document.body.appendChild( lightboxContainer );
	document.body.classList.add( 'newspack-has-img-lightbox' );
};

domReady( function () {
	[ ...document.querySelectorAll( 'figure[data-lightbox]' ) ].forEach( lightboxEl => {
		lightboxEl.addEventListener( 'click', () => {
			createLightbox( lightboxEl.querySelector( 'img' ).src );
		} );
	} );
} );
