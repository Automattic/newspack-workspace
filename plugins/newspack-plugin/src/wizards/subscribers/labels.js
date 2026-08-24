/**
 * Publisher-configurable group/team label and group-role labels.
 *
 * The group label mirrors the Audience → Setup "Group labels" override
 * (newspack_group_subscription_label_singular / _plural options, surfaced by
 * Group_Subscription::get_label), localized onto window by the wizard's PHP
 * enqueue. Falls back to the default "Group" / "Groups" when unset.
 */

import { __ } from '@wordpress/i18n';

const cfg = ( typeof window !== 'undefined' && window.newspackSubscribers ) || {};

export const GROUP_LABEL = cfg.groupLabel || __( 'Group', 'newspack-plugin' );
export const GROUP_LABEL_PLURAL = cfg.groupLabelPlural || __( 'Groups', 'newspack-plugin' );

// A group member's role within their group. The group is the owner's, held by
// exactly one owner; any number of managers maintain it; everyone else is a
// plain member.
export const ROLE_LABELS = {
	owner: __( 'Owner', 'newspack-plugin' ),
	manager: __( 'Manager', 'newspack-plugin' ),
	member: __( 'Member', 'newspack-plugin' ),
};
