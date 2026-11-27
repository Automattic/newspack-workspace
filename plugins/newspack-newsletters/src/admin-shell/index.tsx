/**
 * Admin shell entry point — resolves the current page slug from the
 * `newspackNewslettersAdmin` global and mounts the matching screen.
 */

import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import App from './app';
import { resolveLabel, resolveScreen } from './screens';
import './style.scss';

domReady( () => {
	const admin = window.newspackNewslettersAdmin;
	const mountId = admin?.mountId;
	const currentPage = admin?.currentPage;
	if ( ! mountId ) {
		return;
	}

	const target = document.getElementById( mountId );
	if ( ! target ) {
		return;
	}

	const entry = resolveScreen( currentPage );
	if ( ! entry ) {
		return;
	}

	// Prefer the PHP-localised label so the rendered heading matches the admin menu — registry label is the fallback.
	createRoot( target ).render( <App label={ resolveLabel( currentPage ) } Screen={ entry.component } /> );
} );
