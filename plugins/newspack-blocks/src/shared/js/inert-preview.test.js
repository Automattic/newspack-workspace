/**
 * The handler cancels plain clicks on links inside the preview wrapper, and
 * leaves Cmd/Ctrl-clicks alone so open-in-new-tab still works. These cases
 * exercise that predicate against plain DOM and through React's
 * onClickCapture, the way the blocks attach it.
 */
/**
 * External dependencies
 */
import { createEvent, fireEvent, render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createPortal } from '@wordpress/element';

/**
 * Internal dependencies
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

	const dispatchModifiedClick = modifier => {
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
		return event;
	};

	it.each( [ 'ctrlKey', 'metaKey' ] )( 'leaves a %s click alone so open-in-new-tab still works', modifier => {
		expect( dispatchModifiedClick( modifier ).defaultPrevented ).toBe( false );
	} );

	it.each( [ 'shiftKey', 'altKey' ] )( 'cancels a %s click, which would open a window or download the link', modifier => {
		expect( dispatchModifiedClick( modifier ).defaultPrevented ).toBe( true );
	} );

	it( 'cancels a link inside the preview and leaves one portaled out of it alone', () => {
		const portalTarget = document.createElement( 'div' );
		document.body.appendChild( portalTarget );
		const { getByText } = render(
			<div onClickCapture={ preventPreviewNavigation }>
				<a href="https://example.test/in">In preview</a>
				{ createPortal( <a href="https://example.test/out">In popover</a>, portalTarget ) }
			</div>
		);
		const clickThrough = anchor => {
			const event = createEvent.click( anchor );
			fireEvent( anchor, event );
			return event;
		};
		expect( clickThrough( getByText( 'In preview' ) ).defaultPrevented ).toBe( true );
		expect( clickThrough( getByText( 'In popover' ) ).defaultPrevented ).toBe( false );
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
