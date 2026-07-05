/**
 * Localized by `Admin::enqueue_scripts()` (`wp_localize_script( ..., 'newspack_aux_data', $aux_data )`,
 * see `includes/class-admin.php`) on the multibranded-site admin page.
 *
 * Global script file: no top-level imports — an import would turn this file
 * into a module and strip the declaration below of its global scope.
 */
declare const newspack_aux_data: {
	/** Theme colors registered via the `newspack_multibranded_site_theme_colors` filter. */
	theme_colors: { theme_mod_name: string; label: string; default: string }[];
	/** Registered nav menu locations, keyed by location slug. */
	menu_locations: Record< string, string >;
	/** Available nav menus. */
	menus: { value: number; label: string }[];
	/** The site URL. */
	site: string;
};
