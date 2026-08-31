/**
 * Cancel any click that lands on or inside an anchor.
 *
 * Attached with onClickCapture to a block's editor-preview wrapper so no link
 * in the preview — JSX-authored, server-built, or filter-injected — navigates
 * the editor-canvas iframe away from the post being edited, while links keep
 * rendering with their real destinations. Ctrl/middle-click open-in-new-tab
 * still works: those don't dispatch a plain click through this path.
 *
 * @param {Event} event Click event from the capture phase.
 */
export const preventPreviewNavigation = event => {
	if ( event.target.closest( 'a' ) ) {
		event.preventDefault();
	}
};
