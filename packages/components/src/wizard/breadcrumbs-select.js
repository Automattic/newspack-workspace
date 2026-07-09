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

/**
 * Append a section's render-time current-page breadcrumb(s) to a trail.
 *
 * A section can supply a leaf via `headerData.sectionName` — either a single
 * label (e.g. an integration name, or Add/Edit) or an ordered array of
 * `{ label, url? }` crumbs when the leaf needs its own linked ancestors. Each
 * crumb is appended in turn, skipping one whose label just repeats the current
 * trailing label so the same leaf never renders twice (some routes author the
 * leaf both statically and via `sectionName`).
 *
 * @param {Array}                  breadcrumbItems Base trail `{ label, url? }[]`.
 * @param {string|Array|undefined} sectionName     A label, an array of crumbs, or falsy.
 * @return {Array} A new trail with the section crumb(s) appended (deduped).
 */
export const appendSectionName = ( breadcrumbItems = [], sectionName ) => {
	if ( ! sectionName ) {
		return breadcrumbItems;
	}
	const extraCrumbs = ( Array.isArray( sectionName ) ? sectionName : [ { label: sectionName } ] ).filter( crumb => crumb?.label );
	return extraCrumbs.reduce( ( trail, crumb ) => {
		if ( trail[ trail.length - 1 ]?.label === crumb.label ) {
			return trail;
		}
		return [ ...trail, crumb ];
	}, breadcrumbItems );
};
