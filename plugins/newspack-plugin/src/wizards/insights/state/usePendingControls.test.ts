/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import { renderHook, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import usePendingControls from './usePendingControls';
import type { DateRange } from './useDateRange';

const DEFAULT_RANGE: DateRange = { preset: 'last-30', start: '2026-05-01', end: '2026-05-30' };

const setup = ( comparison = false ) => {
	window.history.replaceState( {}, '', '/wp-admin/admin.php?page=newspack-insights' );
	return renderHook( () => usePendingControls( { defaultRange: DEFAULT_RANGE, defaultComparison: comparison } ) );
};

describe( 'usePendingControls', () => {
	it( 'starts clean — not dirty, applied equals the default range', () => {
		const { result } = setup();
		expect( result.current.isDirty ).toBe( false );
		expect( result.current.appliedRange ).toEqual( DEFAULT_RANGE );
		expect( result.current.appliedPreviousRange ).toBeNull();
	} );

	it( 'a preset change is dirty and applies immediately (no modal)', () => {
		const { result } = setup();
		act( () => result.current.setPreset( 'last-7' ) );
		expect( result.current.isDirty ).toBe( true );
		expect( result.current.appliedRange.preset ).toBe( 'last-30' ); // not yet applied
		act( () => result.current.apply() );
		expect( result.current.confirmOpen ).toBe( false );
		expect( result.current.appliedRange.preset ).toBe( 'last-7' );
		expect( result.current.isDirty ).toBe( false );
	} );

	it( 'cancel restores the draft to the applied value', () => {
		const { result } = setup();
		act( () => result.current.setPreset( 'last-90' ) );
		act( () => result.current.cancel() );
		expect( result.current.isDirty ).toBe( false );
		expect( result.current.draftRange.preset ).toBe( 'last-30' );
	} );

	it( 'applying a custom range opens the modal; confirmApply commits', () => {
		const { result } = setup();
		act( () => result.current.setCustom( '2026-04-01', '2026-04-15' ) );
		act( () => result.current.apply() );
		expect( result.current.confirmOpen ).toBe( true );
		expect( result.current.appliedRange.preset ).toBe( 'last-30' ); // still not applied
		act( () => result.current.confirmApply() );
		expect( result.current.confirmOpen ).toBe( false );
		expect( result.current.appliedRange ).toEqual( { preset: 'custom', start: '2026-04-01', end: '2026-04-15' } );
	} );

	it( 'toggling compare opens the modal on apply; cancel discards', () => {
		const { result } = setup();
		act( () => result.current.setCompare( true ) );
		act( () => result.current.apply() );
		expect( result.current.confirmOpen ).toBe( true );
		act( () => result.current.cancel() );
		expect( result.current.confirmOpen ).toBe( false );
		expect( result.current.draftCompare ).toBe( false );
		expect( result.current.appliedPreviousRange ).toBeNull();
	} );

	it( 'commits a previousRange when compare is applied', () => {
		const { result } = setup();
		act( () => result.current.setCompare( true ) );
		act( () => result.current.confirmApply() );
		expect( result.current.appliedPreviousRange ).not.toBeNull();
	} );
} );
