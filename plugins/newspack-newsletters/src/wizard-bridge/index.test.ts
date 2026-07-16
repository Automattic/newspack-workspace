import { waitFor } from '@testing-library/react';

import { boot } from './index';
import { EVENTS } from './events';

describe( 'wizard-bridge boot', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		delete window.newspack_newsletters_wizard_bridge;
		delete window.newspackNewslettersBridgeReady;
	} );

	it( 'no-ops when the localised global is missing', () => {
		boot();
		expect( document.body.querySelector( '.newspack-newsletters-wizard-bridge-root' ) ).toBeNull();
	} );

	it( 'mounts a single root container when the localised global is present', () => {
		window.newspack_newsletters_wizard_bridge = { debug: false };
		boot();
		const containers = document.body.querySelectorAll( '.newspack-newsletters-wizard-bridge-root' );
		expect( containers ).toHaveLength( 1 );
	} );

	it( 'is idempotent — second boot does not double-mount', () => {
		window.newspack_newsletters_wizard_bridge = { debug: false };
		boot();
		boot();
		expect( document.body.querySelectorAll( '.newspack-newsletters-wizard-bridge-root' ) ).toHaveLength( 1 );
	} );

	it( 'dispatches BRIDGE_MOUNTED and sets the readiness flag once the host effect has run', async () => {
		window.newspack_newsletters_wizard_bridge = { debug: false };
		const listener = jest.fn();
		document.addEventListener( EVENTS.BRIDGE_MOUNTED, listener );
		boot();
		await waitFor( () => expect( listener ).toHaveBeenCalled() );
		expect( window.newspackNewslettersBridgeReady ).toBe( true );
		document.removeEventListener( EVENTS.BRIDGE_MOUNTED, listener );
	} );
} );
