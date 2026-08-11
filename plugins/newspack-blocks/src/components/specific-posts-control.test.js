/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SpecificPostsControl from './specific-posts-control';

const noop = () => {};
const noItems = () => Promise.resolve( [] );

describe( 'SpecificPostsControl', () => {
	it( 'exposes the token field to assistive technology as "Content"', () => {
		render( <SpecificPostsControl postIds={ [] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noop } /> );
		expect( screen.getByRole( 'combobox', { name: 'Content' } ) ).toBeInTheDocument();
	} );

	it( 'keeps the reorder button focusable while too little content is selected', () => {
		render( <SpecificPostsControl postIds={ [ 11 ] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noItems } /> );
		const reorder = screen.getByRole( 'button', { name: 'Reorder Content' } );
		expect( reorder ).toHaveAttribute( 'aria-disabled', 'true' );
		reorder.focus();
		expect( document.activeElement ).toBe( reorder );
	} );

	it( 'enables the reorder button once two items are selected', () => {
		render( <SpecificPostsControl postIds={ [ 11, 22 ] } onChange={ noop } fetchSuggestions={ noop } fetchSavedInfo={ noItems } /> );
		expect( screen.getByRole( 'button', { name: 'Reorder Content' } ) ).not.toHaveAttribute( 'aria-disabled' );
	} );
} );
