/**
 * Shared DOM-portal helpers for the Subscribers Demo wizard.
 *
 * The wizard layout renders the section header and main column itself, so a few
 * screens need to portal content (full-width notices, the profile avatar) into
 * nodes the wizard owns. These hooks resolve those nodes resiliently.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Host full-width notices in a container inserted as the first child of
 * .newspack-wizard__main, so they span the full width below the header/tabs
 * (outside the narrower content column). Returns the container node (null until
 * mounted) to portal into.
 *
 * @return {HTMLElement|null} The notices container, or null before it mounts.
 */
export function useNoticesPortal() {
	const [ noticesNode, setNoticesNode ] = useState( null );
	useEffect( () => {
		const main = document.querySelector( '.newspack-wizard__main' );
		if ( ! main ) {
			return undefined;
		}
		const container = document.createElement( 'div' );
		container.className = 'newspack-subscribers-demo__notices';
		main.insertBefore( container, main.firstChild );
		setNoticesNode( container );
		return () => {
			setNoticesNode( null );
			container.remove();
		};
	}, [] );
	return noticesNode;
}

/**
 * Resolve a node the wizard renders asynchronously from its store (e.g. the
 * section header). Watches the DOM until the selector matches instead of betting
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
		const attach = () => {
			const found = document.querySelector( selector );
			if ( found ) {
				if ( modifierClass ) {
					found.classList.add( modifierClass );
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
		return () => observer?.disconnect();
	}, [ selector, modifierClass, enabled ] );
	return node;
}
