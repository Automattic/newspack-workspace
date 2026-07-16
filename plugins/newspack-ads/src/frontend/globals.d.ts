/**
 * Ambient declarations for the Google Publisher Tag (GPT) global and the
 * nonstandard `_size` property GPT stamps onto rendered ad slot elements.
 *
 * The workspace does not ship @types for GPT, so only the minimal surface
 * used by the side-rail placement script is declared.
 *
 * Global-script form (no top-level imports/exports), so declarations land in
 * the global scope.
 */

interface NewspackGoogletagSlot {
	getSlotElementId: () => string;
}

interface NewspackGoogletagEvent {
	slot: NewspackGoogletagSlot;
	size: [ number, number ];
}

interface NewspackGoogletagPubads {
	addEventListener: ( eventName: 'slotRenderEnded', listener: ( event: NewspackGoogletagEvent ) => void ) => void;
}

interface NewspackGoogletag {
	cmd: Array< () => void >;
	/**
	 * Optional because the pre-load stub (`window.googletag || { cmd: [] }`) only
	 * carries `cmd` -- GPT replaces/extends the global with the full API (including
	 * `pubads`) once it loads, which is always true by the time a `cmd` callback runs.
	 */
	pubads?: () => NewspackGoogletagPubads;
}

interface Window {
	googletag: NewspackGoogletag;
}

interface HTMLElement {
	/** Set by GPT on an ad slot's container element once a creative has rendered. */
	_size?: [ number, number ];
}
