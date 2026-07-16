declare global {
	/**
	 * The subset of the WordPress admin `wp` global (window.wp) used by the
	 * editor block-style registration files (src/js/editor/block-styles/*),
	 * which intentionally call these outside of `@wordpress/*` ES module
	 * imports so each style file stays a tiny, standalone webpack entry. The
	 * workspace does not ship @types/wordpress__* globals, so this re-declares
	 * only the members actually called.
	 */
	interface NewspackBlockThemeWpGlobal {
		domReady: ( callback: () => void ) => void;
		blocks: {
			registerBlockStyle: (
				blockNames: string | string[],
				styleVariation: import('@wordpress/blocks').BlockStyle | import('@wordpress/blocks').BlockStyle[]
			) => void;
			unregisterBlockStyle: ( blockName: string, styleVariationName: string ) => void;
		};
	}

	interface Window {
		wp: NewspackBlockThemeWpGlobal;
	}

	/* eslint-disable-next-line no-var */
	var wp: Window[ 'wp' ];
}

export {};
