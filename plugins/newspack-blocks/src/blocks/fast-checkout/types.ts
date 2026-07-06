export type ProductField = 'title' | 'short_description' | 'price' | 'price_raw' | 'image_url' | 'url';

export interface StoreApiProduct {
	id: number;
	name: string;
	short_description: string;
	price_html: string;
	prices?: { price?: string };
	images?: { src: string }[];
	permalink: string;
	variations?: number[];
}

export interface StoreApiVariation {
	id: number;
	attributes: { name: string; option: string }[];
}

export interface Variation {
	id: number;
	label: string;
}

export interface FastCheckoutAttributes {
	product?: string;
	variation?: string;
	is_variable: boolean;
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
}
