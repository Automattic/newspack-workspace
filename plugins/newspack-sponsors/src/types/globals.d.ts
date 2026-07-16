/**
 * Ambient globals for newspack-sponsors: window-scoped data localized by PHP
 * (`includes/class-editor.php`'s `wp_localize_script( 'newspack-sponsors-editor', 'newspack_sponsors_data', ... )`).
 *
 * This is a global script (no top-level imports/exports), so the `Window`
 * augmentation below applies across the whole unit.
 */
interface NewspackSponsorsSettings {
	byline: string;
	flag: string;
	disclaimer: string;
	suppress: boolean;
}

interface NewspackSponsorsData {
	post_type: string;
	settings: NewspackSponsorsSettings;
	defaults: NewspackSponsorsSettings;
	cpt: string;
	tax: string;
}

interface Window {
	newspack_sponsors_data: NewspackSponsorsData;
}
