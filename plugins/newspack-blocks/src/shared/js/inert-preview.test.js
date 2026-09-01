/**
 * The handler cancels any unmodified click that lands on or inside an anchor,
 * and leaves modified clicks alone so open-in-new-tab still works. These cases
 * exercise that predicate against plain DOM. Whether it covers every anchor in
 * a preview depends on where the blocks attach it, which is not decided here.
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

	it( 'leaves a modified click alone so open-in-new-tab still works', () => {
		[ 'ctrlKey', 'metaKey', 'shiftKey', 'altKey' ].forEach( modifier => {
			const container = document.createElement( 'div' );
			container.innerHTML = '<a href="https://example.test/">Headline</a>';
			document.body.appendChild( container );
			container.addEventListener( 'click', preventPreviewNavigation, true );
			const event = new MouseEvent( 'click', {
				bubbles: true,
				cancelable: true,
				[ modifier ]: true,
			} );
			container.querySelector( 'a' ).dispatchEvent( event );
			expect( event.defaultPrevented ).toBe( false );
		} );
	} );

	it( 'ignores a target that cannot be asked for an ancestor anchor', () => {
		const event = { target: {}, preventDefault: jest.fn() };
		expect( () => preventPreviewNavigation( event ) ).not.toThrow();
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );

	it( 'leaves non-anchor clicks alone', () => {
		const container = document.createElement( 'div' );
		container.innerHTML = '<article><h2 class="entry-title">Headline</h2></article>';
		document.body.appendChild( container );
		const event = dispatchClick( container, container.querySelector( 'h2' ) );
		expect( event.defaultPrevented ).toBe( false );
	} );
} );
