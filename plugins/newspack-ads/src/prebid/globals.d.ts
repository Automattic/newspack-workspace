/**
 * Ambient declarations for `prebid.js`. The package ships no `.d.ts` (its
 * `package.json` only declares `main`, pointing at plain JS source), so only
 * the surface actually used by this entry is declared here.
 */
declare module 'prebid.js' {
	interface PrebidJS {
		/** Processes any `pbjs.que`/`pbjs.cmd` callbacks queued before this bundle loaded. */
		processQueue: () => void;
	}

	const pbjs: PrebidJS;
	export default pbjs;
}

/**
 * The bidder/RTD adapter modules below are imported only for their side effects
 * (each registers itself with the `pbjs` instance on import) -- no exports are
 * used, so each is declared as an empty ambient module.
 */
declare module 'prebid.js/modules/enrichmentFpdModule';
declare module 'prebid.js/modules/express';
declare module 'prebid.js/modules/medianetBidAdapter';
declare module 'prebid.js/modules/medianetRtdProvider';
declare module 'prebid.js/modules/openxBidAdapter';
declare module 'prebid.js/modules/pubmaticBidAdapter';
declare module 'prebid.js/modules/sovrnBidAdapter';
