/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from 'react';

/**
 * Internal dependencies
 */
import Metering from './metering';

/**
 * Stateful harness: NumberControl only commits values through a controlled
 * round-trip, so the component is rendered with live state like in the wizard.
 */
function MeteringHarness( { initialMetering } ) {
	const [ metering, setMetering ] = useState( initialMetering );
	return <Metering metering={ metering } onChange={ setMetering } />;
}

const getFreeViewsInput = () => screen.getByRole( 'spinbutton', { name: 'Free views' } );

const typeAndBlur = ( input, value ) => {
	fireEvent.change( input, { target: { value } } );
	fireEvent.blur( input );
};

describe( 'Metering free views count', () => {
	it( 'keeps an explicitly entered 0 instead of autocorrecting it to 1', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '0' );

		expect( freeViewsInput.value ).toBe( '0' );
	} );

	it( 'shows the gated-for-all warning when the count is 0', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 0, period: 'month' } } /> );

		expect( screen.getByText( /Content will be gated for all readers/ ) ).toBeInTheDocument();
	} );

	it( 'treats a blanked field as 0', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '' );

		expect( freeViewsInput.value ).toBe( '0' );
	} );

	it( 'accepts a regular positive count', () => {
		render( <MeteringHarness initialMetering={ { enabled: true, count: 5, period: 'month' } } /> );

		const freeViewsInput = getFreeViewsInput();
		typeAndBlur( freeViewsInput, '3' );

		expect( freeViewsInput.value ).toBe( '3' );
	} );
} );
