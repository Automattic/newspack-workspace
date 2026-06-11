/**
 * Ambient declarations for the Insights wizard.
 *
 * Window globals only — concrete shapes live alongside their consumers.
 */

import type { CacheInternals } from './state/insightsCache';

declare global {
	interface Window {
		/**
		 * Cross-chunk shared state for insightsCache. Each lazy-loaded tab
		 * chunk gets its own copy of state/insightsCache.ts; anchoring the
		 * Maps on `window` keeps subscribers in the main chunk in sync with
		 * refreshes fired from a tab chunk.
		 */
		__newspackInsightsCache?: CacheInternals;
	}
}

export {};
