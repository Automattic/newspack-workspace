/**
 * Shared types for the Collections block.
 */

/**
 * Attributes of the Collections block. All attributes have block.json
 * defaults, so they are always present.
 */
export type CollectionsAttributes = {
	queryType: string;
	numberOfItems: number;
	offset: number;
	selectedCollections: Array< string | number >;
	includeCategories: Array< string | number >;
	excludeCategories: Array< string | number >;
	layout: string;
	columns: number;
	imageAlignment: string;
	imageSize: string;
	showFeaturedImage: boolean;
	showTitle: boolean;
	showCategory: boolean;
	showExcerpt: boolean;
	showPeriod: boolean;
	showVolume: boolean;
	showNumber: boolean;
	showCTAs: boolean;
	numberOfCTAs: number;
	showSubscriptionUrl: boolean;
	showOrderUrl: boolean;
	specificCTAs: string;
};

/**
 * A call-to-action attached to a collection by the REST API.
 */
export type CollectionCta = {
	label: string;
	url?: string;
};

/**
 * A term embedded in a collection REST response.
 */
export type CollectionTerm = {
	id: number;
	name: string;
	taxonomy?: string;
};

/**
 * Featured media embedded in a collection REST response.
 */
export type CollectionFeaturedMedia = {
	alt_text?: string;
	media_details?: {
		sizes?: {
			full?: {
				source_url?: string;
			};
		};
	};
};

/**
 * A newspack_collection post as returned by the REST API with _embed.
 */
export type Collection = {
	id: number;
	title?: {
		rendered?: string;
	};
	excerpt?: {
		rendered?: string;
	};
	content?: {
		rendered?: string;
	};
	meta?: {
		newspack_collection_volume?: string | number;
		newspack_collection_number?: string | number;
		newspack_collection_period?: string;
	};
	ctas?: CollectionCta[];
	_embedded?: {
		'wp:featuredmedia'?: CollectionFeaturedMedia[];
		'wp:term'?: CollectionTerm[][];
	};
};
