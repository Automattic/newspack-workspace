/**
 * Constants for the Subscriber discounts tab.
 */

/**
 * Internal dependencies.
 */
import { WIZARD_ENDPOINT } from '../../constants';

/** Rules and settings for this tab. */
export const DISCOUNTS_ENDPOINT = `${ WIZARD_ENDPOINT }/discounts`;

/** Settings sub-route. */
export const DISCOUNT_SETTINGS_ENDPOINT = `${ DISCOUNTS_ENDPOINT }/settings`;

/** How many products the editor previews before summarizing the rest. */
export const PREVIEW_LIMIT = 8;

/**
 * How many subscriptions the list's Subscription column names before summarizing
 * the rest.
 *
 * The cell is one line and has to keep the trailing count legible beside the
 * names, which is what bounds this rather than any particular column width.
 * Matches `MAX_NAMED_PRODUCTS` in the subscriber-only tab, so the two lists in
 * this wizard summarize at the same point. The editor's drawer has room to list
 * the whole audience and caps nothing.
 */
export const SUBSCRIPTIONS_LABEL_LIMIT = 2;
