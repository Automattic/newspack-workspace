/**
 * Tests for FreeReaderConversionSection empty states (NPPD-1702): the THREE-way
 * field-presence detection that is the point of this ticket —
 *   - fields absent (hub not deployed) → percentages, NO empty state (degrade)
 *   - fields present, impressions 0     → no_opportunity
 *   - fields present, registrations 0   → no_conversions (with {N})
 *   - fields present, normal data       → scorecards + per-card "0 of N" fallback
 * plus the errored-scalar fall-through (a zero total behind an error must not
 * masquerade as a genuine empty state).
 *
 * `.test.tsx` harness gap from NPPD-1683 still applies, so this may not run in CI
 * yet; written to the sibling PaidReaderConversionSection.test.tsx convention.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import FreeReaderConversionSection from './FreeReaderConversionSection';
import type { GatesScalarMetric, GatesWindow } from '../../api/gates';

const scalar = ( over: Partial< GatesScalarMetric > = {} ): GatesScalarMetric => ( {
	state: 'populated',
	value: 0,
	computable: true,
	denominator: null,
	numerator: null,
	placeholder_type: 'rate',
	data_missing: false,
	...over,
} );

const makeWindow = ( over: Partial< GatesWindow > = {} ): GatesWindow => ( {
	window: { start: '2026-03-01', end: '2026-03-31' },
	total_gate_impressions: scalar( { placeholder_type: 'count', value: 100 } ),
	unique_readers_reached: scalar( { placeholder_type: 'count' } ),
	avg_exposures_per_reader: scalar( { placeholder_type: 'decimal' } ),
	sessions_with_gate: scalar(),
	regwall_conversion_direct: scalar(),
	regwall_conversion_influenced_7d: scalar(),
	paywall_conversion_direct: scalar(),
	paywall_conversion_influenced_14d: scalar(),
	total_paywall_revenue_direct: scalar( { placeholder_type: 'currency' } ),
	avg_revenue_per_paywall_conversion: scalar( { placeholder_type: 'currency' } ),
	paywall_impressions_total: 0,
	paywall_conversions_total: 0,
	// Default: hub count fields ABSENT (null) — the pre-deploy production state.
	registration_impressions_total: null,
	registrations_total: null,
	conversion_funnel: { state: 'empty', stages: [] },
	exposures_distribution: { state: 'empty', buckets: [] },
	performance_by_gate: { state: 'empty', rows: [] },
	...over,
} );

describe( 'FreeReaderConversionSection empty states (NPPD-1702)', () => {
	it( 'degrades to the percentage scorecards (NO empty state) when the hub count fields are absent', () => {
		// The crux: fields null even though the regwall rates are a non-computable
		// zero. Absent is NOT zero — today's render must be preserved exactly.
		const current = makeWindow( {
			registration_impressions_total: null,
			registrations_total: null,
			regwall_conversion_direct: scalar( { value: 0, computable: false } ),
			regwall_conversion_influenced_7d: scalar( { value: 0, computable: false } ),
		} );
		const { container } = render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Registered access gate Conversion (Direct)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Registered access gate Conversion (Influenced, 7d)' ) ).toBeInTheDocument();
	} );

	it( 'renders no_opportunity when the fields are present and impressions === 0', () => {
		const current = makeWindow( { registration_impressions_total: 0, registrations_total: 0 } );
		const { container } = render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		// Body asserted on the container — the Notice's speak() duplicates it into a
		// global live-region, so a screen-level text query would match twice.
		expect( container ).toHaveTextContent( 'No registration gate impressions in this timeframe' );
		expect( screen.queryByText( 'Registered access gate Conversion (Direct)' ) ).not.toBeInTheDocument();
	} );

	it( 'renders no_conversions with the impressions count interpolated when impressions > 0 and registrations === 0', () => {
		const current = makeWindow( { registration_impressions_total: 14000, registrations_total: 0 } );
		const { container } = render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_conversions"]' ) ).toBeInTheDocument();
		// 14000 → "14,000" via the shared formatNumber.
		expect( container ).toHaveTextContent( 'Your registration gates reached 14,000 readers' );
		// 7-day attribution window in the Free copy (NOT 14 — that's the Paid side).
		expect( container ).toHaveTextContent( 'within the 7-day attribution window' );
		expect( screen.queryByText( 'Registered access gate Conversion (Direct)' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the two scorecards (no empty state) when the section has data', () => {
		const current = makeWindow( {
			registration_impressions_total: 14000,
			registrations_total: 994,
			regwall_conversion_direct: scalar( { value: 0.071, numerator: 994, denominator: 14000 } ),
			regwall_conversion_influenced_7d: scalar( { value: 0.123, numerator: 1476, denominator: 12000 } ),
		} );
		const { container } = render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Registered access gate Conversion (Direct)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Registered access gate Conversion (Influenced, 7d)' ) ).toBeInTheDocument();
	} );

	it( 'applies the per-card count fallback inside a normal section render', () => {
		// Section has data (Influenced registered some) so the grid renders, but the
		// Direct card is 0 of 14,000 → exercises the "0 of N" rate fallback.
		const current = makeWindow( {
			registration_impressions_total: 14000,
			registrations_total: 1476,
			regwall_conversion_direct: scalar( { value: 0, computable: true, numerator: 0, denominator: 14000 } ),
			regwall_conversion_influenced_7d: scalar( { value: 0.123, numerator: 1476, denominator: 12000 } ),
		} );
		render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( screen.getByText( '0 of 14,000' ) ).toBeInTheDocument();
	} );

	it( 'does NOT show an empty state when a regwall scalar errored — renders the scorecards instead', () => {
		// Even with impressions present at 0 (which would otherwise be no_opportunity),
		// an errored Direct query must fall through to the scorecards so the errored
		// card surfaces its own error treatment rather than a misleading empty state.
		const current = makeWindow( {
			registration_impressions_total: 0,
			registrations_total: 0,
			regwall_conversion_direct: scalar( { state: 'error', computable: false, error_message: 'BQ down' } ),
		} );
		const { container } = render( <FreeReaderConversionSection current={ current } previous={ null } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( container ).not.toHaveTextContent( 'No registration gate impressions in this timeframe' );
		expect( screen.getByText( 'Registered access gate Conversion (Direct)' ) ).toBeInTheDocument();
	} );
} );
