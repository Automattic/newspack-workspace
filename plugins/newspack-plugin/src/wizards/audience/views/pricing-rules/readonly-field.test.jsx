/**
 * The shared read-only row behind the Goal and Deal ID fields.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import ReadonlyField from './readonly-field';

describe( 'ReadonlyField', () => {
	it( 'labels the value and leaves it uneditable but selectable', () => {
		render( <ReadonlyField id="rf" label="Deal ID" value="121" /> );
		const input = screen.getByDisplayValue( '121' );
		expect( input ).toHaveAttribute( 'readonly' );
		expect( input ).toBeEnabled();
		expect( screen.getByText( 'Deal ID' ) ).toBeInTheDocument();
	} );

	it( 'describes the value by its help text', () => {
		render( <ReadonlyField id="rf" label="Deal ID" help="It never changes." value="121" /> );
		expect( screen.getByDisplayValue( '121' ) ).toHaveAttribute( 'aria-describedby', 'rf__help' );
		expect( screen.getByText( 'It never changes.' ) ).toHaveAttribute( 'id', 'rf__help' );
	} );

	it( 'omits the description when there is no help text', () => {
		render( <ReadonlyField id="rf" label="Deal ID" value="121" /> );
		expect( screen.getByDisplayValue( '121' ) ).not.toHaveAttribute( 'aria-describedby' );
	} );

	it( 'shows the placeholder when the value is empty', () => {
		render( <ReadonlyField id="rf" label="Goal" value="" placeholder="No goal chosen yet" /> );
		expect( screen.getByPlaceholderText( 'No goal chosen yet' ) ).toBeInTheDocument();
	} );

	it( 'keeps the action reachable beside the value', () => {
		render(
			<ReadonlyField id="rf" label="Goal" value="Win-Back">
				<Button variant="secondary">Change</Button>
			</ReadonlyField>
		);
		expect( screen.getByRole( 'button', { name: 'Change' } ) ).toBeEnabled();
	} );

	it( 'marks the value monospace only when asked', () => {
		const wrapper = () => screen.getByDisplayValue( '121' ).closest( '.is-monospace' );
		const { rerender } = render( <ReadonlyField id="rf" label="Deal ID" value="121" /> );
		expect( wrapper() ).toBeNull();
		rerender( <ReadonlyField id="rf" label="Deal ID" value="121" isMonospace /> );
		expect( wrapper() ).toBeInTheDocument();
	} );
} );
