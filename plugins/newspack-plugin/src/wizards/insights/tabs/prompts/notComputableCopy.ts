/**
 * Per-intent "not computable this window" copy for the Prompts tab (NPPD-1704).
 *
 * Shown when a conversion metric IS capable (its block exists in an active
 * prompt) but the math couldn't complete for the selected window — typically a
 * zero denominator (SAFE_DIVIDE NULL) because no in-intent prompts were viewed.
 * Distinct from `notCapableCopy` (NPPD-1720), which fires before the BigQuery
 * query when the block is structurally absent: that nudge says "add the block",
 * these say "no inputs this timeframe — try a longer range or wait for traffic".
 * Reader-facing copy says "timeframe" (not "window") to match the rest of the
 * Insights UI, e.g. the Prompt exposure section on this same tab.
 *
 * Intent-grouped: the four conversion intents (newsletter, registration,
 * donation, subscription) each share one string across their Direct and Revenue
 * cards, plus the cross-intent form-submission case. All four **Influenced** rates
 * are converter-denominated (paid: NPPD-1822; free registration/newsletter:
 * NPPD-1824) — their non-computable trigger is "no new converters of that type in
 * the window", not "no in-intent prompts viewed" — so they use the dedicated
 * `*Influenced` strings. Centralized here (rather than inline on each card) so the
 * cross-tab voice-and-tone audit (NPPD-1698) has a single place to review them.
 * Strings end with a period to match 1720's nudges.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const NOT_COMPUTABLE_COPY = {
	/** Form submission rate — denominator is impressions on any form-bearing prompt. */
	formBearing: __( 'No form-bearing prompts viewed in this timeframe.', 'newspack-plugin' ),
	newsletter: __( 'No prompts with a newsletter block viewed in this timeframe.', 'newspack-plugin' ),
	registration: __( 'No prompts with a registration block viewed in this timeframe.', 'newspack-plugin' ),
	donation: __( 'No prompts with a donation block viewed in this timeframe.', 'newspack-plugin' ),
	subscription: __( 'No subscription-intent prompts viewed in this timeframe.', 'newspack-plugin' ),
	// Converter-denominated Influenced rates (paid NPPD-1822, free NPPD-1824): non-
	// computable means no new converters of that type in the window, not "no prompts viewed".
	donationInfluenced: __( 'No new donors in this timeframe.', 'newspack-plugin' ),
	subscriptionInfluenced: __( 'No new subscribers in this timeframe.', 'newspack-plugin' ),
	registrationInfluenced: __( 'No new registrations in this timeframe.', 'newspack-plugin' ),
	newsletterInfluenced: __( 'No new newsletter signups in this timeframe.', 'newspack-plugin' ),
};
