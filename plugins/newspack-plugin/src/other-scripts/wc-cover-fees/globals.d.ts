/**
 * Ambient declarations for the `wc-cover-fees` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * jQuery surface used by this entry, merged into the shared minimal typing
 * (src/shared/globals.d.ts): Woo checkout events are triggered with extra data.
 */
interface NewspackJQuery {
	trigger( eventType: string, extraParameters: Record< string, unknown > ): NewspackJQuery;
}
