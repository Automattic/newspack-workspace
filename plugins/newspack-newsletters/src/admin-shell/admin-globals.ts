/**
 * Safe getters for the PHP-localised `newspackNewslettersAdmin` global.
 *
 * Modules can import outside wp-admin (Jest, Storybook); these getters
 * use optional chaining + fallbacks so imports don't throw on a
 * missing global.
 */

const DEFAULT_ADMIN_URL = '/wp-admin/';
const DEFAULT_CPT_SLUG = 'newspack_nl_cpt';

function getGlobal(): NewspackNewslettersAdmin | null {
	return typeof window === 'undefined' ? null : window.newspackNewslettersAdmin || null;
}

/**
 * Resolve the admin base URL (always trailing-slashed, e.g. `/wp-admin/`).
 *
 * @return Trailing-slashed URL.
 */
export function getAdminUrl(): string {
	return getGlobal()?.adminUrl || DEFAULT_ADMIN_URL;
}

/**
 * Resolve the Newsletters CPT slug.
 *
 * @return CPT slug.
 */
export function getCptSlug(): string {
	return getGlobal()?.cptSlug || DEFAULT_CPT_SLUG;
}
