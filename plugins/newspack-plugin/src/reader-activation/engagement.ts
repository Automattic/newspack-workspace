/**
 * The slice of the reader-activation client this module consumes. Declared
 * structurally (rather than the full NewspackReaderActivation) so the unit test
 * can drive it with a lightweight mock (see mocks/ras). The full client and the
 * mock both satisfy this shape.
 */
type EngagementReaderActivation = {
	store: Pick< NewspackReaderActivationStore, 'register' | 'get' | 'set' >;
};

/**
 * Set up general reader engagement fields.
 *
 * @param ras Reader Activation object.
 */
export default function setupEngagement( ras: EngagementReaderActivation ): void {
	// first_visit_date — preserve the oldest known value (server or client).
	ras.store.register( 'first_visit_date', {
		merge: ( server, client ) => {
			const candidates = [ server, client ].filter( v => v !== null && v !== undefined );
			return candidates.length ? Math.min( ...( candidates as number[] ) ) : Date.now();
		},
	} );
	// Set default if this is the first visit ever.
	if ( ! ras.store.get( 'first_visit_date' ) ) {
		ras.store.set( 'first_visit_date', Date.now() );
	}

	// last_active — most recent timestamp wins.
	ras.store.register( 'last_active', {
		merge: ( server, client ) => Math.max( ( server as number ) || 0, ( client as number ) || 0 ),
	} );
	ras.store.set( 'last_active', Date.now() );
}
