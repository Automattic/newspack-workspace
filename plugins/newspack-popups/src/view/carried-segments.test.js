import { ingestCarriedSegments, CARRIED_PARAM } from './carried-segments';
import { CARRIED_SEGMENTS_KEY } from './utils/segments';

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

describe( 'ingestCarriedSegments', () => {
	let ras;

	beforeEach( () => {
		window.newspack_popups_view = { segments: { 12: { criteria: [] }, 45: { criteria: [] } } };
		window.history.replaceState( {}, '', '/' );
		ras = makeRas();
	} );

	it( 'remembers valid carried IDs for the session, without syncing to reader meta', () => {
		window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=12,45` );
		ingestCarriedSegments( ras );
		expect( ras.data[ CARRIED_SEGMENTS_KEY ].value ).toEqual( [ '12', '45' ] );
		expect( ras.store.set ).toHaveBeenCalledWith( CARRIED_SEGMENTS_KEY, expect.anything(), false );
	} );

	it( 'merges newly carried IDs with those carried earlier in the session', () => {
		window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=12` );
		ingestCarriedSegments( ras );
		window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=45,12` );
		ingestCarriedSegments( ras );
		expect( ras.data[ CARRIED_SEGMENTS_KEY ].value ).toEqual( [ '12', '45' ] );
	} );

	it( 'ignores unknown, malformed and raw merge-tag values entirely', () => {
		[ '%SEGMENTS%', '*|SEGS|*', '[[SEGS]]', '99', 'abc', '12abc', '12;45' ].forEach( raw => {
			window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=${ encodeURIComponent( raw ) }` );
			ingestCarriedSegments( ras );
		} );
		expect( ras.data[ CARRIED_SEGMENTS_KEY ] ).toBeUndefined();
		expect( ras.store.set ).not.toHaveBeenCalled();
	} );

	it( 'keeps the valid IDs from a partly invalid list, deduplicated', () => {
		window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=12,99,abc,12` );
		ingestCarriedSegments( ras );
		expect( ras.data[ CARRIED_SEGMENTS_KEY ].value ).toEqual( [ '12' ] );
	} );

	it( 'does nothing without the param, and never throws without a store', () => {
		ingestCarriedSegments( ras );
		expect( ras.store.set ).not.toHaveBeenCalled();
		window.history.replaceState( {}, '', `/?${ CARRIED_PARAM }=12` );
		expect( () => ingestCarriedSegments( undefined ) ).not.toThrow();
	} );
} );
