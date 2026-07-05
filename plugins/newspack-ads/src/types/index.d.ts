declare global {
	interface Window {
		/**
		 * Base URL for this plugin's split editor asset bundle, localized when
		 * the editor script is enqueued as a Jetpack-style block-assets bundle.
		 * Read by setup/public-path to set webpack's runtime publicPath.
		 * Optional because it's only present when the site loads the editor
		 * bundle through that mechanism.
		 */
		Jetpack_Block_Assets_Base_Url?: string;
	}

	/**
	 * The subset of the WordPress admin `wp` global (window.wp) used by this
	 * plugin's block registration files, which intentionally call these
	 * outside of `@wordpress/*` ES module imports (`wp.domReady` gates
	 * registration until core filters are ready; `wp.blocks.registerBlockType`
	 * is called via the shared `registerBlock()` helper). The workspace does
	 * not ship @types/wordpress__* globals, so this re-declares only the
	 * members actually called.
	 *
	 * `NewspackAdsWpGlobal` itself (and the `declare var wp`) is declared in
	 * `../customizer/globals.d.ts`; this merges the block-editor members into
	 * that same interface rather than redeclaring `Window['wp']` or `wp`
	 * again, which TS rejects if the shape differs between declarations.
	 */
	interface NewspackAdsWpGlobal {
		domReady: ( callback: () => void ) => void;
		blocks: {
			registerBlockType: ( name: string, settings: Record< string, unknown > ) => unknown;
		};
	}
}

export {};
