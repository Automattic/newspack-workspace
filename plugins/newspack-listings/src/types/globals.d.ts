/**
 * Ambient globals for newspack-listings: window-scoped data localized by PHP
 * (`includes/class-blocks.php`'s `wp_localize_script( 'newspack-listings-editor', 'newspack_listings_data', ... )`),
 * plus a Gutenberg-provided block editor ready-promise used by the editor bootstrap.
 *
 * This is a global script (no top-level imports/exports), so the `Window`
 * augmentation below applies across the whole unit.
 */
interface NewspackListingsPostTypeInfo {
	name: string;
	label: string;
	show_in_inserter: boolean;
}

interface NewspackListingsPostTypes {
	event: NewspackListingsPostTypeInfo;
	generic: NewspackListingsPostTypeInfo;
	marketplace: NewspackListingsPostTypeInfo;
	place: NewspackListingsPostTypeInfo;
}

interface NewspackListingsSelfServeListingType {
	slug: string;
	name: string;
}

interface NewspackListingsData {
	post_type_label: string;
	post_type: string | false;
	post_type_slug: string | false;
	post_types: NewspackListingsPostTypes;
	currency: string;
	currencies: Record< string, string >;
	no_listings: boolean;
	date_format: string;
	time_format: string;
	self_serve_enabled: boolean;
	self_serve_listing_types?: NewspackListingsSelfServeListingType[];
	self_serve_listing_expiration?: number;
	is_listing_customer?: boolean;
}

interface Window {
	newspack_listings_data: NewspackListingsData;
	/**
	 * Resolves once the Gutenberg block editor UI has fully loaded. Provided by
	 * WordPress core (wp-edit-post), not typed upstream.
	 */
	_wpLoadBlockEditor?: Promise< void >;
}
