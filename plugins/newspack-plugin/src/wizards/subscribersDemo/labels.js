/**
 * Publisher-configurable group/team label.
 *
 * Mirrors the Audience → Setup "Group labels" override
 * (newspack_group_subscription_label_singular / _plural options, surfaced by
 * Group_Subscription::get_label), localized onto window by the wizard's PHP
 * enqueue. Falls back to the default "Group" / "Groups" when unset.
 */

import { __ } from '@wordpress/i18n';

const cfg = ( typeof window !== 'undefined' && window.newspackSubscribersDemo ) || {};

export const GROUP_LABEL = cfg.groupLabel || __( 'Group', 'newspack-plugin' );
export const GROUP_LABEL_PLURAL = cfg.groupLabelPlural || __( 'Groups', 'newspack-plugin' );
export const GROUP_LABEL_LOWER = GROUP_LABEL.toLowerCase();
export const GROUP_LABEL_PLURAL_LOWER = GROUP_LABEL_PLURAL.toLowerCase();
