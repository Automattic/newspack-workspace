/**
 * Internal dependencies.
 */
import Router from '../proxied-imports/router';

const { matchPath } = Router;

const sectionMatches = ( section, pathname ) => {
	if ( Array.isArray( section.activeTabPaths ) ) {
		const wildcardHit = section.activeTabPaths.some( path =>
			path.endsWith( '*' ) ? pathname.startsWith( path.slice( 0, -1 ) ) : path === pathname
		);
		if ( wildcardHit ) {
			return true;
		}
	}
	if ( ! section.path ) {
		return false;
	}
	const exact = '/' === section.path || section.exact === true;
	return !! matchPath( pathname, { path: section.path, exact } );
};

/**
 * Select the active section's explicit breadcrumb trail by current route. Falls
 * back to the first section, then to an empty trail.
 *
 * @param {Array}  sections Wizard sections (`{ path, breadcrumbs, exact?, activeTabPaths? }`).
 * @param {string} pathname Current router pathname.
 * @return {Array} Breadcrumb items `{ label, url? }`.
 */
export const activeBreadcrumbs = ( sections = [], pathname ) => {
	if ( ! sections?.length ) {
		return [];
	}
	const match = sections.find( section => sectionMatches( section, pathname ) ) || sections[ 0 ];
	return match.breadcrumbs || [];
};
