/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CardSortableList from './';

const items = [
	{ id: 1, title: 'Homepage', badgeIntent: 'stable', badgeText: 'Active' },
	{ id: 2, title: 'About', badgeIntent: 'draft', badgeText: 'Draft' },
];

describe( 'CardSortableList', () => {
	it( 'renders a badge for each item that has badge text', () => {
		render( <CardSortableList items={ items } /> );
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Draft' ) ).toBeInTheDocument();
	} );

	it( 'renders no badge for an item without badge text', () => {
		const { container } = render( <CardSortableList items={ [ { id: 1, title: 'Homepage' } ] } /> );
		expect( screen.getByText( 'Homepage' ) ).toBeInTheDocument();
		// An unguarded Badge would still emit an empty span, so assert on element children.
		expect( container.querySelector( 'h3' ).children ).toHaveLength( 0 );
	} );
} );
