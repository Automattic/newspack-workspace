/**
 * Dynamically set WebPack's publicPath so that split assets can be found.
 *
 * @see https://webpack.js.org/guides/public-path/#on-the-fly
 */

// Webpack's free variable for setting publicPath at runtime. The workspace does not
// ship @types/webpack-env, so it's declared here; assigning it is this file's whole
// purpose (webpack reads it, so it is write-only from TS's perspective). `var` (not
// `let`) so a duplicate ambient declaration elsewhere merges instead of colliding.
// eslint-disable-next-line no-var
declare var __webpack_public_path__: string;

if ( typeof window === 'object' && window.Jetpack_Block_Assets_Base_Url ) {
	// eslint-disable-next-line no-global-assign, @typescript-eslint/no-unused-vars
	__webpack_public_path__ = window.Jetpack_Block_Assets_Base_Url;
}
