declare global {
	interface Window {
		/**
		 * Localized strings for menu/comment accessibility, injected via
		 * wp_localize_script( 'newspack-main', 'newspackScreenReaderText', ... )
		 * in includes/class-core.php.
		 */
		newspackScreenReaderText: {
			close_menu: string;
			comment_too_fast: string;
		};
	}
}

export {};
