/**
 * Ambient declarations for the WP Customizer JS API (`wp.customize`) and the
 * minimal jQuery surface used by this theme's customizer control/preview
 * scripts (`customize-controls.ts`, `customize-preview.ts`,
 * `logo-customize-controls.ts`, `logo-customize-preview.ts`).
 *
 * These scripts run standalone inside the Customizer's control/preview
 * frames (not through the block editor), where `wp.customize` and `jQuery`
 * are provided by WordPress core as bare globals. The workspace does not
 * ship @types for either, so only the subset actually used by these files is
 * declared here.
 *
 * Global-script form (no top-level imports/exports), so declarations land in
 * the global scope.
 */

/** A Customizer setting/value. Callable as a getter/setter, per the real API. */
interface NewspackCustomizeValue< T = unknown > {
	(): T;
	( value: T ): void;
	get(): T;
	set( value: T ): void;
	bind( callback: ( to: T, from?: T ) => void ): void;
	preview(): void;
}

/** A single Customizer control. */
interface NewspackCustomizeControl< T = unknown > {
	container: NewspackThemeJQuery;
	setting: NewspackCustomizeValue< T >;
	deactivate(): void;
	activate(): void;
}

/** The subset of `wp.customize.section`/`wp.customize.panel` used here. */
interface NewspackCustomizeSectionOrPanelAPI {
	has: ( id: string ) => boolean;
	instance: ( id: string ) => { focus: () => void };
}

/**
 * `wp.customize.control` is both callable (register a callback, or fetch the
 * control synchronously when called with just an `id`) and carries
 * `has`/`instance`, same as `section`/`panel`.
 */
interface NewspackCustomizeControlAPI extends NewspackCustomizeSectionOrPanelAPI {
	( id: string, callback: ( control: NewspackCustomizeControl ) => void ): void;
	( id: string ): NewspackCustomizeControl;
}

interface NewspackCustomizeAPI {
	< T = unknown >( id: string, callback: ( setting: NewspackCustomizeValue< T > ) => void ): void;
	bind: ( event: 'ready', callback: () => void ) => void;
	control: NewspackCustomizeControlAPI;
	section: NewspackCustomizeSectionOrPanelAPI;
	panel: NewspackCustomizeSectionOrPanelAPI;
	value: ( id: string ) => NewspackCustomizeValue;
}

/** A jQuery click/DOM event (subset used by these entries). */
interface NewspackThemeJQueryEvent {
	preventDefault: () => void;
}

/**
 * A jQuery set. The workspace does not ship @types/jquery, so this declares
 * only the minimal surface used by these customizer control/preview scripts.
 */
interface NewspackThemeJQuery< T = HTMLElement > {
	readonly length: number;
	each: ( callback: ( index: number, item: T ) => void ) => this;
	click: ( handler: ( this: HTMLElement, event: NewspackThemeJQueryEvent ) => void ) => this;
	attr: ( name: string ) => string;
	css: ( ( name: string ) => string ) & ( ( props: Record< string, string | number > ) => NewspackThemeJQuery );
	addClass: ( className: string ) => this;
	removeClass: ( className: string ) => this;
	hide: () => this;
	show: () => this;
	slideDown: ( duration: number ) => this;
	slideUp: ( duration: number ) => this;
	load: ( handler: () => void ) => this;
	resize: () => this;
	iris: ( options: { palettes: string[] } ) => this;
}

interface NewspackThemeJQueryStatic {
	( selector: string ): NewspackThemeJQuery;
	( element: HTMLElement ): NewspackThemeJQuery;
	( target: Window ): NewspackThemeJQuery;
	( target: Document ): NewspackThemeJQuery;
	( items: readonly [ 'control', 'section', 'panel' ] ): NewspackThemeJQuery< 'control' | 'section' | 'panel' >;
	isNumeric: ( value: unknown ) => boolean;
}

interface Window {
	wp: { customize: NewspackCustomizeAPI };
	jQuery: NewspackThemeJQueryStatic;
}

// eslint-disable-next-line no-var
declare var wp: Window[ 'wp' ];
// eslint-disable-next-line no-var
declare var jQuery: Window[ 'jQuery' ];
