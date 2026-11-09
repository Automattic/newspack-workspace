/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { select } from '@wordpress/data';
import { formatCount, formatSiteDate, formatSiteDateTime } from 'newspack-components';
import { NAMESPACE as storeNamespace } from '../store/constants';

export const getFieldElements = field => {
	if ( ! field.is_filterable || 'no' === field.is_filterable ) {
		return undefined;
	}
	if ( field.options?.length ) {
		return field.options;
	}
	if ( field.type === 'boolean' ) {
		return [
			{ value: true, label: __( 'Yes', 'newspack-story-budget' ) },
			{ value: false, label: __( 'No', 'newspack-story-budget' ) },
		];
	}
	// Fallback to unique values.
	const values = getUniqueValues( field );
	if ( ! values.length ) {
		return undefined;
	}
	return values.map( value => ( {
		value,
		label: value,
	} ) );
};

/**
 * The DataViews field type for a Story Budget field.
 *
 * DataViews offers the "is all" operator only on its `array` type, and
 * right-aligns `number` columns, which suits counts but not a field that
 * shows a label picked from its options.
 *
 * @param {Object} field The Story Budget field.
 * @return {string} The DataViews field type.
 */
export const getDataViewsType = field => {
	if ( field.is_multiple ) {
		return 'array';
	}
	if ( field.options?.length && [ 'number', 'integer' ].includes( field.type ) ) {
		return 'text';
	}
	return field.type;
};

export const getFilterByOperators = field => {
	if ( field.is_multiple ) {
		return [ 'isAny', 'isNone', 'isAll' ];
	}
	if ( field.type === 'boolean' ) {
		return [ 'is' ];
	}
	return [ 'isAny', 'isNone' ];
};

/**
 * Whether a field has no value. Only null, undefined and an empty string count:
 * 0 and false are values, shown as "0" and "No".
 *
 * @param {*} value The field value.
 * @return {boolean} Whether the value is empty.
 */
export const isEmptyValue = value => value === null || value === undefined || value === '';

export const getDisplayValue = ( field, value ) => {
	if (
		isEmptyValue( value ) ||
		( Array.isArray( value ) && ! value.length ) ||
		( [ 'date', 'datetime', 'text', 'longtext' ].includes( field.type ) && ! value )
	) {
		return null;
	}
	if ( field.options?.length ) {
		if ( Array.isArray( value ) ) {
			value = value.map( v => field.options.find( o => o.value === v )?.label || v );
		}
		value = field.options.find( o => o.value === value )?.label || value;
	}
	if ( field.type === 'date' ) {
		return formatSiteDate( value );
	}
	if ( field.type === 'datetime' ) {
		return formatSiteDateTime( value );
	}
	if ( field.type === 'boolean' ) {
		return value ? __( 'Yes', 'newspack-story-budget' ) : __( 'No', 'newspack-story-budget' );
	}
	if ( Array.isArray( value ) ) {
		return value.join( ', ' );
	}
	if ( 'number' === field.type && ! field.options?.length && Number.isFinite( Number( value ) ) ) {
		return formatCount( value );
	}
	return value;
};

export const getUniqueValues = field => {
	const stories = select( storeNamespace ).getAllStories();
	return stories
		.map( story => story[ field.slug ] )
		.flat()
		.filter( ( value, index, self ) => value && self.indexOf( value ) === index );
};
