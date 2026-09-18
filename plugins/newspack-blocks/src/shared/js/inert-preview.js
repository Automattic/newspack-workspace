/**
 * Cancel any unmodified click that lands on or inside an anchor.
 *
 * Attached with onClickCapture to a block's editor-preview wrapper so no link
 * in the preview — JSX-authored, server-built, or filter-injected — navigates
 * the editor-canvas iframe away from the post being edited, while links keep
 * rendering with their real destinations.
 *
 * Modified clicks are left alone so an editor can still open a previewed post
 * in a new tab or window: cmd+click on macOS, ctrl+click elsewhere, dispatches
 * an ordinary click whose default action is open-in-new-tab, and cancelling it
 * would take that away. Guarding on all four modifiers is the same test
 * link-intercepting routers use (React Router's isModifiedEvent), and is
 * deliberately wider than the gestures that open a tab. The gap it leaves is
 * the Super key on Windows and Linux, which sets metaKey while carrying no link
 * behaviour of its own; the OS claims that chord before the page sees it in
 * practice. Two gestures never reach this handler at all: middle-click fires
 * auxclick, and on macOS ctrl+click is a secondary click that fires contextmenu.
 *
 * A target that cannot be asked for an ancestor anchor is ignored rather than
 * throwing: an exception raised here runs in the capture phase and would stop
 * every later click in the preview from being handled at all.
 *
 * @param {MouseEvent | import('react').MouseEvent} event Click event from the capture phase.
 */
export const preventPreviewNavigation = event => {
	if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
		return;
	}
	if ( typeof event.target?.closest !== 'function' ) {
		return;
	}
	if ( event.target.closest( 'a' ) ) {
		event.preventDefault();
	}
};
