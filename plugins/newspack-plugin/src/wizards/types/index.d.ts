/**
 * Allow image to be imported as a modules
 */
declare module '*.png' {
	const path: string;
	export default path;
}

/**
 * Wizard API fetch function
 */
type WizardApiFetch< T = {} > = (
	options: ApiFetchOptions,
	callbacks?: ApiFetchCallbacks< any >
) => Promise< T >;

/**
 * WP REST API Error.
 */
type WpRestApiError = {
	code: string;
	message: string;
	data: {
		status: number;
		params: Record< string, string >;
	};
};

/**
 * Attachment object interface.
 */
interface Attachment {
	id: number;
	date: string;
	date_gmt: string;
	guid: {
		rendered: string;
	};
	modified: string;
	modified_gmt: string;
	slug: string;
	status: string;
	type: string;
	link: string;
	title: {
		rendered: string;
	};
	author: number;
	featured_media: number;
	comment_status: string;
	ping_status: string;
	template: string;
	meta: {
		newspack_ads_suppress_ads: boolean;
		newspack_popups_has_disabled_popups: boolean;
		newspack_sponsor_sponsorship_scope: string;
		newspack_sponsor_native_byline_display: string;
		newspack_sponsor_native_category_display: string;
		newspack_sponsor_underwriter_style: string;
		newspack_sponsor_underwriter_placement: string;
		_media_credit: string;
		_media_credit_url: string;
		_navis_media_credit_org: string;
		_navis_media_can_distribute: string;
	};
	class_list: string[];
	description: {
		rendered: string;
	};
	caption: {
		rendered: string;
	};
	alt_text: string;
	media_type: string;
}
