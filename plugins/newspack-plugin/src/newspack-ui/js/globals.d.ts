/**
 * Ambient declarations for the newspack-ui unit and the admin browser globals
 * its scripts (and the My Account / custom-product-options scripts that depend
 * on it) read. Global-script form (no top-level imports/exports), so every
 * declaration lands in the global scope and the Window merge applies globally.
 */

/** The notices API exposed by the newspack-ui entry (src/newspack-ui/js/notices). */
interface NewspackUINotices {
	openNotice( element: HTMLElement, remove?: boolean ): void;
	closeNotice( element: HTMLElement, remove?: boolean ): void;
	createNotice( message: string, type?: string ): HTMLElement;
}

interface Window {
	/** Public API published by the newspack-ui entry (src/newspack-ui/js/index). */
	newspackUI?: {
		notices?: NewspackUINotices;
		announce?: ( message: string, assertive: boolean ) => void;
	};
	/**
	 * Icon markup and labels printed by PHP for notices and buttons built in JS.
	 * Absent until the newspack-ui assets localize it, so reads guard with `?.`.
	 */
	newspackUIData?: {
		noticeIcons?: Record< string, string >;
		closeIcon?: string;
		closeLabel?: string;
	};
	/** jQuery, present whenever a script declaring it as a dependency runs. */
	jQuery?: NewspackJQueryStatic;
}

/**
 * WordPress admin globals exposed by core scripts. Only the subset these
 * entries read is declared.
 */
interface NewspackWpGlobal {
	ajax: {
		send( action: string, options: { data: Record< string, unknown > } ): void;
	};
}

// eslint-disable-next-line no-var
declare var wp: NewspackWpGlobal;

/** A jQuery event object (subset read by these entries). */
interface NewspackJQueryEvent {
	preventDefault(): void;
	target: HTMLElement;
	currentTarget: HTMLElement;
}

/**
 * A select2 instance, as read off a jQuery set via `.data( 'select2' )` by the
 * modal script that keeps WooCommerce's country/state dropdowns inside a dialog.
 */
interface NewspackSelect2Data {
	dropdown?: {
		$dropdownParent?: NewspackJQuery;
	};
}

/**
 * A jQuery set. The workspace ships no @types/jquery, so this declares only the
 * minimal surface the Newspack admin scripts use.
 */
interface NewspackJQuery {
	closest( selector: string ): NewspackJQuery;
	find( selector: string ): NewspackJQuery;
	is( selector: string ): boolean;
	val(): string | undefined;
	show(): NewspackJQuery;
	hide(): NewspackJQuery;
	toggle( display: boolean ): NewspackJQuery;
	trigger( eventType: string ): NewspackJQuery;
	ready( handler: () => void ): NewspackJQuery;
	data( key: 'select2' ): NewspackSelect2Data | undefined;
	data( key: string ): unknown;
	on( events: string, handler: ( this: HTMLElement, event: NewspackJQueryEvent ) => void ): NewspackJQuery;
	on( events: string, selector: string, handler: ( this: HTMLElement, event: NewspackJQueryEvent ) => void ): NewspackJQuery;
}

/** The jQuery entry point (subset used by Newspack admin scripts). */
interface NewspackJQueryStatic {
	( selector: string | HTMLElement | Document | EventTarget | NewspackJQuery ): NewspackJQuery;
}

declare const jQuery: NewspackJQueryStatic;
