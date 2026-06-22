/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import MatchLogicToggle from './match-logic-toggle';

describe( 'MatchLogicToggle', () => {
	it( 'is disabled with fewer than 2 non-specific rules', () => {
		render( <MatchLogicToggle value="all" ruleCount={ 1 } onChange={ () => {} } /> );
		expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();
	} );

	it( 'is enabled with 2+ non-specific rules and toggles to "any"', () => {
		const onChange = jest.fn();
		render( <MatchLogicToggle value="all" ruleCount={ 2 } onChange={ onChange } /> );
		const toggle = screen.getByRole( 'checkbox' );
		expect( toggle ).not.toBeDisabled();
		fireEvent.click( toggle );
		expect( onChange ).toHaveBeenCalledWith( 'any' );
	} );

	it( 'reflects "any" as checked and toggles back to "all"', () => {
		const onChange = jest.fn();
		render( <MatchLogicToggle value="any" ruleCount={ 2 } onChange={ onChange } /> );
		fireEvent.click( screen.getByRole( 'checkbox' ) );
		expect( onChange ).toHaveBeenCalledWith( 'all' );
	} );
} );
