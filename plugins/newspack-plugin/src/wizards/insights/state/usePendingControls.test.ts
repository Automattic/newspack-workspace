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

	it( 'writes the URL only on apply — an un-applied edit leaves it untouched', () => {
		const { result } = setup();
		// No commit on mount, so no range param is written yet.
		expect( new URLSearchParams( window.location.search ).get( 'range' ) ).toBeNull();
		// Editing the draft must NOT touch the URL — persistence is commit-time only.
		act( () => result.current.setPreset( 'last-7' ) );
		expect( new URLSearchParams( window.location.search ).get( 'range' ) ).toBeNull();
		// Applying commits the range (and compare) to the URL.
		act( () => result.current.apply() );
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'range' ) ).toBe( 'last-7' );
		expect( params.get( 'compare' ) ).toBe( '0' );
	} );

	it( 'writes custom range params to the URL on confirmApply', () => {
		const { result } = setup();
		act( () => result.current.setCustom( '2026-04-01', '2026-04-15' ) );
		// Still un-applied — the URL stays untouched even for a custom edit.
		expect( new URLSearchParams( window.location.search ).get( 'range' ) ).toBeNull();
		act( () => result.current.apply() ); // opens the confirmation modal for custom
		act( () => result.current.confirmApply() ); // commits
		const params = new URLSearchParams( window.location.search );
		expect( params.get( 'range' ) ).toBe( 'custom' );
		expect( params.get( 'start' ) ).toBe( '2026-04-01' );
		expect( params.get( 'end' ) ).toBe( '2026-04-15' );
	} );
} );
