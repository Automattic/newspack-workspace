import { EVENTS, emit } from './events';

import { store, getReader } from './index';

/**
 * Set the pending checkout URL.
 *
 * @param url Pending checkout URL, or false to clear.
 */
export function setPendingCheckout( url: string | false = false ): void {
	store.set( 'pending_checkout', url, false );
	emit( EVENTS.reader, getReader() );
}

/**
 * Get the pending checkout URL.
 *
 * @return Pending checkout URL.
 */
export function getPendingCheckout(): string | false {
	const pendingCheckout = store.get( 'pending_checkout' );
	return typeof pendingCheckout === 'string' && pendingCheckout ? pendingCheckout : false;
}
