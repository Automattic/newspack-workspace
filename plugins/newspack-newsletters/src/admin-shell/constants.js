/**
 * Shell-wide values shared between the screens and `style.scss`.
 */

import { isBundledMode } from './admin-globals';

/**
 * Class every screen puts on its `EmptyState.Root`.
 *
 * `style.scss` keys `:has()` off it to hide the shell header and cap the main region
 * at 1006px. Omitting it silently restores both.
 *
 * @type {string}
 */
export const EMPTY_STATE_CLASS = 'newspack-newsletters-admin__empty-state';

/**
 * Heading level for a screen's empty state.
 *
 * Standalone hides the shell header that carries the page `<h1>`, so the empty state
 * has to be it. Bundled mode gets its `<h1>` from `Page`, outside that hidden subtree.
 *
 * @return {1|2} 1 when standalone, 2 when bundled.
 */
export function getEmptyStateHeading() {
	return isBundledMode() ? 2 : 1;
}
