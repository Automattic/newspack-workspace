import './style.scss';
import './extensions';

import { render } from '@wordpress/element';

import LocalListModalHost from './local-list-modal-host';
import { EVENTS } from './events';

const ROOT_CLASS = 'newspack-newsletters-wizard-bridge-root';

// Expose the event contract so wizard consumers read the live names rather than a mirror.
if ( typeof window !== 'undefined' ) {
	window.newspackNewslettersEvents = EVENTS;
}

export function boot() {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if ( document.querySelector( `.${ ROOT_CLASS }` ) ) {
		return;
	}
	if ( ! window.newspack_newsletters_wizard_bridge ) {
		return;
	}
	const container = document.createElement( 'div' );
	container.className = ROOT_CLASS;
	document.body.appendChild( container );
	// `<LocalListModalHost />` flips `window.newspackNewslettersBridgeReady` and dispatches `bridge-mounted` from its own effect, so a sync consumer dispatch lands at a ready listener.
	render( <LocalListModalHost />, container );
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}
