export type ProductField = 'title' | 'short_description' | 'price' | 'price_raw' | 'image_url' | 'url';

export interface StoreApiProduct {
	id: number;
	name: string;
	short_description: string;
	price_html: string;
	prices?: { price?: string; currency_minor_unit?: number };
	images?: { src: string }[];
	permalink: string;
	type?: 'simple' | 'variable' | 'grouped' | 'variation' | string;
	variations?: number[];
	grouped_products?: number[];
	extensions?: {
		name_your_price?: {
			minimum_price?: string;
			maximum_price?: string;
			suggested_price?: string;
			is_nyp?: boolean;
		};
	};
}

export interface StoreApiVariation {
	id: number;
	attributes: { name: string; option: string }[];
}

export interface Variation {
	id: number;
	label: string;
}

export interface GroupedChild {
	id: number;
	name: string;
	priceHtml: string;
}

export interface NypConfig {
	min: number;
	max: number;
	suggested: number;
}

export interface FastCheckoutAttributes {
	product?: string;
	variation?: string;
	is_variable: boolean;
	is_grouped: boolean;
	is_nyp: boolean;
	grouped_child?: string;
	nyp_price?: string;
	afterSuccessURL?: string;
}

export interface BindingArgs {
	field: ProductField;
}

export interface Binding {
	args?: BindingArgs;
}

export interface BindingsContext {
	'newspack-blocks/fastCheckoutProductId'?: string | number;
	'newspack-blocks/fastCheckoutVariationId'?: string | number;
	'newspack-blocks/fastCheckoutGroupedChild'?: string | number;
	'newspack-blocks/fastCheckoutNypPrice'?: string | number;
}
