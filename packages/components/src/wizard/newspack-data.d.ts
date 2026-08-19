/**
 * Globals localized by newspack-plugin (`Wizard::enqueue_scripts()`, via
 * `wp_localize_script`) on every Newspack wizard admin page, where the
 * wizard components are mounted. Guard with `typeof x !== 'undefined'`
 * when a component can render outside a wizard page.
 */

declare const newspack_urls: {
	/** URL of the Newspack dashboard. */
	dashboard: string;
	/** Public path of the plugin's built assets. */
	public_path: string;
	bloginfo: {
		name: string;
	};
	plugin_version: {
		label: string;
	};
	/** Edit link of the homepage, if set. */
	homepage: string | null;
	/** The site URL. */
	site: string;
	/** Newspack support site URL. */
	support: string;
	/** Support email address, if configured. */
	support_email: string | false;
	/** Present for admins on sites with starter content. */
	remove_starter_content?: string;
	/** Present for admins in debug mode. */
	components_demo?: string;
	/** Present for admins in debug mode. */
	setup_wizard?: string;
	/** Present for admins in debug mode. */
	reset_url?: string;
};

declare const newspack_aux_data: {
	is_e2e: boolean;
	is_debug_mode: boolean;
	has_completed_setup: boolean;
	site_title: string;
	/** Whether the site is connected to the Newspack Manager. */
	is_managed: boolean;
	/** Site-wide gating notice shown across the wizards when access enforcement is inert. */
	inert_gating?: {
		show?: boolean;
		message: string;
		urls: {
			accessControl: string;
			audience: string;
		};
	};
};
