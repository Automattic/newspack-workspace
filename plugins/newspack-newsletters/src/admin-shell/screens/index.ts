/**
 * Screen registry — maps admin page slugs to React components.
 *
 * Settings is standalone-only at the PHP layer (`Admin_Shell::get_pages`
 * excludes it in bundled mode), so its registration here is harmless.
 */

import { __ } from '@wordpress/i18n';
import NewslettersListScreen from './newsletters-list';
import AdsListScreen from './ads-list';
import AdvertisersListScreen from './advertisers-list';
import LayoutsListScreen from './layouts-list';
import SettingsScreen from './settings';

import type { ComponentType } from 'react';

interface ScreenEntry {
	component: ComponentType< { label?: string } >;
	label: string;
}

export const screens: Record< string, ScreenEntry > = {
	'newspack-newsletters-list': {
		component: NewslettersListScreen,
		label: __( 'All Newsletters', 'newspack-newsletters' ),
	},
	'newspack-newsletters-ads-list': {
		component: AdsListScreen,
		label: __( 'Newsletter Ads', 'newspack-newsletters' ),
	},
	'newspack-newsletters-advertisers-list': {
		component: AdvertisersListScreen,
		label: __( 'Advertisers', 'newspack-newsletters' ),
	},
	'newspack-newsletters-layouts-list': {
		component: LayoutsListScreen,
		label: __( 'Layouts', 'newspack-newsletters' ),
	},
	'newspack-newsletters-settings': {
		component: SettingsScreen,
		label: __( 'Settings', 'newspack-newsletters' ),
	},
};

export function resolveScreen( slug?: string ): ScreenEntry | null {
	if ( ! slug ) {
		return null;
	}
	return screens[ slug ] || null;
}

/**
 * Resolve the visible page label, preferring the PHP-localised value
 * so the heading stays aligned with the admin menu PHP renders.
 *
 * @param slug                                 Page slug (PHP-localised `currentPage`).
 * @param globalScope                          Override for tests; defaults to `window`.
 * @param globalScope.newspackNewslettersAdmin PHP-localised admin globals.
 * @return Resolved label, or an empty string.
 */
export function resolveLabel(
	slug?: string,
	globalScope: { newspackNewslettersAdmin?: NewspackNewslettersAdmin } = typeof window === 'undefined' ? {} : window
): string {
	const phpLabel = globalScope?.newspackNewslettersAdmin?.label;
	if ( phpLabel ) {
		return phpLabel;
	}
	return resolveScreen( slug )?.label || '';
}
