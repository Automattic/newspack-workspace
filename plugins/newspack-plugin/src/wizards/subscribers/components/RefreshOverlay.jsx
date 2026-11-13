/**
 * WordPress dependencies.
 */
import { createPortal } from '@wordpress/element';

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
