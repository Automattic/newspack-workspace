/**
 * Tests for the Subscribers WindowedSection empty states (NPPD-1695): the
 * whole-section no_opportunity collapse, the per-card no-acquisition treatment
 * on New subscribers, the currency zeroFallback, and — the new wrinkle in 1695
 * vs 1696 — the GOOD-ZERO cases: zero churn renders normally (NOT an empty
 * state) and zero failed payments reframes positively (NPPD-1726).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import WindowedSection from './WindowedSection';
import type { SubscribersWindow } from '../../api/subscribers';
import type { DateRange } from '../../state/useDateRange';

const RANGE: DateRange = { preset: 'last-30', start: '2026-05-19', end: '2026-06-17' };

const makeWindow = ( over: Partial< SubscribersWindow > = {} ): SubscribersWindow => ( {
	window: { start: '2026-05-19', end: '2026-06-17' },
	new_subscribers: 14,
	winback_subscribers: 2,
	churned_subscribers: 3,
	revenue_gross: 5000,
	revenue_net: 4800,
	refund_rate: { value: 0.02, computable: true, denominator: 120 },
	failed_payment_retry_rate: { value: 0.8, computable: true, denominator: 10 },
	subscriptions_by_product: [],
	subscriptions_by_campaign: [],
	cancellation_reasons: [],
	has_window_activity: true,
	...over,
} );

/** Read a MetricCard's hero value text by its label (skips empty-note cards). */
const cardValueByLabel = ( container: HTMLElement, label: string ): string => {
	const labelEl = Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) ).find( el => el.textContent === label );
	const card = labelEl?.closest( '.newspack-insights__metric-card' );
	return card?.querySelector( '.newspack-insights__metric-card-value' )?.textContent ?? '';
};

describe( 'Subscribers WindowedSection empty states', () => {
	it( 'collapses to a no_opportunity EmptyMetricSection when the window saw no activity', () => {
		const current = makeWindow( {
			has_window_activity: false,
			new_subscribers: 0,
			churned_subscribers: 0,
			revenue_gross: 0,
			revenue_net: 0,
			refund_rate: { value: 0, computable: false, denominator: 0 },
			failed_payment_retry_rate: { value: 0, computable: false, denominator: 0 },
		} );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 0 } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		// Assert on the container — the Notice's speak() duplicates copy into a live-region.
		expect( container ).toHaveTextContent( 'No subscription activity in this timeframe' );
		expect( screen.queryByText( 'Churned subscribers' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Gross revenue' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the per-card no-acquisition state on New subscribers without collapsing the section', () => {
		const current = makeWindow( { new_subscribers: 0, churned_subscribers: 2 } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ makeWindow() } activeSubscribers={ 128 } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '128 active subscribers, but none new this timeframe' ) ).toBeInTheDocument();
		// The misleading period delta is suppressed even though `previous` has a real
		// prior count — a "↓ 100%" would misread an honest zero.
		const newCard = Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) )
			.find( el => el.textContent === 'New subscribers' )
			?.closest( '.newspack-insights__metric-card' );
		expect( newCard?.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
		// Real cards still render.
		expect( screen.getByText( 'Churned subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gross revenue' ) ).toBeInTheDocument();
	} );

	it( 'does NOT show the no-acquisition line when there are no active subscribers either', () => {
		const current = makeWindow( { new_subscribers: 0, churned_subscribers: 1 } );
		render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 0 } /> );

		expect( screen.queryByText( /existing subscribers are active/ ) ).not.toBeInTheDocument();
	} );

	it( 'GOOD ZERO: zero churn renders the card normally, NOT an empty state', () => {
		// Activity present (new subscribers + revenue), but zero churn this window.
		const current = makeWindow( { new_subscribers: 9, churned_subscribers: 0 } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ makeWindow() } activeSubscribers={ 200 } /> );

		// Section is NOT collapsed and the churn card is a real card showing 0.
		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Churned subscribers' ) ).toBeInTheDocument();
		expect( cardValueByLabel( container, 'Churned subscribers' ) ).toBe( '0' );
	} );

	it( 'GOOD ZERO: zero failed payments reframes positively (NPPD-1726), section intact', () => {
		const current = makeWindow( { failed_payment_retry_rate: { value: 0, computable: false, denominator: 0 } } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 200 } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'No failed payments in this timeframe.' ) ).toBeInTheDocument();
		// Not the old missing-data phrasing.
		expect( screen.queryByText( 'No payment retries in this timeframe.' ) ).not.toBeInTheDocument();
	} );

	it( 'GOOD ZERO: orders but zero refunds renders em-dash + "No refund requests" (NPPD-1698 D4)', () => {
		// Orders exist (gross > 0) but no refunds → refund rate is a computable 0%,
		// a good zero. Renders the em-dash + positive line, not a literal "0%".
		const current = makeWindow( { refund_rate: { value: 0, computable: true, denominator: 120 } } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 200 } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'No refund requests in this timeframe.' ) ).toBeInTheDocument();
		// Distinct from the no-orders message, and the hero is the em-dash, not "0%".
		expect( screen.queryByText( 'No subscription orders in this timeframe.' ) ).not.toBeInTheDocument();
		expect( cardValueByLabel( container, 'Refund rate' ) ).toBe( '—' );
	} );

	it( 'D5: Refund rate and Failed payment recovery good-zeros share the em-dash treatment', () => {
		// Churn-only window (no orders → refund not computable; no retries → failed-
		// payment good zero): both rate cards render the shared em-dash + line, not the
		// old bespoke empty-note markup.
		const current = makeWindow( {
			new_subscribers: 0,
			churned_subscribers: 4,
			revenue_gross: 0,
			revenue_net: 0,
			refund_rate: { value: 0, computable: false, denominator: 0 },
			failed_payment_retry_rate: { value: 0, computable: false, denominator: 0 },
		} );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 200 } /> );

		expect( cardValueByLabel( container, 'Refund rate' ) ).toBe( '—' );
		expect( cardValueByLabel( container, 'Failed payment recovery' ) ).toBe( '—' );
		expect( screen.getByText( 'No failed payments in this timeframe.' ) ).toBeInTheDocument();
		// The old bespoke empty-note element no longer renders for these cards.
		expect( container.querySelectorAll( '.newspack-insights__metric-card--empty' ).length ).toBe( 0 );
	} );

	it( 'shows the currency count fallback when there were no subscription orders', () => {
		// Churn-only window: activity is true (churn), but no completed orders →
		// Gross and Net show "No subscription orders…" instead of $0.00.
		const current = makeWindow( {
			new_subscribers: 0,
			churned_subscribers: 4,
			revenue_gross: 0,
			revenue_net: 0,
			refund_rate: { value: 0, computable: false, denominator: 0 },
		} );
		render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeSubscribers={ 50 } /> );

		// Gross + Net both fall back (shared MetricCard helper renders "…in this timeframe").
		expect( screen.getAllByText( 'No subscription orders in this timeframe' ).length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'renders every card when fully populated', () => {
		const { container } = render( <WindowedSection range={ RANGE } current={ makeWindow() } previous={ null } activeSubscribers={ 200 } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'New subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Churned subscribers' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Refund rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Failed payment recovery' ) ).toBeInTheDocument();
	} );
} );
