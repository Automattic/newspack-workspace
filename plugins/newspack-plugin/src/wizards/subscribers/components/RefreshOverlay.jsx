/**
 * WordPress dependencies.
 */
import { createPortal, useEffect, useRef } from '@wordpress/element';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies.
 */
import { Waiting } from '../../../../packages/components/src';

/**
 * A spinner covering a server-paginated list while a refetch is in flight.
 *
 * DataViews' own spinner renders below the table, out of sight on a long page.
 * This one sits in the document body, so no ancestor of the list can pin the
 * fixed overlay to itself or stack popovers above it. Cancelling the press keeps
 * focus where it was: moving it would close an open filter popover and pull
 * focus out of the search box. Keys still reach the controls underneath, so a
 * reader can keep typing in the search box.
 *
 * @return {JSX.Element} The overlay.
 */
export default function RefreshOverlay() {
	return createPortal(
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="newspack-subscribers__loading-overlay" onMouseDown={ event => event.preventDefault() }>
			<Waiting />
		</div>,
		document.body
	);
}

/**
 * Announce the new result count once a refetch settles.
 *
 * The overlay's spinner has no accessible name, so without this a screen
 * reader user typing in the search box hears nothing while the list changes.
 * It speaks once per refetch, not on every keystroke.
 *
 * @param {boolean} isRefreshing Whether a refetch is in flight over a shown list.
 * @param {string}  message      The count to announce, e.g. "12 subscribers".
 */
export function useAnnounceRefresh( isRefreshing, message ) {
	const wasRefreshing = useRef( false );
	useEffect( () => {
		if ( wasRefreshing.current && ! isRefreshing ) {
			speak( message, 'polite' );
		}
		wasRefreshing.current = isRefreshing;
	}, [ isRefreshing, message ] );
}
