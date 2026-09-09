/**
 * Publisher-configurable group/team label and group-role labels.
 *
 * The group label mirrors the Audience → Setup "Group labels" override. PHP ships the
 * raw option, blank when unset, alongside the default nouns and every sentence that
 * wraps them: this bundle's JS strings are not localized, so a template built here
 * would read in English beside a noun PHP had already resolved in the site's language.
 * Anything that puts the noun in a sentence belongs in this file, and its template in
 * the `groupPhrases` payload.
 *
 * Keep this list short. It exists only because no handle here calls
 * `wp_set_script_translations` and no `.json` catalogues are generated, so JS `__()`
 * returns its msgid verbatim. Once that build step lands, these templates should move
 * back to the call sites and this file should shrink to the two nouns. Until then,
 * counted phrases here are limited to the singular/plural pair a payload can carry —
 * real plural forms need the catalogues.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCount } from '../../../packages/components/src/breadcrumbs/format-count';

const cfg = ( typeof window !== 'undefined' && window.newspackSubscribers ) || {};

export const GROUP_LABEL = cfg.groupLabel || cfg.groupLabelDefault || __( 'Group', 'newspack-plugin' );
export const GROUP_LABEL_PLURAL = cfg.groupLabelPlural || cfg.groupLabelDefaultPlural || __( 'Groups', 'newspack-plugin' );

// The English source doubles as the fallback for a payload that never arrived.
const phrase = ( key, fallback ) => ( cfg.groupPhrases || {} )[ key ] || fallback;

/**
 * Spoken phrasing for a group count, e.g. "14 Groups". The noun is whichever one the
 * heading shows — the publisher's own or the translated default — so the two agree.
 * No "total": the list ships a default filter that hides cancelled groups, so the
 * number describes the current view rather than the site.
 *
 * @param {number} total Number of groups in the current view.
 * @return {string} Translated count phrase.
 */
export function groupCountLabel( total ) {
	return sprintf(
		/* translators: 1: number of groups. 2: the group label, e.g. "Groups". */
		phrase( 'count', __( '%1$s %2$s', 'newspack-plugin' ) ),
		formatCount( total ),
		1 === total ? GROUP_LABEL : GROUP_LABEL_PLURAL
	);
}

/**
 * Column header naming a reader's role within their group, e.g. "Group role".
 *
 * @return {string} Translated column header.
 */
export function groupRoleLabel() {
	return sprintf(
		/* translators: %s: the group label, e.g. "Group". */
		phrase( 'role', __( '%s role', 'newspack-plugin' ) ),
		GROUP_LABEL
	);
}

/**
 * Message for a failed groups read, e.g. "Could not load Groups: timed out".
 *
 * @param {string} message The underlying error.
 * @return {string} Translated failure message.
 */
export function groupLoadFailedLabel( message ) {
	return sprintf(
		/* translators: 1: the group label, e.g. "Groups". 2: the error message. */
		phrase( 'loadFailed', __( 'Could not load %1$s: %2$s', 'newspack-plugin' ) ),
		GROUP_LABEL_PLURAL,
		message
	);
}

/**
 * Accessible name for a link to one group, e.g. "View Group: Family plan".
 *
 * @param {string} name The group's name.
 * @return {string} Translated link name.
 */
export function groupViewLabel( name ) {
	return sprintf(
		/* translators: 1: the group label, e.g. "Group". 2: the group name. */
		phrase( 'view', __( 'View %1$s: %2$s', 'newspack-plugin' ) ),
		GROUP_LABEL,
		name
	);
}

// A group member's role within their group. The group is the owner's, held by
// exactly one owner; any number of managers maintain it; everyone else is a
// plain member.
export const ROLE_LABELS = {
	owner: __( 'Owner', 'newspack-plugin' ),
	manager: __( 'Manager', 'newspack-plugin' ),
	member: __( 'Member', 'newspack-plugin' ),
};
