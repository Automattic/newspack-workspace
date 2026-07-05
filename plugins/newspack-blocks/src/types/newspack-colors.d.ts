// `newspack-colors` (packages/colors) is intentionally kept as buildless JS: its
// package.json "main" points at `colors.module.scss`, a Sass file that exposes its
// color tokens to JavaScript via a CSS Modules `:export` block (see
// packages/colors/colors.module.scss). At build time this resolves to a plain object
// of color-token-name to CSS color string (e.g. `{ 'primary-600': '#003da5', ... }`).
// This declaration types that runtime shape without converting the package itself.
declare module 'newspack-colors' {
	const colors: Record< string, string >;
	export default colors;
}
