/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import Breadcrumbs from './';

describe( 'Breadcrumbs', () => {
	it( 'renders the last item as the only h1 and never a link', () => {
		render( <Breadcrumbs items={ [ { label: 'Newsletters' }, { label: 'Settings', url: '#/x' } ] } /> );
		const h1s = screen.getAllByRole( 'heading', { level: 1 } );
		expect( h1s ).toHaveLength( 1 );
		expect( h1s[ 0 ] ).toHaveTextContent( 'Settings' );
		expect( screen.queryByRole( 'link', { name: 'Settings' } ) ).not.toBeInTheDocument();
	} );

	it( 'renders a non-last item with a url as a link, without a url as text', () => {
		render( <Breadcrumbs items={ [ { label: 'Advertising' }, { label: 'Sponsors', url: '/wp-admin/x' }, { label: 'All sponsors' } ] } /> );
		const links = screen.getAllByRole( 'link' );
		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ] ).toHaveTextContent( 'Sponsors' );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe( '/wp-admin/x' );
		expect( screen.getByText( 'Advertising' ) ).toBeInTheDocument();
	} );

	it( 'does not special-case the first item: it links when it has a url', () => {
		render( <Breadcrumbs items={ [ { label: 'Audience', url: '#/' }, { label: 'Donations' } ] } /> );
		const link = screen.getByRole( 'link', { name: 'Audience' } );
		expect( link.getAttribute( 'href' ) ).toBe( '#/' );
	} );

	it( 'renders a single-item trail as just the h1 with no separator', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Dashboard' } ] } /> );
		expect( screen.getByRole( 'heading', { level: 1 } ) ).toHaveTextContent( 'Dashboard' );
		expect( container.querySelector( '.newspack-breadcrumbs__separator' ) ).toBeNull();
		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a "/" separator after each preceding item', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Audience' }, { label: 'Access control' } ] } /> );
		const separators = container.querySelectorAll( '.newspack-breadcrumbs__separator' );
		expect( separators ).toHaveLength( 1 );
		expect( separators[ 0 ] ).toHaveTextContent( '/' );
		expect( separators[ 0 ] ).toHaveAttribute( 'aria-hidden', 'true' );
	} );

	it( 'renders no heading when the last item has no label', () => {
		render( <Breadcrumbs items={ [ { label: undefined } ] } /> );
		expect( screen.queryByRole( 'heading', { level: 1 } ) ).not.toBeInTheDocument();
	} );

	it( 'renders nothing when there are no items', () => {
		const { container } = render( <Breadcrumbs items={ [] } /> );
		expect( container.querySelector( 'nav' ) ).not.toBeInTheDocument();
	} );
} );
