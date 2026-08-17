/**
 * Front-end registry for the Subscriptions wizard's tabs.
 *
 * The shell renders whichever tabs PHP registered (see
 * Audience_Subscriptions::register_tab) and looks each one up here by slug, so
 * a feature owns both halves of its own tab and the shell knows nothing about
 * any particular feature.
 *
 * This module deliberately imports no feature: features import it, and
 * `tabs/index.ts` imports both. Registering from a module this one imported
 * would run the registration before the registry existed.
 */

/**
 * Internal dependencies.
 */
import type { SubscriptionsTabComponent } from '../types';

const registry: Record< string, SubscriptionsTabComponent > = {};

/**
 * Register a tab's front end under the slug PHP registered it with.
 *
 * @param slug Tab slug.
 * @param tab  The tab's renderer.
 */
export function registerTab( slug: string, tab: SubscriptionsTabComponent ) {
	registry[ slug ] = tab;
}

/**
 * Get a registered tab.
 *
 * @param slug Tab slug.
 */
export function getTab( slug: string ): SubscriptionsTabComponent | undefined {
	return registry[ slug ];
}
