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

describe( 'AutocompleteTokenField with two posts sharing a title', () => {
	const twins = () => [
		{ value: 65, label: 'Twin Story' },
		{ value: 66, label: 'Twin Story' },
		{ value: 67, label: 'Other Story' },
	];

	const search = input => {
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Story' } } );
	};

	const renderField = tokens => {
		const onChange = jest.fn();
		render(
			<AutocompleteTokenField
				tokens={ tokens }
				onChange={ onChange }
				fetchSuggestions={ () => Promise.resolve( twins() ) }
				fetchSavedInfo={ () => Promise.resolve( twins().filter( ( { value } ) => tokens.includes( value ) ) ) }
				label="Content"
			/>
		);
		return { onChange };
	};

	// A draft copy of a saved story has a lower or higher ID, and the saved one must survive either way.
	it( 'keeps the saved post when the editor adds something else', async () => {
		const { onChange } = renderField( [ 66 ] );
		await screen.findByText( 'Twin Story' );
		search( screen.getByRole( 'combobox' ) );
		fireEvent.click( await screen.findByRole( 'option', { name: 'Other Story' }, { timeout: 1500 } ) );
		expect( onChange ).toHaveBeenCalledWith( [ '66', '67' ] );
	} );

	it( 'adds the twin the editor picked', async () => {
		const { onChange } = renderField( [] );
		search( screen.getByRole( 'combobox' ) );
		fireEvent.click( await screen.findByRole( 'option', { name: 'Twin Story (66)' }, { timeout: 1500 } ) );
		expect( onChange ).toHaveBeenCalledWith( [ '66' ] );
	} );

	it( 'names every clashing post with its note, and adds the ID only when the notes clash too', async () => {
		const posts = [
			{ value: 65, label: 'Twin Story', clashNote: 'Draft' },
			{ value: 66, label: 'Twin Story', clashNote: 'Published' },
			{ value: 67, label: 'Copy Story', clashNote: 'Draft' },
			{ value: 68, label: 'Copy Story', clashNote: 'Draft' },
			{ value: 69, label: 'Other Story', clashNote: 'Draft' },
		];
		render( <AutocompleteTokenField tokens={ [] } onChange={ noop } fetchSuggestions={ () => Promise.resolve( posts ) } label="Content" /> );
		search( screen.getByRole( 'combobox' ) );
		await screen.findByRole( 'option', { name: 'Other Story' }, { timeout: 1500 } );
		expect( screen.getAllByRole( 'option' ).map( option => option.textContent ) ).toEqual( [
			'Twin Story (Draft)',
			'Twin Story (Published)',
			'Copy Story (Draft, 67)',
			'Copy Story (Draft, 68)',
			'Other Story',
		] );
	} );

	it( 'keeps both saved twins when one more is added', async () => {
		const { onChange } = renderField( [ 65, 66 ] );
		await screen.findByText( 'Twin Story (66)' );
		search( screen.getByRole( 'combobox' ) );
		fireEvent.click( await screen.findByRole( 'option', { name: 'Other Story' }, { timeout: 1500 } ) );
		expect( onChange ).toHaveBeenCalledWith( [ '65', '66', '67' ] );
	} );
} );
