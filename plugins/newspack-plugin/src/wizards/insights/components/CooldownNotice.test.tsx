/**
 * Tests for CooldownNotice.
 */

/**
 * External dependencies
 */
import { render, screen, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CooldownNotice from './CooldownNotice';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import type { DateRange } from '../state/useDateRange';

const range = { start: '2026-01-01', end: '2026-01-31', preset: 'last-30' } as unknown as DateRange;

beforeEach( () => {
	jest.useFakeTimers();
	jest.setSystemTime( new Date( '2026-06-10T00:00:00Z' ) );
} );

afterEach( () => {
	jest.useRealTimers();
	( insightsCache as unknown as { reset: () => void } ).reset();
} );

const seedCooldown = ( tab: string, until: string | null ) => {
	const key = makeSlotKey( tab, range, null );
	insightsCache.getSlot( key );
	insightsCache.setCooldown( key, until );
};

describe( 'CooldownNotice', () => {
	it( 'renders nothing when no cooldown is set', () => {
		const { container } = render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders the MM:SS countdown when a cooldown is set', () => {
		seedCooldown( 'gates', '2026-06-10T00:05:00Z' );
		render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );
		expect( screen.getByText( /Please wait 05:00/ ) ).toBeInTheDocument();
	} );

	it( 'unmounts and clears the slot cooldown when time runs out', () => {
		seedCooldown( 'gates', '2026-06-10T00:00:30Z' );
		render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );
		expect( screen.getByText( /Please wait/ ) ).toBeInTheDocument();

		act( () => {
			jest.advanceTimersByTime( 31 * 1000 );
		} );

		expect( screen.queryByText( /Please wait/ ) ).not.toBeInTheDocument();
		const key = makeSlotKey( 'gates', range, null );
		expect( insightsCache.getSlot( key ).cooldownUntil ).toBeNull();
	} );

	it( 'does not render a dismiss button (newspack Notice has no dismiss affordance)', () => {
		seedCooldown( 'gates', '2026-06-10T00:05:00Z' );
		render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );

		expect( screen.queryByRole( 'button', { name: /dismiss/i } ) ).not.toBeInTheDocument();
	} );
} );
