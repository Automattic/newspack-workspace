/**
 * Ambient declarations for browser globals shared by multiple standalone entries
 * (newspack-ui, plugins-screen, nicename-change, group-subscription, utils).
 *
 * Global-script form (no top-level imports/exports), so every declaration lands in
 * the global scope and remains open for interface merging from unit-local files.
 */

/*
 * The reader-activation contract (NewspackReaderActivation, NewspackRASQueueItem
 * and window.newspackRAS) is declared canonically in
 * newspack-scripts/types/newspack-globals.d.ts, included via tsconfig.
 */

interface Window {
	/** Shared AuthorContext published by newspack-blocks (author-profile block). */
	NewspackAuthorContext?: import( 'react' ).Context< Record< string, unknown > | null >;
}

/**
 * WordPress admin globals exposed by core scripts (`wp-util`/`wp-ajax-response`,
 * `wp-i18n`, `wp-hooks`). Only the subset used by these entries is declared.
 *
 * Declared as an interface + `var` (not an inline-typed `const`) so other units
 * can merge additional members they load via their own script dependencies
 * (e.g. newspack-components' image-upload merges `media`).
 */
interface NewspackWpGlobal {
	ajax: {
		send: ( action: string, options: { data: Record< string, unknown > } ) => void;
	};
	i18n: {
		__: ( text: string, domain?: string ) => string;
	};
	hooks?: {
		addAction: ( hookName: string, namespace: string, callback: ( ...args: unknown[] ) => void ) => void;
	};
}

// eslint-disable-next-line no-var
declare var wp: NewspackWpGlobal;

/**
 * A jQuery event object (subset used by these entries).
 */
interface NewspackJQueryEvent {
	preventDefault(): void;
	currentTarget: HTMLElement;
	keyCode?: number;
}

/**
 * A jQuery set. The workspace does not ship @types/jquery, so this declares only
 * the minimal surface used by Newspack admin scripts. Extend via interface
 * merging (e.g. group-subscription adds `select2`).
 */
interface NewspackJQuery {
	length: number;
	addClass( className: string ): NewspackJQuery;
	removeClass( className: string ): NewspackJQuery;
	closest( selector: string ): NewspackJQuery;
	find( selector: string ): NewspackJQuery;
	parent(): NewspackJQuery;
	last(): NewspackJQuery;
	remove(): NewspackJQuery;
	is( selector: string ): boolean;
	prop( propertyName: string, value: boolean ): NewspackJQuery;
	attr( attributeName: string, value: string | boolean ): NewspackJQuery;
	html( htmlString: string ): NewspackJQuery;
	text(): string;
	text( value: string | number ): NewspackJQuery;
	val(): string | undefined;
	val( value: string | null ): NewspackJQuery;
	data( key: string ): string | number | undefined;
	data( key: string, value: string | number ): NewspackJQuery;
	show(): NewspackJQuery;
	hide(): NewspackJQuery;
	trigger( eventType: string ): NewspackJQuery;
	append( content: string ): NewspackJQuery;
	before( content: string ): NewspackJQuery;
	after( content: string ): NewspackJQuery;
	on( events: string, handler: ( this: HTMLElement, event: NewspackJQueryEvent ) => void ): NewspackJQuery;
	on( events: string, selector: string, handler: ( event: NewspackJQueryEvent ) => void ): NewspackJQuery;
	ready( handler: () => void ): NewspackJQuery;
}

/**
 * The jQuery entry point (subset used by Newspack admin scripts).
 */
interface NewspackJQueryStatic {
	( selector: string | HTMLElement | Document | EventTarget ): NewspackJQuery;
	ajax< TResponse >( settings: {
		type?: string;
		url: string;
		data?: Record< string, unknown >;
		success?: ( response: TResponse ) => void;
	} ): void;
}

declare const jQuery: NewspackJQueryStatic;
