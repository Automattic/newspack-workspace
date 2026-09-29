/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import AutocompleteTokenField from './autocomplete-tokenfield';

const noop = () => {};

describe( 'AutocompleteTokenField when the saved titles cannot be loaded', () => {
	let errorSpy;

	beforeEach( () => {
		errorSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		errorSpy.mockRestore();
	} );

	const renderField = () => {
		const onChange = jest.fn();
		render(
			<AutocompleteTokenField
				tokens={ [ 11, 22 ] }
				onChange={ onChange }
				fetchSuggestions={ noop }
				fetchSavedInfo={ () => Promise.reject( new Error( 'unreachable' ) ) }
				label="Content"
			/>
		);
		return { onChange };
	};

	it( 'shows the IDs rather than dropping the saved selection', async () => {
		renderField();
		expect( await screen.findByText( '11' ) ).toBeInTheDocument();
		expect( screen.getByText( '22' ) ).toBeInTheDocument();
	} );

	// The field looks settled once the spinner goes, so the console is the only
	// trace left of why the titles turned into numbers.
	it( 'leaves the cause in the console', async () => {
		renderField();
		await screen.findByText( '11' );
		expect( errorSpy ).toHaveBeenCalledWith( expect.stringContaining( 'could not load the titles' ), expect.any( Error ) );
	} );

	it( 'keeps the remaining IDs when one token is removed', async () => {
		const { onChange } = renderField();
		await screen.findByText( '11' );
		fireEvent.click( document.querySelectorAll( '.components-form-token-field__remove-token' )[ 0 ] );
		expect( onChange ).toHaveBeenCalledWith( [ '22' ] );
	} );
} );
