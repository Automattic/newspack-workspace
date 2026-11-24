/**
 * A thumb that slides under a segmented control's selected segment.
 */

/**
 * Whether a segment is the selected one, however its script marks it.
 *
 * @param {HTMLElement} button A segment button.
 * @return {boolean} Whether the segment is selected.
 */
const isSelected = button =>
	button.classList.contains( 'selected' ) || 'true' === button.getAttribute( 'aria-selected' ) || 'true' === button.getAttribute( 'aria-pressed' );

/**
 * The segment buttons of a segmented control.
 *
 * @param {HTMLElement} tabs A `.newspack-ui__segmented-control__tabs` element.
 * @return {HTMLElement[]} The segment buttons.
 */
const getButtons = tabs => [ ...tabs.children ].filter( child => child.classList.contains( 'newspack-ui__button' ) );

/**
 * Move the thumb to the selected segment's box, or hide it when nothing is selected or visible.
 *
 * @param {HTMLElement} tabs    A `.newspack-ui__segmented-control__tabs` element.
 * @param {boolean}     animate Whether the thumb may slide there, rather than jump.
 */
const place = ( tabs, animate ) => {
	const selected = getButtons( tabs ).find( isSelected );
	if ( ! selected || ! selected.offsetWidth ) {
		tabs.classList.remove( 'has-thumb' );
		return;
	}
	const measuring = ! animate || ! tabs.classList.contains( 'has-thumb' );
	if ( measuring ) {
		tabs.classList.add( 'is-measuring' );
	}
	tabs.style.setProperty( '--newspack-ui-segmented-thumb-x', `${ selected.offsetLeft }px` );
	tabs.style.setProperty( '--newspack-ui-segmented-thumb-y', `${ selected.offsetTop }px` );
	tabs.style.setProperty( '--newspack-ui-segmented-thumb-width', `${ selected.offsetWidth }px` );
	tabs.style.setProperty( '--newspack-ui-segmented-thumb-height', `${ selected.offsetHeight }px` );
	tabs.classList.add( 'has-thumb' );
	if ( measuring ) {
		void tabs.offsetWidth;
		tabs.classList.remove( 'is-measuring' );
	}
};

/**
 * Keep a thumb under the selected segment, whichever script changes the selection.
 *
 * @param {HTMLElement} tabs A `.newspack-ui__segmented-control__tabs` element.
 */
export const setupThumb = tabs => {
	if ( tabs.dataset.thumb ) {
		return;
	}
	tabs.dataset.thumb = 'true';
	place( tabs, false );
	const mutationObserver = new window.MutationObserver( () => place( tabs, true ) );
	getButtons( tabs ).forEach( button =>
		mutationObserver.observe( button, { attributes: true, attributeFilter: [ 'class', 'aria-selected', 'aria-pressed' ] } )
	);
	if ( typeof window.ResizeObserver === 'function' ) {
		new window.ResizeObserver( () => place( tabs, false ) ).observe( tabs );
	}
};
