/**
 * Tests for the Gates tab-local scalarToCard adapter. Precedence under test:
 * error > data_missing > normal value.
 */

/**
 * Internal dependencies
 */
import { scalarToMetricCardProps } from './scalarToCard';
import type { GatesScalarMetric } from '../../api/gates';

const scalar = ( overrides: Partial< GatesScalarMetric > = {} ): GatesScalarMetric => ( {
	state: 'populated',
	value: 0.42,
	computable: true,
	denominator: null,
	numerator: null,
	placeholder_type: 'rate',
	data_missing: false,
	...overrides,
} );

describe( 'gates scalarToMetricCardProps — data_missing routing', () => {
	it( 'returns dataMissing:true (no value) when populated and data_missing is true', () => {
		const props = scalarToMetricCardProps( {
			label: 'Paid access gate Conversion (Direct)',
			description: 'd',
			current: scalar( { data_missing: true } ),
		} );
		expect( props.dataMissing ).toBe( true );
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'returns the normal value mapping when populated and data_missing is false', () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const props: any = scalarToMetricCardProps( {
			label: 'Paid access gate Conversion (Direct)',
			description: 'd',
			current: scalar( { data_missing: false } ),
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'dataMissing' );
	} );

	it( 'lets the error treatment win over data_missing', () => {
		const props = scalarToMetricCardProps( {
			label: 'Paid access gate Conversion (Direct)',
			description: 'd',
			current: scalar( { state: 'error', data_missing: true, error_message: 'BQ down' } ),
		} );
		expect( props.error ).toBe( 'BQ down' );
		expect( props ).not.toHaveProperty( 'dataMissing' );
		expect( props ).not.toHaveProperty( 'value' );
	} );
} );
