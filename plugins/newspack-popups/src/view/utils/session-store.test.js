import { readSessionValue, writeSessionValue, SESSION_TIMEOUT } from './session-store';

const GA_COOKIE = '_ga_TEST123';

const setGaSession = sid => {
	document.cookie = `${ GA_COOKIE }=GS1.1.${ sid }.5.1.1700000000.60.0.0`;
};

const clearGaCookies = () => {
	document.cookie.split( '; ' ).forEach( pair => {
		const name = pair.split( '=' )[ 0 ];
		if ( name && 0 === name.indexOf( '_ga_' ) ) {
			document.cookie = `${ name }=; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
		}
	} );
};

const makeRas = () => {
	const data = {};
	return {
		data,
		store: {
			get: jest.fn( key => data[ key ] ),
			set: jest.fn( ( key, value ) => {
				data[ key ] = value;
			} ),
		},
	};
};

describe( 'session-store', () => {
	let ras;

	beforeEach( () => {
		clearGaCookies();
		ras = makeRas();
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'round-trips a value within the session, without syncing to reader meta', () => {
		const { sid } = readSessionValue( ras, 'k' );
		writeSessionValue( ras, 'k', sid, [ 'a' ] );
		expect( readSessionValue( ras, 'k' ).value ).toEqual( [ 'a' ] );
		// Session state is per-device bookkeeping — the sync flag must be off.
		expect( ras.store.set ).toHaveBeenCalledWith( 'k', expect.anything(), false );
	} );

	it( 'expires the fallback window after the session timeout', () => {
		const now = 1700000000000;
		const dateSpy = jest.spyOn( Date, 'now' ).mockReturnValue( now );
		writeSessionValue( ras, 'k', null, 'v' );
		dateSpy.mockReturnValue( now + SESSION_TIMEOUT - 1000 );
		expect( readSessionValue( ras, 'k' ).value ).toBe( 'v' );
		dateSpy.mockReturnValue( now + SESSION_TIMEOUT + 1000 );
		expect( readSessionValue( ras, 'k' ).value ).toBeNull();
	} );

	it( 'discards the value when the GA4 session ID changes', () => {
		setGaSession( '1700000001' );
		const { sid } = readSessionValue( ras, 'k' );
		writeSessionValue( ras, 'k', sid, 'v' );
		expect( readSessionValue( ras, 'k' ).value ).toBe( 'v' );
		setGaSession( '1700009999' );
		expect( readSessionValue( ras, 'k' ).value ).toBeNull();
	} );

	it( 'treats an unrecognized stored shape as absent', () => {
		ras.data.k = { sid: null, ts: Date.now(), ids: [ 'legacy-shape' ] };
		expect( readSessionValue( ras, 'k' ).value ).toBeNull();
	} );

	it( 'never throws on a broken store', () => {
		ras.store.get.mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		ras.store.set.mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		expect( readSessionValue( ras, 'k' ) ).toEqual( { sid: null, value: null } );
		expect( () => writeSessionValue( ras, 'k', null, 'v' ) ).not.toThrow();
	} );
} );
