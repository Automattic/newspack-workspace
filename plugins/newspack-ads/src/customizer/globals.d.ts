/**
 * Ambient declarations for the WP Customizer JS API (`wp.customize`) and the
 * minimal jQuery surface used by the customizer control/preview scripts.
 *
 * These scripts run standalone inside the Customizer's control/preview
 * frames (not through the block editor), where `wp.customize` and jQuery are
 * provided by WordPress core as globals. The workspace does not ship @types
 * for either, so only the subset used here is declared.
 *
 * Global-script form (no top-level imports/exports), so declarations land in
 * the global scope.
 */

/** A Customizer setting bound to a `newspack_ads_placement` control. */
interface NewspackCustomizeSetting {
	get: () => string;
	set: ( value: string ) => void;
}

/** A single Customizer control. Only the `newspack_ads_placement` shape is used here. */
interface NewspackCustomizeControl {
	container: NewspackCustomizerJQuery;
	params: { type: string };
	setting: NewspackCustomizeSetting;
}

interface NewspackCustomizeSection {
	controls: () => NewspackCustomizeControl[];
}

interface NewspackCustomizePanel {
	sections: () => NewspackCustomizeSection[];
}

/** A selective-refresh partial passed to the preview's render callback. */
interface NewspackCustomizeSelectiveRefreshPlacement {
	container: NewspackCustomizerJQuery;
}

interface NewspackCustomizeAPI {
	bind: ( event: 'ready', callback: () => void ) => void;
	panel: ( id: string ) => NewspackCustomizePanel;
	selectiveRefresh?: {
		bind: ( event: 'partial-content-rendered', callback: ( placement: NewspackCustomizeSelectiveRefreshPlacement ) => void ) => void;
	};
}

/**
 * The subset of `window.wp` used across this plugin's standalone scripts.
 * A named (rather than inline) interface so other files can declaration-merge
 * additional members into it -- e.g. `src/types/index.d.ts` adds `domReady`
 * and `blocks` for the block-registration files. Re-declaring `Window['wp']`
 * itself with a different inline shape in more than one file is a TS error
 * ("subsequent property declarations must have the same type"), so all
 * `window.wp` members belong on this interface instead.
 */
interface NewspackAdsWpGlobal {
	customize: NewspackCustomizeAPI;
}

/** A jQuery event object (subset used by these entries). */
interface NewspackCustomizerJQueryEvent {
	preventDefault: () => void;
}

/**
 * A jQuery set. The workspace does not ship @types/jquery, so this declares
 * only the minimal surface used by the customizer control/preview scripts.
 */
interface NewspackCustomizerJQuery {
	readonly length: number;
	[ index: number ]: HTMLElement;
	find: ( selector: string ) => NewspackCustomizerJQuery;
	each: ( callback: ( this: HTMLElement, index: number, element: HTMLElement ) => void ) => NewspackCustomizerJQuery;
	hide: () => NewspackCustomizerJQuery;
	show: () => NewspackCustomizerJQuery;
	addClass: ( className: string ) => NewspackCustomizerJQuery;
	removeClass: ( className: string ) => NewspackCustomizerJQuery;
	is: ( selector: string ) => boolean;
	attr: ( name: string, value: string | boolean ) => NewspackCustomizerJQuery;
	val: ( () => string | undefined ) & ( ( value: string ) => NewspackCustomizerJQuery );
	data: ( key: string ) => unknown;
	change: () => NewspackCustomizerJQuery;
	ready: ( handler: () => void ) => NewspackCustomizerJQuery;
	on: ( ( events: string, handler: ( this: HTMLElement, event: NewspackCustomizerJQueryEvent ) => void ) => NewspackCustomizerJQuery ) &
		( (
			events: string,
			selector: string,
			handler: ( this: HTMLElement, event: NewspackCustomizerJQueryEvent ) => void
		) => NewspackCustomizerJQuery );
}

interface NewspackCustomizerJQueryStatic {
	( selector: string | HTMLElement | Document ): NewspackCustomizerJQuery;
}

interface Window {
	wp: NewspackAdsWpGlobal;
	jQuery: NewspackCustomizerJQueryStatic;
}

// eslint-disable-next-line no-var
declare var wp: Window[ 'wp' ];
