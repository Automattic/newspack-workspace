/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import RangeRuleControl from './range-rule-control';

const renderControl = ( value: unknown, onChange = jest.fn() ) => {
	render( <RangeRuleControl label="Donation total" value={ value } onChange={ onChange } /> );
	return onChange;
};

describe( 'RangeRuleControl', () => {
	it( 'stores each typed bound as a number, and drops a cleared one', () => {
		const onChange = renderControl( { max: 100 } );

		fireEvent.change( screen.getByLabelText( 'Minimum' ), { target: { value: '50' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { min: 50, max: 100 } );

		fireEvent.change( screen.getByLabelText( 'Maximum' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( {} );
	} );

	it( 'keeps the same inputs while the notice comes and goes, so typing is not cut off', () => {
		// The first keystroke clears the "not set" notice. Were the inputs rebuilt
		// then, focus would drop and the rest of what the editor typed would be lost.
		const { rerender } = render( <RangeRuleControl label="Donation total" value={ {} } onChange={ jest.fn() } /> );
		const minimum = screen.getByLabelText( 'Minimum' );
		expect( screen.getByRole( 'note' ) ).toBeInTheDocument();

		rerender( <RangeRuleControl label="Donation total" value={ { min: 1 } } onChange={ jest.fn() } /> );

		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Minimum' ) ).toBe( minimum );
	} );

	it( 'shows the stored bounds, including a bound of 0', () => {
		renderControl( { min: 0, max: 10 } );

		expect( screen.getByLabelText( 'Minimum' ) ).toHaveValue( 0 );
		expect( screen.getByLabelText( 'Maximum' ) ).toHaveValue( 10 );
		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );

	it( 'names text saved before the min/max control, which the rule denies on', () => {
		renderControl( '50' );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( 'The saved value “50” is not a minimum or maximum, so this rule grants no access.' );
		expect( screen.getByLabelText( 'Minimum' ) ).toHaveValue( null );
	} );

	it( 'says an unset range admits every reader with a number', () => {
		renderControl( {} );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( 'grants access to every reader with a number in this field' );
	} );

	it( 'says an inverted range matches no reader', () => {
		renderControl( { min: 100, max: 50 } );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( 'The minimum is above the maximum, so this rule matches no reader.' );
	} );
} );
