/**
 * Ambient declarations for the plugins-screen entry.
 *
 * Global-script form (no top-level imports/exports) so the declarations land in
 * the global scope and merge with `src/shared/globals.d.ts`.
 */

/**
 * Payload localized by Admin_Plugins_Screen::enqueue_scripts_and_styles() as
 * `newspack_plugin_info`.
 */
declare const newspack_plugin_info: {
	plugins: string[];
	installed_plugins: string[];
	screen: string;
	plugin_review_link: string | null;
	approved_plugins_list_link: string | null;
};
