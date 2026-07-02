/**
 * Tests for the Conversion tab-local scalarToCard adapter, focused on the
 * incalculable-rate routing added in NEWS-2593: a percentage/rate metric with
 * no population (0/0 → `computable: false`) renders MetricCard's em-dash
 * not-computable treatment instead of a misleading `0%`. Precedence under test:
 * error > data_missing > not-computable > normal value. Count/currency/decimal
 * scalars are intentionally out of scope (only percentages get the em-dash).
 */

/**
 * Internal dependencies
 */
import { scalarToMetricCardProps } from './scalarToCard';
import type { ConversionScalarMetric } from '../../api/conversion';

const scalar = ( overrides: Partial< ConversionScalarMetric > = {} ): ConversionScalarMetric => ( {
	state: 'populated',
	value: 0,
	computable: true,
	denominator: null,
	placeholder_type: 'rate',
	data_missing: false,
	...overrides,
} );

describe( 'conversion scalarToMetricCardProps — incalculable rate routing (NEWS-2593)', () => {
	it( 'routes a non-computable rate (0/0, no population) to the em-dash treatment', () => {
		const props = scalarToMetricCardProps( {
			label: 'Influenced Donation Rate',
			description: 'd',
			current: scalar( { computable: false, denominator: 0 } ),
		} );
		expect( props.notComputableMessage ).toBe( 'Not enough data to calculate.' );
		// The value path is skipped entirely — no bare 0% hero.
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'lets the section override the not-computable copy', () => {
		const props = scalarToMetricCardProps( {
			label: 'Influenced Donation Rate',
			description: 'd',
			current: scalar( { computable: false, denominator: 0 } ),
			notComputableMessage: 'No donations in this timeframe.',
		} );
		expect( props.notComputableMessage ).toBe( 'No donations in this timeframe.' );
	} );

	it( 'leaves a computable rate on the normal value path', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { value: 0.25, computable: true } ),
		} );
		expect( props.value ).toBe( 0.25 );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );

	it( 'does NOT em-dash a non-rate scalar even when non-computable (scope is percentages)', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { placeholder_type: 'count', value: 0, computable: false } ),
		} );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
		expect( props.value ).toBe( 0 );
	} );

	it( 'lets the error treatment win over not-computable', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { state: 'error', computable: false, error_message: 'boom' } ),
		} );
		expect( props.error ).toBe( 'boom' );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );

	it( 'lets data_missing win over not-computable', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { computable: false, data_missing: true } ),
		} );
		expect( props.dataMissing ).toBe( true );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );
} );
