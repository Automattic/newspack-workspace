/**
 * WordPress dependencies.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import useConfirmDialog from './use-confirm-dialog';

type UseUnsavedChangesDialogOptions = {
	when: boolean;
};

// Stack of mounted guards; the last entry owns the document-level handler so
// only the most-recently-mounted guard prompts when several are active. Using a
// stack keeps ownership correct under out-of-order unmounts.
const activeGuards: symbol[] = [];

/**
 * Returns true when `href` resolves to the same origin as the current page —
 * i.e. it is an internal navigation that would unload the wizard. External
 * `https://...` links, `mailto:`, `tel:`, and other schemes navigate to a new
 * context (new tab, mail client, dialer) without unloading the wizard, and
 * must not trigger the discard-changes prompt.
 */
function isSameOriginNavigation( link: HTMLAnchorElement ): boolean {
	try {
		const url = new URL( link.href, window.location.href );
		return url.origin === window.location.origin && ( url.protocol === 'http:' || url.protocol === 'https:' );
	} catch ( e ) {
		return false;
	}
}

/**
 * Shared unsaved-changes guard. Wraps `useConfirmDialog` with standardized
 * messaging, intercepts same-origin link clicks so the dialog fires instead
 * of a silent navigation, and adds a `beforeunload` listener as the last-resort
 * guard for refresh / tab-close (browser-native, cannot be styled). The
 * returned `confirmDialog` element must be rendered in JSX.
 *
 * Single-consumer constraint: the click handler is attached at the document
 * level in capture phase. Two simultaneously-active instances will both fire
 * a dialog. A development-only warning surfaces this.
 */
function useUnsavedChangesDialog( { when }: UseUnsavedChangesDialogOptions ) {
	const { confirmDialog, requestConfirm } = useConfirmDialog( {
		when,
		message: __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard changes', 'newspack-plugin' ),
		hideTitle: true,
	} );

	// Tracks navigation the user has already approved via our custom dialog so
	// the beforeunload guard doesn't fire a second native prompt on top of it.
	const isNavigatingRef = useRef( false );

	useEffect( () => {
		if ( ! when ) {
			return;
		}
		const ownerId = Symbol( 'unsaved-changes-guard' );
		activeGuards.push( ownerId );
		if ( process.env.NODE_ENV !== 'production' && activeGuards.length > 1 ) {
			// eslint-disable-next-line no-console
			console.warn(
				'useUnsavedChangesDialog: more than one active instance detected. ' +
					'Document-level click capture will fire a dialog per instance — ensure only one guard is active at a time.'
			);
		}
		const handler = ( e: MouseEvent ) => {
			// Only the top-of-stack guard prompts, so concurrent guards can't stack dialogs.
			if ( activeGuards[ activeGuards.length - 1 ] !== ownerId ) {
				return;
			}
			if ( e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
				return;
			}
			const target = e.target as HTMLElement | null;
			const link = target?.closest( 'a[href]' ) as HTMLAnchorElement | null;
			if ( ! link ) {
				return;
			}
			const href = link.getAttribute( 'href' );
			if ( ! href || href.startsWith( '#' ) || href.startsWith( 'javascript:' ) ) {
				// All `#`-prefixed links are skipped here: plain anchors
				// (`#section`) are scroll targets, and HashRouter paths
				// (`#/route`) are intercepted by `ConfirmDialog`'s built-in
				// `history.block` listener instead. Routing them through
				// `window.location.href` here would trigger a hashchange
				// that the still-active block re-catches, double-prompting.
				return;
			}
			if ( link.target && link.target !== '_self' ) {
				return;
			}
			// Skip mailto:, tel:, external origins, and any non-http(s) scheme —
			// they don't unload the wizard, so a discard prompt would be wrong.
			if ( ! isSameOriginNavigation( link ) ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			const destination = link.href;
			requestConfirm( () => {
				isNavigatingRef.current = true;
				window.location.href = destination;
			} );
		};
		document.addEventListener( 'click', handler, true );
		return () => {
			document.removeEventListener( 'click', handler, true );
			const idx = activeGuards.indexOf( ownerId );
			if ( idx !== -1 ) {
				activeGuards.splice( idx, 1 );
			}
		};
	}, [ when, requestConfirm ] );

	useEffect( () => {
		if ( ! when ) {
			return;
		}
		const handler = ( e: BeforeUnloadEvent ) => {
			if ( isNavigatingRef.current ) {
				return;
			}
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => window.removeEventListener( 'beforeunload', handler );
	}, [ when ] );

	return { confirmDialog, requestConfirm };
}

export default useUnsavedChangesDialog;
