/**
 * Shared types for the Advertising wizard.
 */

/**
 * WordPress dependencies.
 */
import type { APIFetchOptions } from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import type { WithWizardInjectedProps } from '../../../packages/components/src/with-wizard';

declare global {
	interface Window {
		newspack_ads_wizard: {
			iab_sizes: Record< string, string >;
			media_kit_page_edit_url: string;
			media_kit_page_status: string;
			can_connect_google: boolean;
		};
	}
	const newspack_ads_wizard: Window[ 'newspack_ads_wizard' ];
}

/**
 * A [ width, height ] size pair. Entries can be strings while being edited in
 * the size control's text fields.
 */
export type AdUnitSizeDimensions = ( number | string )[];

/**
 * A size option: a dimensions pair, or the special fluid size.
 */
export type AdUnitSizeOption = AdUnitSizeDimensions | 'fluid';

/**
 * A parent ad unit within an ad unit's GAM path.
 */
export type AdUnitPathItem = {
	id?: number | string;
	name?: string;
	code: string;
};

/**
 * A Google Ad Manager ad unit.
 */
export type AdUnit = {
	id: number | string;
	name: string;
	code: string;
	/**
	 * Mostly dimension pairs, but the special 'fluid' size can end up in the
	 * list too (the ad unit screen offers it among the addable size options).
	 */
	sizes: AdUnitSizeOption[];
	fluid?: boolean;
	status?: string;
	is_default?: boolean;
	is_legacy?: boolean;
	ad_service?: string;
	path?: AdUnitPathItem[];
};

/**
 * Connection status of the Google Ad Manager service.
 */
export type GoogleAdManagerStatus = {
	connected?: boolean;
	connection_mode?: string;
	network_code?: string;
	is_network_code_matched?: boolean;
	error?: string;
};

/**
 * Google Ad Manager service data, as returned by the billboard wizard API.
 * Only `status` is present before the first fetch completes.
 */
export type GoogleAdManagerServiceData = {
	status: GoogleAdManagerStatus;
	label?: string;
	enabled?: boolean;
	available?: boolean;
	network_code?: string;
	parent_network_code?: string;
	parent_ad_unit_id?: number | string;
	available_networks?: { code: number | string; name: string }[];
	created_targeting_keys?: string[];
};

/**
 * State and information for each advertising service.
 */
export type AdvertisingServices = {
	google_ad_manager: GoogleAdManagerServiceData;
};

/**
 * The /newspack/v1/wizard/billboard REST response.
 */
export type AdvertisingResponse = {
	services: AdvertisingServices;
	ad_units: AdUnit[];
	parent_ad_units: AdUnit[];
	error?: unknown;
};

/**
 * Advertising data held in the wizard state.
 */
export type AdvertisingData = {
	adUnits: Record< string, AdUnit >;
	parentAdUnits?: AdUnit[];
	services: AdvertisingServices;
	suppression?: boolean;
};

/**
 * Ads suppression configuration. Term ids can transiently be undefined when a
 * selected term carries no id.
 */
export type SuppressionConfig = {
	post_types?: string[];
	tags?: ( number | string | undefined )[];
	categories?: ( number | string | undefined )[];
	tag_archive_pages?: boolean;
	category_archive_pages?: boolean;
	author_archive_pages?: boolean;
};

/**
 * Generic error shape surfaced by REST request rejections.
 */
export type AdsApiError = {
	message?: string;
};

/**
 * The wizardApiFetch function injected by the withWizard HOC.
 */
export type AdsWizardApiFetch = WithWizardInjectedProps[ 'wizardApiFetch' ];

/**
 * The root wizard's API helper, passed down to screens. Fetches, then updates
 * the wizard state with the response.
 */
export type UpdateWithAPI = ( requestConfig: APIFetchOptions & { quiet?: boolean } ) => Promise< unknown >;

/**
 * Re-fetches the advertising data, optionally without the loading UI.
 */
export type FetchAdvertisingData = ( quiet?: boolean ) => Promise< unknown >;

/**
 * Enables or disables an advertising service.
 */
export type ToggleService = ( service: string, enabled: boolean ) => Promise< unknown >;

/**
 * An ad provider's selectable ad unit.
 */
export type ProviderUnit = {
	name: string;
	value: string;
	sizes: AdUnitSizeDimensions[];
};

/**
 * An advertising provider (Google Ad Manager, Broadstreet, …).
 */
export type Provider = {
	id: string;
	name: string;
	units?: ProviderUnit[];
};

/**
 * A header bidding partner.
 */
export type Bidder = {
	name: string;
	ad_sizes: number[][];
};

/**
 * Data of a placement or of one of its hooks: the provider/ad-unit/bidders
 * selection, plus placement-level flags.
 */
export type PlacementData = {
	provider?: string;
	ad_unit?: string;
	bidders_ids?: Record< string, string >;
	enabled?: boolean;
	stick_to_top?: boolean;
	hooks?: Record< string, PlacementData >;
};

/**
 * A placement's hook descriptor.
 */
export type PlacementHook = {
	name: string;
	hook_name: string;
};

/**
 * A global ad placement, as returned by the newspack-ads placements API.
 * The API guarantees `name`, `description`, `hook_name`, `supports` and
 * `data` via `wp_parse_args` defaults.
 */
export type Placement = {
	name: string;
	description: string;
	hook_name: string;
	hooks?: Record< string, PlacementHook >;
	supports: string[];
	data: PlacementData;
};
