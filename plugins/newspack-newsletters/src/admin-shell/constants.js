/**
 * Shell-wide constants shared between the screens and `style.scss`.
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
