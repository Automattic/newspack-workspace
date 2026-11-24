export const SITE_NAME = 'Site name';

/**
 * A link in the header, the mobile menu or the footer.
 *
 * - `section`: the path this link marks as current. The link whose `section` is the longest
 *   prefix of the current path is the current one; `'/'` matches only the home page.
 * - `drawer`: in the mobile menu, `'tree'` marks the link that holds the docs page tree as a
 *   folder (its row links to `href` and has its own toggle), and `false` leaves out a link that
 *   is already a section of that tree.
 * - `external`: the link leaves the site. Menu rows stay plain links in the same tab.
 */
export type NavLink = {
	href: string;
	text: string;
	section?: string;
	drawer?: 'tree' | false;
	external?: boolean;
};

export const NAV_LINKS: NavLink[] = [
	{ href: '/', text: 'Home', section: '/' },
	{ href: '/docs/', text: 'Docs', section: '/docs/', drawer: 'tree' },
];

export const FOOTER_LINKS: NavLink[] = [ { href: '/docs/', text: 'Docs' } ];

/**
 * Whether the site has a dark color scheme. With `false` the site is light only: the header has
 * no theme toggle, and the head script never sets `dark`, whatever the system setting or a
 * choice stored earlier.
 */
export const DARK_MODE = true;

/**
 * Whether docs pages show the page tree as a sidebar at `md` and up (768px, the default
 * MOBILE_MENU_QUERY). With `false` there is no sidebar column: the page and its table of
 * contents take the width, and the header nav is the page list. Turn it off for a small site
 * whose header nav already lists every page. The mobile menu keeps the site links and the docs
 * tree either way.
 */
export const DOCS_SIDEBAR = true;

/**
 * Below this width the header shows the menu button and the site follows the system color
 * scheme. Keep it equal to Tailwind's `md` breakpoint, which the header's classes use.
 */
export const MOBILE_MENU_QUERY = '(width < 48rem)';

/** The static search index the docs search reads. */
export const SEARCH_INDEX = '/api/search.json';

/** The line the search overlay shows when nothing matches, such as the names worth searching for. */
export const SEARCH_EMPTY_HINT = 'No pages match. Try another word.';
