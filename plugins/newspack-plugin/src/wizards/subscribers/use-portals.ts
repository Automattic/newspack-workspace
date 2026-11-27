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
 * @param selector      The selector to resolve.
 * @param modifierClass Class to add to the node once found.
 * @param enabled       Skip resolution entirely when false.
 * @return The resolved node, or null until it appears.
 */
export function useWizardNode( selector: string, modifierClass?: string, enabled = true ): HTMLElement | null {
	const [ node, setNode ] = useState< HTMLElement | null >( null );
	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		let observer: MutationObserver | undefined;
		// The node belongs to the wizard, not to the screen that borrowed it, so
		// the modifier has to come back off when the screen unmounts — otherwise a
		// list navigated back to keeps a class describing a portal that is gone.
		let tagged: HTMLElement | undefined;
		const attach = () => {
			const found = document.querySelector< HTMLElement >( selector );
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
			const resolved = new window.MutationObserver( () => {
				if ( attach() ) {
					resolved.disconnect();
				}
			} );
			observer = resolved;
			resolved.observe( document.body, { childList: true, subtree: true } );
		}
		return () => {
			observer?.disconnect();
			// `tagged` is set only when `modifierClass` was, so it is a string here.
			tagged?.classList.remove( modifierClass as string );
		};
	}, [ selector, modifierClass, enabled ] );
	return node;
}
