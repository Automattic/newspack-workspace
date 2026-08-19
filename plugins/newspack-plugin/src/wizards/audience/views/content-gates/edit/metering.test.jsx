/**
 * Regression test for NPPD-2056: the Free views field autocorrected an explicitly
 * entered 0 back to 1, so a publisher who wanted "no free views" silently granted
 * one. These tests pin the floor that replaced it – `min={ 0 }` on the control plus
 * the component's own clamp – including the negative and fractional entries that
 * floor is responsible for.
 *
 * The count field only renders for a gate keeping its own allowance, so these cases
 * pin `scope: 'gate'`. The shared-allowance cases are in the suite below.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Metering from './metering';

/**
 * Stateful harness mirroring the wizard: Metering is controlled, so the parent has
 * to feed committed values back through the `metering` prop for the field to settle.
 *
 * The optional spy records the payload the wizard would save, which is what the
 * assertions below check. Asserting the rendered input value alone is not enough:
 * the control keeps an internal draft, so the input reads "0" after typing 0 even
 * if `onChange` never fires at all.
 */
function MeteringHarness( { initialMetering, onChange = () => {}, siteCount, sitePeriod } ) {
	const [ metering, setMetering ] = useState( initialMetering );
	const handleChange = nextMetering => {
		setMetering( nextMetering );
		onChange( nextMetering );
	};
	return <Metering metering={ metering } onChange={ handleChange } siteCount={ siteCount } sitePeriod={ sitePeriod } />;
}

// The notice is a core `Notice`, which announces itself through `speak()`. That
// copies the message into the a11y live region, so an unscoped text query matches
// twice and the assertion is about the visible notice, not the announcement.
const IGNORE_SPOKEN = 'script, style, .a11y-speak-region';

const getFreeViewsInput = () => screen.getByRole( 'spinbutton', { name: 'Free views' } );

const typeAndBlur = ( input, value ) => {
	fireEvent.change( input, { target: { value } } );
	fireEvent.blur( input );
};

describe( 'Metering free views count', () => {
	it( 'keeps an explicitly entered 0 instead of autocorrecting it to 1', () => {
		const onChange = jest.fn();
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month', scope: 'gate' } } onChange={ onChange } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '0' );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { count: 0 } ) );
		expect( freeViewsInput.value ).toBe( '0' );
	} );

	it( 'floors a negative entry at 0', () => {
		const onChange = jest.fn();
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month', scope: 'gate' } } onChange={ onChange } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '-1' );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { count: 0 } ) );
		expect( freeViewsInput.value ).toBe( '0' );
	} );

	it( 'rounds a fractional entry to a whole number of views', () => {
		const onChange = jest.fn();
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month', scope: 'gate' } } onChange={ onChange } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '1.5' );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { count: 2 } ) );
		expect( freeViewsInput.value ).toBe( '2' );
	} );

	it( 'warns that a count of 0 is the same as turning metering off', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 0, period: 'month', scope: 'gate' } } /> );

		expect( screen.getByText( /the same behavior as turning Metering off/, { ignore: IGNORE_SPOKEN } ) ).toBeInTheDocument();
	} );

	it( 'treats a blanked field as 0', () => {
		const onChange = jest.fn();
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month', scope: 'gate' } } onChange={ onChange } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '' );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { count: 0 } ) );
		expect( freeViewsInput.value ).toBe( '0' );
	} );

	it( 'accepts a regular positive count', () => {
		const onChange = jest.fn();
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month', scope: 'gate' } } onChange={ onChange } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '3' );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { count: 3 } ) );
		expect( freeViewsInput.value ).toBe( '3' );
	} );
} );

describe( 'Metering scope', () => {
	it( 'shares the site allowance when a gate carries no scope, and reports it', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } siteCount={ 3 } sitePeriod="month" /> );

		expect( screen.getByRole( 'radio', { name: 'Site-wide' } ) ).toBeChecked();
		expect( screen.getByText( /3 free views a month, shared with every other gate\./ ) ).toBeInTheDocument();
	} );

	it( 'hides the per-gate count while the site allowance governs the gate', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } siteCount={ 3 } sitePeriod="month" /> );

		expect( screen.queryByRole( 'spinbutton', { name: 'Free views' } ) ).not.toBeInTheDocument();
	} );

	it( 'reveals the per-gate count once the gate opts out', () => {
		const onChange = jest.fn();
		render(
			<MeteringHarness
				initialMetering={ { enabled: true, count: 5, period: 'month' } }
				siteCount={ 3 }
				sitePeriod="month"
				onChange={ onChange }
			/>
		);

		fireEvent.click( screen.getByRole( 'radio', { name: 'This gate only' } ) );

		expect( onChange ).toHaveBeenLastCalledWith( expect.objectContaining( { scope: 'gate' } ) );
		expect( getFreeViewsInput() ).toBeInTheDocument();
	} );

	it( "warns against a site allowance of 0 without blaming the gate's own count", () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } siteCount={ 0 } sitePeriod="month" /> );

		expect( screen.getByText( /The site meter grants 0 free views/, { ignore: IGNORE_SPOKEN } ) ).toBeInTheDocument();
	} );
} );
