/**
 * Util functions.
 */

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
export const domReady = callback => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
};

/**
 * Wire up tab-and-panel switching on a root element.
 *
 * @param {HTMLElement} element            Root element containing the tab list and (optionally) a content area.
 * @param {Object}      classnames         Component-specific classnames.
 * @param {string}      classnames.list    Classname of the tab list element (button row).
 * @param {string}      classnames.content Classname of the panels container.
 */
export const setupTabController = ( element, classnames ) => {
	const tab_body = element.querySelector( `.${ classnames.content }` );
	let tab_contents = [];
	if ( tab_body ) {
		tab_contents = [ ...tab_body.children ];
	}

	const header = element.querySelector( `.${ classnames.list }` );
	const select = element.querySelector( ':scope > select, :scope > .newspack-ui__segmented-control__form-control > select' );
	if ( ! header && ! select && tab_contents.length ) {
		tab_contents[ 0 ].classList.add( 'selected' );
		return;
	}

	const tab_headers = header ? [ ...header.children ] : [ select ];
	const isTablist = header && header.getAttribute( 'role' ) === 'tablist';

	const select_content = index => {
		if ( tab_contents.length === 0 ) {
			return;
		}

		const selectedContent = tab_contents[ index ];

		if ( isTablist ) {
			// Keep panels in the DOM so each tab's aria-controls target stays valid.
			tab_contents.forEach( ( content, i ) => content.classList.toggle( 'selected', i === index ) );
		} else {
			// Restore previously removed contents in reverse order before removing again.
			if ( tab_body._removedContents ) {
				for ( let i = tab_body._removedContents.length - 1; i >= 0; i-- ) {
					const { content, nextSibling } = tab_body._removedContents[ i ];
					if ( nextSibling && nextSibling.parentNode === tab_body ) {
						tab_body.insertBefore( content, nextSibling );
					} else {
						tab_body.appendChild( content );
					}
				}
				delete tab_body._removedContents;
			}

			// Remove all tab contents except the selected one.
			const removedContents = [];
			tab_contents.forEach( ( content, i ) => {
				if ( i !== index ) {
					removedContents.push( { content, nextSibling: content.nextSibling } );
					content.remove();
				}
			} );
			if ( removedContents.length > 0 ) {
				tab_body._removedContents = removedContents;
			}
			selectedContent.classList.add( 'selected' );
		}

		const radioInputs = selectedContent.querySelectorAll( 'input[type="radio"]' );
		const checkedRadio = [ ...radioInputs ].find( radio => radio.checked );

		if ( radioInputs.length && ! checkedRadio ) {
			radioInputs[ 0 ].click();
		}
		element.dispatchEvent( new CustomEvent( 'content-selected', { detail: selectedContent } ) );
	};

	const updateAria = activeIndex => {
		if ( ! isTablist ) {
			return;
		}
		tab_headers.forEach( ( t, j ) => {
			const isActive = j === activeIndex;
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.setAttribute( 'tabindex', isActive ? '0' : '-1' );
		} );
	};

	tab_headers.forEach( ( tab, i ) => {
		if ( tab_contents.length === 0 ) {
			return;
		}

		if ( tab.tagName === 'SELECT' ) {
			tab.classList.add( 'selected' );
			select_content( parseInt( tab.value, 10 ) );
			tab.addEventListener( 'change', function ( ev ) {
				select_content( parseInt( ev.target.value, 10 ) );
			} );
			return;
		}

		if ( tab.classList.contains( 'selected' ) ) {
			select_content( i );
			updateAria( i );
		}

		tab.addEventListener( 'click', function () {
			tab_headers.forEach( t => t.classList.remove( 'selected' ) );
			this.classList.add( 'selected' );
			select_content( i );
			updateAria( i );
		} );

		if ( isTablist ) {
			tab.addEventListener( 'keydown', function ( ev ) {
				const last = tab_headers.length - 1;
				let next = -1;
				if ( ev.key === 'ArrowRight' ) {
					next = i === last ? 0 : i + 1;
				} else if ( ev.key === 'ArrowLeft' ) {
					next = i === 0 ? last : i - 1;
				} else if ( ev.key === 'Home' ) {
					next = 0;
				} else if ( ev.key === 'End' ) {
					next = last;
				}
				if ( next < 0 ) {
					return;
				}
				ev.preventDefault();
				tab_headers[ next ].focus();
				tab_headers[ next ].click();
			} );
		}
	} );
};
