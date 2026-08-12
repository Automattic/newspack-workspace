/**
 * Shell-wide values shared between the screens and `style.scss`.
 */

/**
 * Class every screen puts on its `EmptyState.Root`.
 *
 * `style.scss` keys `:has()` off it to hide the shell header and hold the main
 * region to 1006px. A screen that renders an empty state without it keeps the
 * header and goes full-width, and nothing errors or fails.
 *
 * @type {string}
 */
export const EMPTY_STATE_CLASS = 'newspack-newsletters-admin__empty-state';

/**
 * Heading level for a screen's empty state.
 *
 * Standalone puts the page `<h1>` in `.newspack-newsletters-admin__header`, which
 * `style.scss` hides whenever an empty state is on screen, so the empty state has to
 * carry it. Bundled mode takes its `<h1>` from newspack-plugin's `Page`, outside that
 * hidden subtree, so the empty state stays an `<h2>`.
 *
 * @return {number} 1 when standalone, 2 when bundled.
 */
export function getEmptyStateHeading() {
	return window.newspackNewslettersAdmin?.bundledMode ? 2 : 1;
}
