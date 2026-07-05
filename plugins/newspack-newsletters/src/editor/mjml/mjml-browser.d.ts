/**
 * No `@types/mjml` (or `@types/mjml-browser`) package exists. `mjml-browser` re-exports the same
 * `mjml2html( mjmlString, options ) => { html }` API as the `mjml` package (per its own README),
 * so only that shape is declared here -- just the parts `mjml/index.ts` actually uses.
 */
declare module 'mjml-browser' {
	interface MJML2HTMLOptions {
		keepComments?: boolean;
		minify?: boolean;
	}
	interface MJML2HTMLResult {
		html: string;
	}
	export default function mjml2html( mjml: string, options?: MJML2HTMLOptions ): MJML2HTMLResult;
}
