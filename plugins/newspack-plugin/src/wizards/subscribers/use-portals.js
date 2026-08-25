/**
 * DOM-portal helpers for the Subscribers wizard.
 *
 * The wizard owns its own header markup and renders it from store data, so a
 * screen that needs to place something inside a node the wizard controls (the
 * profile avatar, in the section header) has to portal into it rather than
 * render it inline.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Resolve a node the wizard renders asynchronously from its store (e.g. the
 * section header, which only appears once a screen has dispatched a
 * `sectionTitle`). Watches the DOM until the selector matches instead of betting
 * on a single frame, optionally tagging the node with a modifier class.
 *
 * @param {string}  selector        The selector to resolve.
 * @param {string}  [modifierClass] Class to add to the node once found.
 * @param {boolean} [enabled]       Skip resolution entirely when false.
 * @return {HTMLElement|null} The resolved node, or null until it appears.
 */
export function useWizardNode( selector, modifierClass, enabled = true ) {
	const [ node, setNode ] = useState( null );
	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		let observer;
		// The node belongs to the wizard, not to the screen that borrowed it, so
		// the modifier has to come back off when the screen unmounts — otherwise a
		// list navigated back to keeps a class describing a portal that is gone.
		let tagged;
		const attach = () => {
			const found = document.querySelector( selector );
			if ( found ) {
				if ( modifierClass ) {
					found.classList.add( modifierClass );
					tagged = found;
				}
				setNode( found );
			}
			return !! found;
		};
		if ( ! attach() ) {
			observer = new window.MutationObserver( () => {
				if ( attach() ) {
					observer.disconnect();
				}
			} );
			observer.observe( document.body, { childList: true, subtree: true } );
		}
		return () => {
			observer?.disconnect();
			tagged?.classList.remove( modifierClass );
		};
	}, [ selector, modifierClass, enabled ] );
	return node;
}
