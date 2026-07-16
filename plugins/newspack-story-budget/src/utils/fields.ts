/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { select } from '@wordpress/data';
import { NAMESPACE as storeNamespace } from '../store/constants';
import type { Field } from '../store/types';

export interface FieldElement {
	value: unknown;
	label: unknown;
}

export const getFieldElements = ( field: Field ): FieldElement[] | undefined => {
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

export const getFilterByOperators = ( field: Field ): string[] => {
	if ( field.is_multiple ) {
		return [ 'isAny', 'isNone', 'isAll', 'isNotAll' ];
	}
	if ( field.type === 'boolean' ) {
		return [ 'is' ];
	}
	return [ 'isAny', 'isNone' ];
};

export const getDisplayValue = ( field: Field, value: unknown ): unknown => {
	if (
		value === null ||
		value === undefined ||
		value === '' ||
		( Array.isArray( value ) && ! value.length ) ||
		( [ 'date', 'datetime', 'text', 'longtext' ].includes( field.type ) && ! value )
	) {
		return null;
	}
	if ( field.options?.length ) {
		const options = field.options;
		if ( Array.isArray( value ) ) {
			value = value.map( v => options.find( o => o.value === v )?.label || v );
		}
		value = options.find( o => o.value === value )?.label || value;
	}
	if ( field.type === 'date' ) {
		return new Date( ( value as number ) * 1000 ).toLocaleDateString( undefined, {
			dateStyle: 'medium',
		} );
	}
	if ( field.type === 'datetime' ) {
		return new Date( ( value as number ) * 1000 ).toLocaleString( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		} );
	}
	if ( field.type === 'boolean' ) {
		return value ? 'Yes' : 'No';
	}
	if ( Array.isArray( value ) ) {
		return value.join( ', ' );
	}
	return value;
};

export const getUniqueValues = ( field: Field ): unknown[] => {
	const stories = select( storeNamespace ).getAllStories();
	return stories
		.map( ( story: Record< string, unknown > ) => story[ field.slug ] )
		.flat()
		.filter( ( value: unknown, index: number, self: unknown[] ) => value && self.indexOf( value ) === index );
};
