/**
 * External dependencies
 */
import { render, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ColorPicker from './';

describe( 'ColorPicker', () => {
	it( 'should render the label', () => {
		const { getByText } = render( <ColorPicker label="Background color" onChange={ () => {} } /> );
		expect( getByText( 'Background color' ) ).toBeInTheDocument();
	} );

	it( 'should render help text', () => {
		const { getByText } = render( <ColorPicker label="Background color" help="Choose a color" onChange={ () => {} } /> );
		expect( getByText( 'Choose a color' ) ).toBeInTheDocument();
	} );

	it( 'should start collapsed', () => {
		const { getByRole } = render( <ColorPicker label="Background color" onChange={ () => {} } /> );
		expect( getByRole( 'button' ) ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'should expand when the expander is clicked', () => {
		const { getByRole } = render( <ColorPicker label="Background color" onChange={ () => {} } /> );
		const expander = getByRole( 'button' );
		fireEvent.click( expander );
		expect( expander ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'should associate label via aria-labelledby', () => {
		const { getByRole, getByText } = render( <ColorPicker label="Background color" onChange={ () => {} } /> );
		const expander = getByRole( 'button' );
		const label = getByText( 'Background color' );
		expect( expander.getAttribute( 'aria-labelledby' ) ).toBe( label.id );
	} );

	it( 'should associate help text via aria-describedby when help is provided', () => {
		const { getByRole, getByText } = render( <ColorPicker label="Background color" help="Choose a color" onChange={ () => {} } /> );
		const expander = getByRole( 'button' );
		const help = getByText( 'Choose a color' );
		expect( expander.getAttribute( 'aria-describedby' ) ).toBe( help.id );
	} );

	it( 'should not set aria-describedby when no help is provided', () => {
		const { getByRole } = render( <ColorPicker label="Background color" onChange={ () => {} } /> );
		expect( getByRole( 'button' ) ).not.toHaveAttribute( 'aria-describedby' );
	} );
} );
