/**
 * Unit-local window global for the standalone Settings admin page
 * (`src/settings`), localized by `Newspack_Popups_Settings::create_admin_page()`.
 * Only used when the main Newspack plugin's Engagement wizard UI isn't
 * available.
 *
 * This is a global script (no top-level imports/exports); inline `import()`
 * types only.
 */

/** An option for a `select`-type setting. */
interface PopupsSettingOption {
	label: string;
	value: string;
}

/**
 * A single setting entry, as built by `Newspack_Popups_Settings::get_settings()`.
 * `key: 'active'` entries are section headers (no `key` to persist, filtered
 * out of the rendered list by the `'active' !== setting.key` check).
 */
interface PopupsSetting {
	key: string;
	/** e.g. 'boolean' | 'string' | 'select'; drives which control is rendered. */
	type: string;
	value: string | boolean | number | null;
	description?: string;
	help?: string;
	section?: string;
	public?: boolean;
	default?: string | boolean;
	options?: PopupsSettingOption[];
}

declare const newspack_popups_settings: PopupsSetting[];
