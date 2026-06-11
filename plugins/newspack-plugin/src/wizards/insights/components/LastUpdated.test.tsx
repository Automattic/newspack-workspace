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
} );
