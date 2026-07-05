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
	ras.store.set( 'last_active', Date.now() );
}
