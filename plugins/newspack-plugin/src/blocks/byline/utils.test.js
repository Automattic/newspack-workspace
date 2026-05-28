/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import { createElement, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { decodeHtmlEntities, parseBylineForDisplay, formatAuthorsList } from './utils';

describe( 'decodeHtmlEntities', () => {
	it( 'should decode HTML entities', () => {
		expect( decodeHtmlEntities( '&amp;' ) ).toBe( '&' );
		expect( decodeHtmlEntities( '&lt;&gt;' ) ).toBe( '<>' );
		expect( decodeHtmlEntities( 'John &amp; Jane' ) ).toBe( 'John & Jane' );
		expect( decodeHtmlEntities( '' ) ).toBe( '' );
	} );
} );

describe( 'parseBylineForDisplay', () => {
	it( 'should parse byline shortcodes into text and author links', () => {
		const result = parseBylineForDisplay( 'By [Author id=5]Jane Doe[/Author] and [Author id=7]John Smith[/Author]' );

		expect( result ).toHaveLength( 4 );
		expect( result[ 0 ] ).toBe( 'By ' );
		expect( result[ 2 ] ).toBe( ' and ' );

		const { container: c1 } = render( result[ 1 ] );
		expect( c1.querySelector( 'a.url.fn.n' ) ).toHaveTextContent( 'Jane Doe' );

		const { container: c2 } = render( result[ 3 ] );
		expect( c2.querySelector( 'a' ) ).toHaveTextContent( 'John Smith' );
	} );

	it( 'should decode HTML entities and handle plain text', () => {
		expect( parseBylineForDisplay( '' ) ).toHaveLength( 0 );
		expect( parseBylineForDisplay( 'By Staff' )[ 0 ] ).toBe( 'By Staff' );

		const result = parseBylineForDisplay( '[Author id=1]John &amp; Jane[/Author]' );
		const { container } = render( result[ 0 ] );
		expect( container ).toHaveTextContent( 'John & Jane' );
	} );
} );

describe( 'formatAuthorsList', () => {
	it( 'should return empty array for no authors', () => {
		expect( formatAuthorsList( [], true ) ).toEqual( [] );
		expect( formatAuthorsList( null, true ) ).toEqual( [] );
	} );

	it( 'should format single author with and without link', () => {
		const authors = [ { id: 1, display_name: 'Jane Doe' } ];

		const { container: linked } = render( formatAuthorsList( authors, true )[ 0 ] );
		expect( linked.querySelector( 'a.url.fn.n' ) ).toHaveTextContent( 'Jane Doe' );

		const { container: unlinked } = render( formatAuthorsList( authors, false )[ 0 ] );
		expect( unlinked.querySelector( 'span.fn.n' ) ).toHaveTextContent( 'Jane Doe' );
		expect( unlinked.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'should format multiple authors with conjunction', () => {
		const authors = [
			{ id: 1, display_name: 'Jane' },
			{ id: 2, display_name: 'John' },
			{ id: 3, name: 'Bob' }, // Tests name fallback.
		];
		const { container } = render( createElement( Fragment, null, ...formatAuthorsList( authors, true ) ) );

		expect( container ).toHaveTextContent( 'Jane' );
		expect( container ).toHaveTextContent( 'John' );
		expect( container ).toHaveTextContent( 'Bob' );
	} );
} );
