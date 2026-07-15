/**
 * External dependencies
 */
import { renderHook, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { getView, useView } from './view-state';

/**
 * Each test uses a unique clientId because the view store is module-global and
 * persists for the lifetime of the editor session (and so across tests here).
 */
describe( 'responsive-container view-state', () => {
	it( 'defaults to the desktop view for an unknown container', () => {
		expect( getView( 'unknown' ) ).toBe( 'desktop' );
	} );

	it( 'useView returns the current view and a setter', () => {
		const { result } = renderHook( () => useView( 'returns' ) );
		const [ view, setView ] = result.current;
		expect( view ).toBe( 'desktop' );
		expect( typeof setView ).toBe( 'function' );
	} );

	it( 'updates the view and reflects it in getView', () => {
		const { result } = renderHook( () => useView( 'updates' ) );
		act( () => result.current[ 1 ]( 'mobile' ) );
		expect( result.current[ 0 ] ).toBe( 'mobile' );
		expect( getView( 'updates' ) ).toBe( 'mobile' );
	} );

	it( 'shares state across instances of the same container', () => {
		// The container and its breakpoint children all subscribe to the same
		// clientId; setting from one must update the others.
		const container = renderHook( () => useView( 'shared' ) );
		const breakpoint = renderHook( () => useView( 'shared' ) );

		act( () => container.result.current[ 1 ]( 'mobile' ) );

		expect( container.result.current[ 0 ] ).toBe( 'mobile' );
		expect( breakpoint.result.current[ 0 ] ).toBe( 'mobile' );
	} );

	it( 'keeps separate containers independent', () => {
		const first = renderHook( () => useView( 'independent-a' ) );
		const second = renderHook( () => useView( 'independent-b' ) );

		act( () => first.result.current[ 1 ]( 'mobile' ) );

		expect( first.result.current[ 0 ] ).toBe( 'mobile' );
		expect( second.result.current[ 0 ] ).toBe( 'desktop' );
	} );

	it( 'unsubscribing one instance does not break the others', () => {
		const a = renderHook( () => useView( 'cleanup' ) );
		const b = renderHook( () => useView( 'cleanup' ) );

		a.unmount();
		act( () => b.result.current[ 1 ]( 'mobile' ) );

		expect( b.result.current[ 0 ] ).toBe( 'mobile' );
		expect( getView( 'cleanup' ) ).toBe( 'mobile' );
	} );

	it( 'initializes from the persisted view when remounted', () => {
		// View is ephemeral (not a block attribute) but lives in module memory,
		// so a remounted instance should pick up the last-set value.
		const first = renderHook( () => useView( 'persist' ) );
		act( () => first.result.current[ 1 ]( 'mobile' ) );
		first.unmount();

		const second = renderHook( () => useView( 'persist' ) );
		expect( second.result.current[ 0 ] ).toBe( 'mobile' );
	} );
} );
