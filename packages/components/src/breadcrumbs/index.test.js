/**
 * External dependencies.
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import Breadcrumbs from './';

describe( 'Breadcrumbs', () => {
	// Unconditional, so a failing assertion cannot leak a language into later tests.
	afterEach( () => {
		document.documentElement.lang = '';
	} );

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

	it( 'annotates a crumb with its count, hiding the parens from assistive tech', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Plans' }, { label: 'Subscriptions', count: 33 } ] } /> );
		const count = container.querySelector( '.newspack-breadcrumbs__count' );
		expect( count ).not.toBeNull();
		expect( count.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '(33)' );
		expect( count.querySelector( '.screen-reader-text' ) ).toHaveTextContent( '33 items' );
		expect( screen.getByRole( 'heading', { level: 1 } ) ).toContainElement( count );
	} );

	it( 'announces a single item in the singular', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Donations', count: 1 } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count .screen-reader-text' ).textContent ).toBe( '1 item' );
	} );

	it( 'renders a zero count rather than dropping the annotation', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Donations', count: 0 } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count [aria-hidden="true"]' ) ).toHaveTextContent( '(0)' );
	} );

	it( 'omits the annotation when a crumb carries no usable count', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Plans' }, { label: 'Bundles', count: undefined } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count' ) ).toBeNull();
		expect( screen.getByRole( 'heading', { level: 1 } ) ).toHaveTextContent( 'Bundles' );
	} );

	it( 'prefers a supplied countLabel for the accessible phrasing', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Subscribers', count: 85, countLabel: '85 subscribers total' } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count .screen-reader-text' ) ).toHaveTextContent( '85 subscribers total' );
	} );

	it( 'annotates a linked crumb outside the link, so the link is named for its destination', () => {
		const { container } = render( <Breadcrumbs items={ [ { label: 'Plans', url: '#/', count: 12 }, { label: 'Add plan' } ] } /> );
		const link = screen.getByRole( 'link', { name: 'Plans' } );
		expect( link.getAttribute( 'href' ) ).toBe( '#/' );
		expect( link ).not.toHaveTextContent( '(12)' );
		const count = container.querySelector( '.newspack-breadcrumbs__count' );
		expect( count.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '(12)' );
		expect( link ).not.toContainElement( count );
	} );

	it( 'groups the count for the document language', () => {
		document.documentElement.lang = 'de-DE';
		const { container } = render( <Breadcrumbs items={ [ { label: 'Abonnenten', count: 1234 } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count [aria-hidden="true"]' ) ).toHaveTextContent( '(1.234)' );
	} );

	it( 'falls back to the default grouping when the document language is not a locale Intl accepts', () => {
		document.documentElement.lang = 'pt-PT-ao90';
		const { container } = render( <Breadcrumbs items={ [ { label: 'Planos', count: 1234 } ] } /> );
		expect( container.querySelector( '.newspack-breadcrumbs__count [aria-hidden="true"]' ).textContent ).toMatch( /^\(1\D?234\)$/ );
	} );
} );
