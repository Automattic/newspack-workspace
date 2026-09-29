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
		// Mirrors jQuery: without a handler the namespace is cleared wholesale,
		// with one only that binding goes.
		off: ( event, handler ) => {
			const [ name ] = event.split( '.' );
			handlers[ name ] = ( handlers[ name ] || [] ).filter( entry => entry.event !== event || ( handler && entry.handler !== handler ) );
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

	// Paging and filtering swap the id list under a mounted hook; a stale
	// closure or a leaked handler would only show up here.
	it( 'rebinds a single handler when the id list changes', () => {
		const { rerender } = renderHook( ( { ids } ) => useLockedPosts( ids ), { initialProps: { ids: [ 7, 9 ] } } );

		rerender( { ids: [ 11 ] } );

		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 1 );
		const data = {};
		act( () => window.jQuery.trigger( 'heartbeat-send', data ) );
		expect( data[ 'wp-check-locked-posts' ] ).toEqual( [ 'post-11' ] );
	} );

	it( 'drops reported locks when the id list empties', () => {
		const { result, rerender } = renderHook( ( { ids } ) => useLockedPosts( ids ), { initialProps: { ids: [ 7 ] } } );

		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': LOCK } ) );
		expect( result.current[ 7 ] ).toBeDefined();

		rerender( { ids: [] } );

		expect( result.current ).toEqual( {} );
		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 0 );
	} );

	// A new object every beat would re-render the whole list.
	it( 'keeps the same map when a tick repeats the current locks', () => {
		const { result } = renderHook( () => useLockedPosts( [ 7 ] ) );

		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': LOCK } ) );
		const first = result.current;

		// Same values, fresh objects — exactly what the next tick sends.
		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': JSON.parse( JSON.stringify( LOCK ) ) } ) );

		expect( result.current ).toBe( first );
	} );

	it( 'replaces the map when a lock changes hands', () => {
		const { result } = renderHook( () => useLockedPosts( [ 7 ] ) );

		act( () => window.jQuery.trigger( 'heartbeat-tick', { 'wp-check-locked-posts': LOCK } ) );
		const first = result.current;

		act( () =>
			window.jQuery.trigger( 'heartbeat-tick', {
				'wp-check-locked-posts': { 'post-7': { name: 'Bob', text: 'Bob is currently editing' } },
			} )
		);

		expect( result.current ).not.toBe( first );
		expect( result.current[ 7 ].text ).toBe( 'Bob is currently editing' );
	} );

	// The teardown is namespaced, so it must also be handler-scoped: a
	// namespace-wide off() would deafen a second consumer on unmount.
	it( 'leaves a second consumer bound when the first unmounts', () => {
		const { unmount } = renderHook( () => useLockedPosts( [ 7 ] ) );
		renderHook( () => useLockedPosts( [ 9 ] ) );
		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 2 );

		unmount();

		expect( window.jQuery.count( 'heartbeat-send' ) ).toBe( 1 );
		expect( window.jQuery.count( 'heartbeat-tick' ) ).toBe( 1 );
	} );

	describe( 'forced connects', () => {
		beforeEach( () => jest.useFakeTimers() );
		afterEach( () => jest.useRealTimers() );

		it( 'collapses a burst of id changes into one', () => {
			const { rerender } = renderHook( ( { ids } ) => useLockedPosts( ids ), { initialProps: { ids: [ 7 ] } } );
			expect( connectNow ).toHaveBeenCalledTimes( 1 );

			rerender( { ids: [ 8 ] } );
			rerender( { ids: [ 9 ] } );

			expect( connectNow ).toHaveBeenCalledTimes( 1 );
		} );

		// Dropping the trailing connect would leave the rows actually on
		// screen unchecked until the next ordinary beat, up to 120s later.
		it( 'still checks the set left on screen once the window closes', () => {
			const { rerender } = renderHook( ( { ids } ) => useLockedPosts( ids ), { initialProps: { ids: [ 7 ] } } );
			rerender( { ids: [ 8 ] } );
			expect( connectNow ).toHaveBeenCalledTimes( 1 );

			act( () => jest.advanceTimersByTime( 2000 ) );

			expect( connectNow ).toHaveBeenCalledTimes( 2 );
			const data = {};
			act( () => window.jQuery.trigger( 'heartbeat-send', data ) );
			expect( data[ 'wp-check-locked-posts' ] ).toEqual( [ 'post-8' ] );
		} );

		it( 'drops a pending connect when the hook unmounts', () => {
			const { rerender, unmount } = renderHook( ( { ids } ) => useLockedPosts( ids ), { initialProps: { ids: [ 7 ] } } );
			rerender( { ids: [ 8 ] } );
			unmount();

			act( () => jest.advanceTimersByTime( 2000 ) );

			expect( connectNow ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
