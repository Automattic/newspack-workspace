/**
 * Cancel any unmodified click that lands on or inside an anchor.
 *
 * Attached with onClickCapture to a block's editor-preview wrapper so no link
 * in the preview — JSX-authored, server-built, or filter-injected — navigates
 * the editor-canvas iframe away from the post being edited, while links keep
 * rendering with their real destinations.
 *
 * Modified clicks are left alone so an editor can still open a previewed post
 * in a new tab or window: ctrl/cmd+click dispatches an ordinary click whose
 * default action is open-in-new-tab, so cancelling it would take that away.
 * Guarding on the four modifiers is the same test link-intercepting routers
 * use (React Router's isModifiedEvent). None of them navigate the canvas, so
 * the no-navigation guarantee holds. Middle-click needs no guard: it fires
 * auxclick, which never reaches this handler.
 *
 * @param {Event} event Click event from the capture phase.
 */
export const preventPreviewNavigation = event => {
	if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
		return;
	}
	if ( event.target.closest( 'a' ) ) {
		event.preventDefault();
	}
};
