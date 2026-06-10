/**
 * Per-tab progressive loading copy (NPPD-1684).
 *
 * Each tab names the backend operation so the wait is informative rather than a
 * bare spinner. Consumed by TabStateView's initial-load frame (six GA4 tabs);
 * Advertising consumes its entry from the async `is_loading` GAM-report state
 * instead, where the genuinely long wait happens. Delays are absolute ms from
 * the start of the load — see useProgressiveMessages for the cadence.
 */

export const TAB_LOADING_MESSAGES = {
	audience: [
		{ text: 'Loading reader data…', delay: 0 },
		{ text: 'Counting unique readers…', delay: 250 },
		{ text: 'Mapping where they come from…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	engagement: [
		{ text: 'Loading engagement data…', delay: 0 },
		{ text: 'Measuring engagement signals…', delay: 250 },
		{ text: 'Analyzing reading patterns…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	gates: [
		{ text: 'Loading gate performance…', delay: 0 },
		{ text: 'Querying gate impressions…', delay: 250 },
		{ text: 'Joining with WooCommerce orders…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	prompts: [
		{ text: 'Loading prompt performance…', delay: 0 },
		{ text: 'Querying prompt impressions…', delay: 250 },
		{ text: 'Joining with WooCommerce orders…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	subscribers: [
		{ text: 'Loading subscriber data…', delay: 0 },
		{ text: 'Counting active subscribers…', delay: 250 },
		{ text: 'Calculating MRR…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	donors: [
		{ text: 'Loading donor data…', delay: 0 },
		{ text: 'Counting active donors…', delay: 250 },
		{ text: 'Calculating contributions…', delay: 6000 },
		{ text: 'Almost there…', delay: 12000 },
	],
	advertising: [
		{ text: 'Loading ad performance…', delay: 0 },
		{ text: 'Submitting GAM report…', delay: 250 },
		{ text: 'Waiting for ad server…', delay: 6000 },
		{ text: 'Processing ad data…', delay: 12000 },
	],
} as const;
