/**
 * Tests for the rebuilt LastUpdated component.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import LastUpdated from './LastUpdated';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import { RefreshRegistryProvider, useRegisterRefresh } from '../state/refreshRegistry';
import type { DateRange } from '../state/useDateRange';

const range = { start: '2026-01-01', end: '2026-01-31', preset: 'last-30' } as unknown as DateRange;

const seedSlot = ( tab: string, patch: Record< string, unknown > ) => {
	const key = makeSlotKey( tab, range, null );
	const slot = insightsCache.getSlot( key );
	Object.assign( slot as Record< string, unknown >, patch );
};

const Harness = ( { tab, onRefresh }: { tab: string; onRefresh: () => void } ) => {
	useRegisterRefresh( tab, onRefresh );
	return null;
};

afterEach( () => {
	( insightsCache as unknown as { reset: () => void } ).reset();
} );

describe( 'LastUpdated', () => {
	it( 'renders nothing when no slot has computed yet', () => {
		const { container } = render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders the timestamp once the slot has data', () => {
		seedSlot( 'engagement', { status: 'success', computedAt: '2026-06-10T18:42:13Z' } );
		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		expect( screen.getByText( /Last updated:/ ) ).toBeInTheDocument();
	} );

	it( 'fires the registered refetch on Refresh now', () => {
		const refetch = jest.fn();
		seedSlot( 'engagement', { status: 'success', computedAt: '2026-06-10T18:42:13Z' } );

		render(
			<RefreshRegistryProvider>
				<Harness tab="engagement" onRefresh={ refetch } />
				<LastUpdated tab="engagement" range={ range } previousRange={ null } />
			</RefreshRegistryProvider>
		);

		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: /refresh now/i } ) );
		expect( refetch ).toHaveBeenCalled();
	} );

	it( 'disables Refresh now during an active cooldown', () => {
		const future = new Date( Date.now() + 5 * 60 * 1000 ).toISOString();
		seedSlot( 'engagement', { status: 'success', computedAt: '2026-06-10T18:42:13Z', cooldownUntil: future } );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		const item = screen.getByRole( 'menuitem', { name: /refresh now/i } );
		expect( item ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'prints with a tab+range filename on Print / Save as PDF', () => {
		const printSpy = jest.spyOn( window, 'print' ).mockImplementation( () => undefined );
		seedSlot( 'engagement', { status: 'success', computedAt: '2026-06-10T18:42:13Z' } );

		let titleAtPrint = '';
		printSpy.mockImplementation( () => {
			titleAtPrint = document.title;
		} );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: /save as pdf/i } ) );

		expect( printSpy ).toHaveBeenCalledTimes( 1 );
		expect( titleAtPrint ).toBe( 'engagement-2026-01-01_to_2026-01-31' );
		printSpy.mockRestore();
	} );

	it( 'disables Print / Save as PDF while the slot is loading', () => {
		seedSlot( 'engagement', { status: 'loading', computedAt: '2026-06-10T18:42:13Z' } );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		const item = screen.getByRole( 'menuitem', { name: /save as pdf/i } );
		expect( item ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'exports JSON with a computedAt+tab+preset filename on Export JSON', () => {
		// jsdom does not implement URL.createObjectURL / URL.revokeObjectURL, so
		// jest.spyOn would throw (can't spy on undefined). Assign directly and
		// save/restore to prevent cross-suite pollution.
		const origCreateObjectURL = URL.createObjectURL;
		const origRevokeObjectURL = URL.revokeObjectURL;
		let downloadName = '';
		let capturedBlob: Blob | null = null;
		const urlMock = global.URL as unknown as { createObjectURL: typeof URL.createObjectURL; revokeObjectURL: typeof URL.revokeObjectURL };
		urlMock.createObjectURL = jest.fn( ( blob: Blob ) => {
			capturedBlob = blob;
			return 'blob:mock';
		} );
		urlMock.revokeObjectURL = jest.fn( () => undefined );
		const clickSpy = jest.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation( function ( this: HTMLAnchorElement ) {
			downloadName = this.download;
		} );

		seedSlot( 'engagement', {
			status: 'success',
			computedAt: '2026-06-10T18:42:13Z',
			data: { current: { views: 5 } },
		} );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: /export json/i } ) );

		expect( clickSpy ).toHaveBeenCalledTimes( 1 );
		expect( downloadName ).toBe( '2026-06-10-engagement-last-30-days.json' );
		expect( capturedBlob ).not.toBeNull();

		const urlRestoreMock = global.URL as unknown as { createObjectURL: typeof URL.createObjectURL; revokeObjectURL: typeof URL.revokeObjectURL };
		urlRestoreMock.createObjectURL = origCreateObjectURL;
		urlRestoreMock.revokeObjectURL = origRevokeObjectURL;
		clickSpy.mockRestore();
	} );

	it( 'disables Export JSON while the slot is loading', () => {
		seedSlot( 'engagement', { status: 'loading', computedAt: '2026-06-10T18:42:13Z', data: { a: 1 } } );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		const item = screen.getByRole( 'menuitem', { name: /export json/i } );
		expect( item ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'disables Export JSON when the slot has no data', () => {
		seedSlot( 'engagement', { status: 'success', computedAt: '2026-06-10T18:42:13Z', data: null } );

		render( <LastUpdated tab="engagement" range={ range } previousRange={ null } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /options/i } ) );
		const item = screen.getByRole( 'menuitem', { name: /export json/i } );
		expect( item ).toHaveAttribute( 'aria-disabled', 'true' );
	} );
} );
