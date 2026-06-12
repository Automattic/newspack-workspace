/**
 * Tests for CooldownNotice.
 */

/**
 * External dependencies
 */
import { render, screen, act, fireEvent } from '@testing-library/react';

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

	// `@wordpress/components` Notice renders both the visible content and a
	// hidden a11y-speak region with the same text, so plain `getByText`
	// queries match twice. Scope queries to the visible notice element.
	const getNotice = () => document.querySelector( '.newspack-insights__cooldown-notice' ) as HTMLElement | null;

	it( 'renders the MM:SS countdown when a cooldown is set', () => {
		seedCooldown( 'gates', '2026-06-10T00:05:00Z' );
		render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );
		const notice = getNotice();
		expect( notice ).not.toBeNull();
		expect( notice ).toHaveTextContent( /Please wait 05:00/ );
	} );

	it( 'unmounts and clears the slot cooldown when time runs out', () => {
		seedCooldown( 'gates', '2026-06-10T00:00:30Z' );
		render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );
		expect( getNotice() ).not.toBeNull();

		act( () => {
			jest.advanceTimersByTime( 31 * 1000 );
		} );

		expect( getNotice() ).toBeNull();
		const key = makeSlotKey( 'gates', range, null );
		expect( insightsCache.getSlot( key ).cooldownUntil ).toBeNull();
	} );

	it( 'renders a dismiss button that hides the notice for the current cooldown but re-shows on a new one', () => {
		seedCooldown( 'gates', '2026-06-10T00:05:00Z' );
		const { rerender } = render( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );

		// `@wordpress/components` Notice labels its dismiss control "Close".
		const dismissButton = screen.getByRole( 'button', { name: /close/i } );
		expect( dismissButton ).toBeInTheDocument();

		act( () => {
			fireEvent.click( dismissButton );
		} );

		expect( getNotice() ).toBeNull();

		// A fresh cooldown (different cooldownUntil) should re-show the notice.
		act( () => {
			seedCooldown( 'gates', '2026-06-10T00:10:00Z' );
		} );
		rerender( <CooldownNotice tab="gates" range={ range } previousRange={ null } /> );

		expect( getNotice() ).not.toBeNull();
	} );
} );
