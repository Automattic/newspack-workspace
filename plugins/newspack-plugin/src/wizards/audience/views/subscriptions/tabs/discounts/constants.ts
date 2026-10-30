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
