/**
 * How often last_active is sent to the server. The browser's copy updates on
 * every page view, but the server's copy only feeds the ESP sync and the
 * Subscribers screen, which don't need it to the minute. Sending it every time
 * would make every logged-in page view send a REST request.
 */
const LAST_ACTIVE_SYNC_INTERVAL = 60 * 60 * 1000; // 1 hour.

/**
 * Set up general reader engagement fields.
 *
 * @param {Object} ras Reader Activation object.
 */
export default function setupEngagement( ras ) {
	// first_visit_date — preserve the oldest known value (server or client).
	ras.store.register( 'first_visit_date', {
		merge: ( server, client ) => {
			const candidates = [ server, client ].filter( v => v !== null && v !== undefined );
			return candidates.length ? Math.min( ...candidates ) : Date.now();
		},
	} );
	// Set default if this is the first visit ever.
	if ( ! ras.store.get( 'first_visit_date' ) ) {
		ras.store.set( 'first_visit_date', Date.now() );
	}

	// last_active — most recent timestamp wins.
	ras.store.register( 'last_active', {
		merge: ( server, client ) => Math.max( server || 0, client || 0 ),
	} );
	const now = Date.now();
	const serverLastActive = Number( window.newspack_reader_data?.items?.last_active ) || 0;
	ras.store.set( 'last_active', now, now - serverLastActive >= LAST_ACTIVE_SYNC_INTERVAL );
}
