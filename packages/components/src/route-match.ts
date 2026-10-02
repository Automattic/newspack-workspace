/**
 * Internal dependencies.
 */
import Router from './proxied-imports/router';

const { matchPath } = Router;

/**
 * A route item that can be tested against the current pathname.
 */
export interface RouteMatchItem {
	/** The route path; matched prefix-based unless `exact` or the root path. */
	path?: string;
	/** Match `path` exactly rather than as a prefix. */
	exact?: boolean;
	/** Extra paths (exact or `*`-wildcard) that also mark this item active. */
	activeTabPaths?: string[];
}

/**
 * Whether a wildcard pattern (`'/base/*'` or `'/base*'`) matches a pathname.
 * The wildcard is anchored at a `/` boundary: `'/segments*'` matches
 * `/segments` and `/segments/123`, never `/segments-old`.
 *
 * @param {string} pattern  Pattern ending in `*`.
 * @param {string} pathname Current router pathname.
 * @return {boolean} Whether the pattern matches.
 */
const matchesWildcard = ( pattern: string, pathname: string ): boolean => {
	const base = pattern.slice( 0, -1 ).replace( /\/$/, '' );
	return pathname === base || pathname.startsWith( base + '/' );
};

/**
 * Single route matcher shared by breadcrumb selection and tabbed navigation.
 *
 * An item matches when one of its `activeTabPaths` hits (exact string, or a
 * `*` wildcard anchored at a `/` boundary), or — falling through on a miss —
 * when its `path` matches the pathname. Path matching is prefix-based except
 * for the root path and items opting in via `exact: true`, mirroring how the
 * wizard registers its `<Route>`s (`exact={ section.exact ?? false }`).
 *
 * @param {Object} item     Route item (`{ path?, exact?, activeTabPaths? }`).
 * @param {string} pathname Current router pathname.
 * @return {boolean} Whether the item matches the pathname.
 */
export const matchesRoute = ( item: RouteMatchItem, pathname: string ): boolean => {
	if ( Array.isArray( item.activeTabPaths ) ) {
		const wildcardHit = item.activeTabPaths.some( path => ( path.endsWith( '*' ) ? matchesWildcard( path, pathname ) : path === pathname ) );
		if ( wildcardHit ) {
			return true;
		}
	}
	if ( ! item.path ) {
		return false;
	}
	const exact = '/' === item.path || item.exact === true;
	return !! matchPath( pathname, { path: item.path, exact } );
};
