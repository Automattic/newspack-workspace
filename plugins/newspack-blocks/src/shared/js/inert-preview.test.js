/**
 * The editor preview must never navigate the canvas: any click that lands on
 * or inside an anchor is cancelled at the container, whatever markup produced
 * the anchor (JSX, server HTML, filter-injected HTML).
 */
import { preventPreviewNavigation } from './inert-preview';

describe( 'preventPreviewNavigation', () => {
	const dispatchClick = ( container, target ) => {
		container.addEventListener( 'click', preventPreviewNavigation, true );
		const event = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
		target.dispatchEvent( event );
		return event;
	};

	it( 'cancels a click on an anchor inside the container', () => {
		const container = document.createElement( 'div' );
		container.innerHTML = '<article><span class="byline"><a href="https://example.test/author/jane/">Jane</a></span></article>';
		document.body.appendChild( container );
		const event = dispatchClick( container, container.querySelector( 'a' ) );
		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'cancels a click on an element nested inside an anchor', () => {
		const container = document.createElement( 'div' );
		container.innerHTML = '<a href="https://example.test/"><img alt="" /></a>';
		document.body.appendChild( container );
		const event = dispatchClick( container, container.querySelector( 'img' ) );
		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'leaves non-anchor clicks alone', () => {
		const container = document.createElement( 'div' );
		container.innerHTML = '<article><h2 class="entry-title">Headline</h2></article>';
		document.body.appendChild( container );
		const event = dispatchClick( container, container.querySelector( 'h2' ) );
		expect( event.defaultPrevented ).toBe( false );
	} );
} );
