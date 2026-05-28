type UnknownObject = Record< string, unknown >;

/**
 * core/image block custom meta that is `show_in_rest`
 */
export interface AttributesMeta {
	_media_credit: string;
	_media_credit_url: string;
	_navis_media_credit_org: string;
}

/**
 * core/image block attributes
 */
export interface Attributes {
	id: number;
	url: string;
	alt: string;
	caption: string;
	meta: AttributesMeta;
}

/**
 * Block setAttributes method
 */
export type SetAttributes< T = UnknownObject > = ( attributes: T ) => void;

/**
 * Base block props
 */
export interface BaseProps< T = UnknownObject, O = Attributes > {
	clientId: string;
	name: string;
	isSelected: boolean;
	attributes: T;
	className?: string;
	setAttributes: SetAttributes< Partial< O > >;
}

/**
 * Typical props used across components
 */
export type AttributeProps = {
	attributes: Attributes;
	setAttributes: SetAttributes< Partial< Attributes > >;
};
