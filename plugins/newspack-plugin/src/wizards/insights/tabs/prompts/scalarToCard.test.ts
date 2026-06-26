/**
 * Tests for the Prompts tab-local scalarToCard adapter, focused on the
 * per-intent capability routing (NPPD-1720) and schema-drift data_missing
 * signalling. Precedence under test:
 * error > data_missing > not-capable > not-computable > normal value.
 */

/**
 * Internal dependencies
 */
import { scalarToMetricCardProps } from './scalarToCard';
import type { PromptsScalarMetric } from '../../api/prompts';

const scalar = ( overrides: Partial< PromptsScalarMetric > = {} ): PromptsScalarMetric => ( {
	state: 'populated',
	value: 0.42,
	computable: true,
	denominator: null,
	placeholder_type: 'rate',
	data_missing: false,
	...overrides,
} );

describe( 'prompts scalarToMetricCardProps — capability routing (NPPD-1720)', () => {
	it( 'routes has_capability:false to the supplied not-capable message', () => {
		const props = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: scalar( { has_capability: false } ),
			notCapableMessage: 'Add a Donate block.',
		} );
		expect( props.notCapableMessage ).toBe( 'Add a Donate block.' );
		// The value path is skipped entirely.
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'falls back to a generic message when the section omits copy', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { has_capability: false } ),
		} );
		expect( props.notCapableMessage ).toBe( 'Not measurable for your active prompts.' );
	} );

	it( 'lets the error treatment win over not-capable', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { state: 'error', has_capability: false, error_message: 'boom' } ),
			notCapableMessage: 'Add a Donate block.',
		} );
		expect( props.error ).toBe( 'boom' );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );

	it( 'renders the normal value when capable, ignoring any message', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { has_capability: true } ),
			notCapableMessage: 'unused',
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );

	it( 'treats absent has_capability as capable (matches server fail-open)', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar(),
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'notCapableMessage' );
	} );
} );

describe( 'prompts scalarToMetricCardProps — not-computable routing (NPPD-1704)', () => {
	// Capable (block exists) but the window produced no inputs: SAFE_DIVIDE NULL on
	// a zero denominator surfaces as a populated, non-computable scalar.
	const notComputable = ( overrides: Partial< PromptsScalarMetric > = {} ): PromptsScalarMetric =>
		scalar( { value: 0, computable: false, has_capability: true, ...overrides } );

	it( 'routes capable + non-computable to the supplied not-computable message', () => {
		const props = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: notComputable(),
			notComputableMessage: 'No donation-intent prompts viewed in this timeframe.',
		} );
		expect( props.notComputableMessage ).toBe( 'No donation-intent prompts viewed in this timeframe.' );
		// The value path is skipped entirely.
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'falls back to a generic message when the section omits copy', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: notComputable(),
		} );
		expect( props.notComputableMessage ).toBe( 'Not enough data to calculate.' );
	} );

	it( 'lets not-capable win over not-computable (add-the-block beats wait-for-data)', () => {
		// A scalar that is BOTH not-capable and (vacuously) non-computable: the more
		// actionable not-capable nudge must take the slot.
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { value: 0, computable: false, has_capability: false } ),
			notCapableMessage: 'Add a Donate block.',
			notComputableMessage: 'No donation-intent prompts viewed in this timeframe.',
		} );
		expect( props.notCapableMessage ).toBe( 'Add a Donate block.' );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );

	it( 'lets the error treatment win over not-computable', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: notComputable( { state: 'error', error_message: 'boom' } ),
			notComputableMessage: 'No donation-intent prompts viewed in this timeframe.',
		} );
		expect( props.error ).toBe( 'boom' );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );

	it( 'renders the normal value when capable and computable, ignoring any message', () => {
		const props = scalarToMetricCardProps( {
			label: 'x',
			description: 'd',
			current: scalar( { has_capability: true, computable: true } ),
			notComputableMessage: 'unused',
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
	} );

	it( 'does NOT route non-conversion scalars (no has_capability) to not-computable', () => {
		// Exposure / engagement scalars carry no capability flag; a non-computable
		// zero there must fall through to the normal value path, never the em-dash —
		// the gate is scoped to conversion-tied metrics only.
		const props = scalarToMetricCardProps( {
			label: 'Click-Through Rate',
			description: 'd',
			current: scalar( { value: 0, computable: false } ),
		} );
		expect( props ).not.toHaveProperty( 'notComputableMessage' );
		expect( props.value ).toBe( 0 );
	} );
} );

describe( 'prompts scalarToMetricCardProps — data_missing routing', () => {
	it( 'returns dataMissing:true (no value) when populated and data_missing is true', () => {
		const props = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: scalar( { data_missing: true } ),
		} );
		expect( props.dataMissing ).toBe( true );
		expect( props ).not.toHaveProperty( 'value' );
	} );

	it( 'returns the normal value mapping when populated and data_missing is false', () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const props: any = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: scalar( { data_missing: false } ),
		} );
		expect( props.value ).toBe( 0.42 );
		expect( props ).not.toHaveProperty( 'dataMissing' );
	} );

	it( 'lets the error treatment win over data_missing', () => {
		const props = scalarToMetricCardProps( {
			label: 'Donation Conversion (Direct)',
			description: 'd',
			current: scalar( { state: 'error', data_missing: true, error_message: 'BQ down' } ),
		} );
		expect( props.error ).toBe( 'BQ down' );
		expect( props ).not.toHaveProperty( 'dataMissing' );
		expect( props ).not.toHaveProperty( 'value' );
	} );
} );
