import { act, renderHook } from '@testing-library/react';

import useLockedPosts from './use-locked-posts';

function createJQuery() {
	const handlers = {};
	const api = {
		on: ( event, handler ) => {
			const [ name ] = event.split( '.' );
			handlers[ name ] = handlers[ name ] || [];
			handlers[ name ].push( { event, handler } );
			return api;
		},
		off: event => {
			const [ name ] = event.split( '.' );
			handlers[ name ] = ( handlers[ name ] || [] ).filter( entry => entry.event !== event );
			return api;
		},
	};
	const jq = jest.fn( () => api );
	jq.count = name => ( handlers[ name ] || [] ).length;
	jq.trigger = ( name, data ) => ( handlers[ name ] || [] ).forEach( ( { handler } ) => handler( {}, data ) );
	return jq;
}

const LOCK = {
	'post-7': { name: 'Jennifer', text: 'Jennifer is currently editing', avatar_src: 'https://example.test/a.png' },
};

describe( 'useLockedPosts', () => {
	const originalWp = window.wp;
	let connectNow;

	beforeEach( () => {
		connectNow = jest.fn();
		window.jQuery = createJQuery();
		window.wp = { heartbeat: { connectNow } };
	} );

	afterEach( () => {
		delete window.jQuery;
		if ( originalWp ) {
			window.wp = originalWp;
		} else {
			delete window.wp;
		}
	} );

	it( 'sends the ids as heartbeat post keys and connects immediately', () => {
		renderHook( () => useLockedPosts( [ 7, 9 ] ) );

		const data = {};
		act( () => window.jQuery.trigger( 'heartbeat-send', data ) );

		expect( data[ 'wp-check-locked-posts' ] ).toEqual( [ 'post-7', 'post-9' ] );
		expect( connectNow ).toHaveBeenCalled();
	} );

	it( 'maps a tick response to locks keyed by post id', () => {
		const { result } = renderHook( () => useLockedPosts( [ 7, 9 ] ) );

		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': LOCK } ) );

		expect( result.current[ 7 ].text ).toBe( 'Jennifer is currently editing' );
		expect( result.current[ 9 ] ).toBeUndefined();
	} );

	// The response omits the key entirely once nothing is locked, so a
	// released lock must not linger in the list.
	it( 'clears locks when a later tick reports none', () => {
		const { result } = renderHook( () => useLockedPosts( [ 7 ] ) );

		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': LOCK } ) );
		expect( result.current[ 7 ] ).toBeDefined();

		act( () => window.jQuery.trigger( 'heartbeat-tick', {} ) );
		expect( result.current[ 7 ] ).toBeUndefined();
	} );

	it( 'caps how many ids one tick checks', () => {
		const ids = Array.from( { length: 260 }, ( _, index ) => index + 1 );
		renderHook( () => useLockedPosts( ids ) );

		const data = {};
		act( () => window.jQuery.trigger( 'heartbeat-send', data ) );

		expect( data[ 'wp-check-locked-posts' ] ).toHaveLength( 100 );
		expect( data[ 'wp-check-locked-posts' ][ 0 ] ).toBe( 'post-1' );
	} );

	it( 'binds nothing without ids and unbinds on unmount', () => {
		const { unmount: unmountEmpty } = renderHook( () => useLockedPosts( [] ) );
		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 0 );
		unmountEmpty();

		const { unmount } = renderHook( () => useLockedPosts( [ 7 ] ) );
		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 1 );

		unmount();
		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 0 );
		expect( window.jQuery.count( 'heartbeat-tick' ) ).toBe( 0 );
	} );

	it( 'no-ops when heartbeat is absent', () => {
		delete window.wp.heartbeat;
		const { result } = renderHook( () => useLockedPosts( [ 7 ] ) );
		expect( result.current ).toEqual( {} );
	} );
} );
