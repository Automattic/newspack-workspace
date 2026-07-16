/**
 * Types for the Brands admin screen: the `brand` custom taxonomy term as
 * returned by (and posted to) the `/wp/v2/brand` REST endpoint.
 */

/**
 * A single theme color override for a brand (see `_theme_colors` meta,
 * registered in `class-theme-colors.php`).
 */
export interface BrandThemeColorOverride {
	name: string;
	color: string;
}

/**
 * A nav menu assignment for a brand (see `_menus` meta).
 */
export interface BrandMenuAssignment {
	location: string;
	menu: number;
}

/**
 * The brand's logo: a raw attachment ID as stored in `_logo` post meta, or
 * (once resolved via `fetchLogoAttachment`, or freshly selected via
 * `ImageUpload`) the attachment object with at least `id`/`url`.
 */
export interface BrandLogo {
	id: number;
	url: string;
	[ key: string ]: unknown;
}

export interface BrandMeta {
	// Typed `string` (not the `'yes' | 'no'` literal union the two option values
	// actually take) because it round-trips through `RadioControl`'s `onChange`,
	// which is typed generically as `(value: string) => void`.
	_custom_url: string;
	// `null` is included because `ImageUpload`'s `onChange` passes `null` when
	// the logo is removed.
	_logo?: number | BrandLogo | null;
	_theme_colors?: BrandThemeColorOverride[] | null;
	_menus?: BrandMenuAssignment[] | null;
	_show_page_on_front?: number;
}

/**
 * A brand as returned by the `/wp/v2/brand` REST endpoint.
 */
export interface Brand {
	id: number;
	name: string;
	slug: string;
	meta: BrandMeta;
}

/**
 * The Brand screen's local editing state: a brand that may not exist yet
 * (new-brand form), so `id`/`name` aren't guaranteed until the form is valid.
 */
export type BrandFormState = Omit< Brand, 'id' | 'name' > & Partial< Pick< Brand, 'id' | 'name' > >;

/**
 * An attachment as returned by the `/wp/v2/media/<id>` REST endpoint (only
 * the fields this unit reads).
 */
export interface MediaAttachment {
	id: number;
	source_url: string;
	[ key: string ]: unknown;
}
