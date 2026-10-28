/* globals newspack_popups_ab_tests */

/**
 * WordPress dependencies
 */
import { render } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import App from './App';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'newspack-popups-ab-tests-root' );
	if ( root ) {
		render( <App data={ newspack_popups_ab_tests } />, root );
	}
} );
