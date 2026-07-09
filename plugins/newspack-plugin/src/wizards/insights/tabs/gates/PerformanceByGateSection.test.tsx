/**
 * Tests for PerformanceByGateSection (NPPD-1686): the per-gate paid access gate columns
 * surface true conversions (count + rate), with an em-dash for a non-paywall-capable
 * (regwall-only) gate — replacing the old engagement-intent attempt columns.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PerformanceByGateSection from './PerformanceByGateSection';
import type { GatesPerformanceRow, GatesPerformanceTable } from '../../api/gates';

const row = ( over: Partial< GatesPerformanceRow > = {} ): GatesPerformanceRow => ( {
	gate_post_id: 1,
	gate_name: 'Gate',
	impressions: 1000,
	unique_viewers: 800,
	registrations: 0,
	regwall_conversion_rate: null,
	paywall_conversions: null,
	paywall_conversion_rate: null,
	...over,
} );

const table = ( rows: GatesPerformanceRow[] ): GatesPerformanceTable => ( {
	state: 'populated',
	rows,
} );

describe( 'PerformanceByGateSection paid access gate conversion columns', () => {
	it( 'renders the conversion column headers, not the old attempt columns', () => {
		// The read-only DataViews table only renders its headers when there are
		// rows (an empty table shows the shared empty-state message instead), so
		// render one row to assert on the header set.
		render( <PerformanceByGateSection data={ table( [ row() ] ) } /> );
		expect( screen.getByText( 'Paid access gate conversions' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Paid access gate conversion rate' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Paid access gate attempts' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Paid access gate attempt rate' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the count + rate for a paywall-capable gate', () => {
		render(
			<PerformanceByGateSection
				data={ table( [
					row( {
						gate_post_id: 77,
						gate_name: 'Member paywall',
						impressions: 5000,
						paywall_conversions: 3,
						paywall_conversion_rate: 0.01,
					} ),
				] ) }
			/>
		);
		expect( screen.getByText( '3' ) ).toBeInTheDocument();
		expect( screen.getByText( '1%' ) ).toBeInTheDocument();
	} );

	it( 'renders an em-dash for a regwall-only gate (null paid access gate columns)', () => {
		render(
			<PerformanceByGateSection
				data={ table( [
					row( {
						gate_post_id: 88,
						gate_name: 'Registered access gate only',
						impressions: 3000,
						registrations: 150,
						regwall_conversion_rate: 0.07,
						paywall_conversions: null,
						paywall_conversion_rate: null,
					} ),
				] ) }
			/>
		);
		// Exactly the two paid access gate cells render the N/A em-dash (regwall rate is 7%).
		expect( screen.getAllByText( '—' ) ).toHaveLength( 2 );
	} );

	it( 'renders a real 0 (not em-dash) for a capable gate that converted nobody', () => {
		render(
			<PerformanceByGateSection
				data={ table( [
					row( {
						gate_post_id: 77,
						gate_name: 'Member paywall',
						impressions: 5000,
						registrations: 12, // distinct value so "0" uniquely identifies paywall_conversions
						regwall_conversion_rate: 0.07, // non-null so the only em-dash candidates are paid access gate cells
						paywall_conversions: 0,
						paywall_conversion_rate: 0,
					} ),
				] ) }
			/>
		);
		expect( screen.getByText( '0' ) ).toBeInTheDocument();
		expect( screen.getByText( '0%' ) ).toBeInTheDocument();
		expect( screen.queryByText( '—' ) ).not.toBeInTheDocument();
	} );
} );
