/**
 * Per-tab progressive loading copy (NPPD-1684).
 *
 * Each tab names the backend operation so the wait is informative rather than a
 * bare spinner. Consumed by TabStateView's initial-load frame (six GA4 tabs);
 * Advertising consumes its entry from the async `is_loading` GAM-report state
 * instead, where the genuinely long wait happens. Delays are absolute ms from
 * the start of the load — see useProgressiveMessages for the cadence.
 *
 * Strings are wrapped in `__()` so they're translatable and picked up by the
 * .pot extractor. Evaluated at module load, which is after script translations
 * are injected for the wizard bundle.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const TAB_LOADING_MESSAGES = {
	audience: [
		{ text: __( 'Loading reader data…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Counting unique readers…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Mapping where they come from…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	engagement: [
		{ text: __( 'Loading engagement data…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Measuring engagement signals…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Analyzing reading patterns…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	conversion: [
		{ text: __( 'Loading conversion journey…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Aggregating across all surfaces…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Joining WooCommerce orders…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	gates: [
		{ text: __( 'Loading gate performance…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Querying gate impressions…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Joining with WooCommerce orders…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	prompts: [
		{ text: __( 'Loading prompt performance…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Querying prompt impressions…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Joining with WooCommerce orders…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	subscribers: [
		{ text: __( 'Loading subscriber data…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Counting active subscribers…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Calculating MRR…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	donors: [
		{ text: __( 'Loading donor data…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Counting active donors…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Calculating contributions…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Almost there…', 'newspack-plugin' ), delay: 12000 },
	],
	advertising: [
		{ text: __( 'Loading ad performance…', 'newspack-plugin' ), delay: 0 },
		{ text: __( 'Submitting GAM report…', 'newspack-plugin' ), delay: 250 },
		{ text: __( 'Waiting for ad server…', 'newspack-plugin' ), delay: 6000 },
		{ text: __( 'Processing ad data…', 'newspack-plugin' ), delay: 12000 },
	],
} as const;
