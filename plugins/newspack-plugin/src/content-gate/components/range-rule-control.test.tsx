/**
 * External dependencies
 */
import { act, render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import RangeRuleControl, { ANNOUNCE_DELAY } from './range-rule-control';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const CONFIG = { name: 'Donation total', is_range: true, empty_grants_access: true, requires_value: true };

const renderControl = ( value: unknown, onChange = jest.fn() ) => {
	render( <RangeRuleControl config={ CONFIG } value={ value } onChange={ onChange } /> );
	return onChange;
};

describe( 'RangeRuleControl', () => {
	beforeEach( () => {
		( speak as jest.Mock ).mockClear();
	} );

	it( 'stores each typed bound as typed, and drops a cleared one', () => {
		const onChange = renderControl( { max: 100 } );

		fireEvent.change( screen.getByLabelText( 'Minimum' ), { target: { value: '1.5' } } );
		expect( onChange ).toHaveBeenLastCalledWith( { min: '1.5', max: 100 } );

		fireEvent.change( screen.getByLabelText( 'Maximum' ), { target: { value: '' } } );
		expect( onChange ).toHaveBeenLastCalledWith( {} );
	} );

	it( 'keeps a typo in one bound rather than dropping it, so the range is not widened', () => {
		// Dropped, "50O" next to a maximum of 100 would save as "at most 100".
		const onChange = renderControl( { min: 50, max: 100 } );

		fireEvent.change( screen.getByLabelText( 'Minimum' ), { target: { value: '50O' } } );

		expect( onChange ).toHaveBeenLastCalledWith( { min: '50O', max: 100 } );
	} );

	describe( 'announcements', () => {
		beforeEach( () => jest.useFakeTimers() );
		afterEach( () => jest.useRealTimers() );

		it( 'announces a notice raised by what the editor typed, but not the one present on load', () => {
			const { rerender } = render( <RangeRuleControl config={ CONFIG } value={ {} } onChange={ jest.fn() } /> );
			act( () => jest.advanceTimersByTime( ANNOUNCE_DELAY ) );
			expect( speak ).not.toHaveBeenCalled();

			rerender( <RangeRuleControl config={ CONFIG } value={ { min: 100, max: 50 } } onChange={ jest.fn() } /> );
			act( () => jest.advanceTimersByTime( ANNOUNCE_DELAY ) );

			const notice = screen.getByRole( 'note' );
			expect( speak ).toHaveBeenLastCalledWith( notice.textContent, 'polite' );
			expect( screen.getByLabelText( 'Minimum' ) ).toHaveAttribute( 'aria-describedby', notice.id );
		} );

		it( 'does not announce a state the next keystroke clears', () => {
			// Typing a maximum of 100 against a minimum of 50 passes through 1, which
			// reads as inverted for a moment.
			const { rerender } = render( <RangeRuleControl config={ CONFIG } value={ { min: 50 } } onChange={ jest.fn() } /> );
			rerender( <RangeRuleControl config={ CONFIG } value={ { min: 50, max: '1' } } onChange={ jest.fn() } /> );
			rerender( <RangeRuleControl config={ CONFIG } value={ { min: 50, max: '100' } } onChange={ jest.fn() } /> );
			act( () => jest.advanceTimersByTime( ANNOUNCE_DELAY ) );

			expect( speak ).not.toHaveBeenCalled();
		} );
	} );

	it( 'keeps the same inputs while the notice comes and goes, so typing is not cut off', () => {
		// The first keystroke clears the "not set" notice. Were the inputs rebuilt
		// then, focus would drop and the rest of what the editor typed would be lost.
		const { rerender } = render( <RangeRuleControl config={ CONFIG } value={ {} } onChange={ jest.fn() } /> );
		const minimum = screen.getByLabelText( 'Minimum' );
		expect( screen.getByRole( 'note' ) ).toBeInTheDocument();

		rerender( <RangeRuleControl config={ CONFIG } value={ { min: 1 } } onChange={ jest.fn() } /> );

		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Minimum' ) ).toBe( minimum );
	} );

	it( 'shows the stored bounds, including a bound of 0', () => {
		renderControl( { min: 0, max: 10 } );

		expect( screen.getByLabelText( 'Minimum' ) ).toHaveValue( '0' );
		expect( screen.getByLabelText( 'Maximum' ) ).toHaveValue( '10' );
		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );

	it( 'reads a bound as a number only where the server does', () => {
		// `Number()` reads hex, binary and octal; PHP's `is_numeric()` refuses them.
		renderControl( { min: '0x10' } );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( 'The minimum and maximum must be numbers.' );
	} );

	it( 'names text saved before the min/max control, which the rule denies on', () => {
		renderControl( '50' );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( 'The saved value “50” is not a minimum or maximum, so this rule grants no access.' );
		expect( screen.getByLabelText( 'Minimum' ) ).toHaveValue( '' );
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
