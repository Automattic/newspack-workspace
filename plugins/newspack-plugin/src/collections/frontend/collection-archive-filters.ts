/**
 * Newspack Collections JavaScript functionality.
 */

import { domReady } from '../../utils';

class CollectionArchiveFilters {
	/**
	 * Class constructor.
	 */
	constructor() {
		const form = document.querySelector( '.collections-filter' );

		if ( form ) {
			form.addEventListener( 'change', event => this.handleFiltersChange( event ) );
		}
	}

	/**
	 * Handle filtering interactions on the collections archive page.
	 *
	 * @param {Event} event The event object.
	 */
	handleFiltersChange( event ) {
		event.preventDefault();

		const url = new URL( window.location.href );

		// Remove pagination from the URL.
		url.pathname = url.pathname.replace( /\/page\/\d+\/?$/, '/' );

		// Build new query parameters.
		const params = new URLSearchParams();
		const yearField = document.getElementById( 'year' );
		const categoryField = document.getElementById( 'category' );

		if ( yearField?.value ) {
			params.set( 'np_collections_year', yearField.value );
		}

		if ( categoryField?.value ) {
			params.set( 'np_collections_category', categoryField.value );
		}

		url.search = params.toString();
		window.location.href = url.toString();
	}
}

domReady( () => {
	new CollectionArchiveFilters();
} );
