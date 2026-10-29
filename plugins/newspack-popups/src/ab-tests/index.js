/* globals newspack_popups_ab_tests */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import App from './App';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'newspack-popups-ab-tests-root' );
	if ( root ) {
		createRoot( root ).render( <App data={ newspack_popups_ab_tests } /> );
	}
} );
