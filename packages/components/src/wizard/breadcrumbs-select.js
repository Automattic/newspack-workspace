/**
 * Internal dependencies.
 */
import { matchesRoute } from '../route-match';

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
	const match = sections.find( section => matchesRoute( section, pathname ) ) || sections[ 0 ];
	return match.breadcrumbs || [];
};

/**
 * Append a section's render-time current-page breadcrumb(s) to a trail.
 *
 * A section can supply a leaf via `headerData.sectionName` — either a single
 * label (e.g. an integration name, or Add/Edit) or an ordered array of
 * `{ label, url? }` crumbs when the leaf needs its own linked ancestors. Each
 * crumb is appended in turn.
 *
 * Convention: a route authors its current-page leaf in exactly ONE place —
 * the static `breadcrumbs` array for a constant label, or `sectionName` for a
 * render-time label (e.g. Add/Edit) whose static trail then holds ancestors
 * only. Never both. The trailing-label skip below is a defensive guard against
 * a route accidentally doing both; it is not a supported pattern to rely on.
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
