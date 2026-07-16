import type { Field, StoriesView, Story } from '../store/types';

/**
 * Story field values, and the DataViews filter `value` these are compared
 * against, are dynamically shaped per field (scalar for single-value fields,
 * array for multi-value/"any of" filters) with no static contract -- this
 * whole module duck-types them the same way the original JS did, narrowing
 * `unknown` only where a comparison operator or method call requires it.
 */

export const filter = ( stories: Story[], fields: Field[], view: Pick< StoriesView, 'filters' > ): Story[] => {
	for ( const { operator, value, field } of view.filters ) {
		const fieldObject = fields.find( f => f.slug === field );

		if ( ! fieldObject?.is_filterable || 'no' === fieldObject.is_filterable ) {
			continue;
		}

		if ( value === null || value === undefined || value === '' || ( Array.isArray( value ) && ! value.length ) ) {
			continue;
		}

		stories = stories.filter( story => {
			const fieldValue = story?.[ field ] ?? '';

			switch ( operator ) {
				case 'is':
					return fieldValue === value;
				case 'isNot':
					return fieldValue !== value;
				case 'isAny':
					if ( fieldObject.is_multiple ) {
						return ( value as unknown[] ).some( v => ( fieldValue as unknown[] ).includes( v ) );
					}
					return ( value as unknown[] ).includes( fieldValue );
				case 'isNone':
					if ( fieldObject.is_multiple ) {
						return ! ( value as unknown[] ).some( v => ( fieldValue as unknown[] ).includes( v ) );
					}
					return ! ( value as unknown[] ).includes( fieldValue );
				case 'isAll':
					if ( fieldObject.is_multiple ) {
						return ( value as unknown[] ).every( v => ( fieldValue as unknown[] ).includes( v ) );
					}
					return ( value as unknown[] ).includes( fieldValue );
				case 'isNotAll':
					if ( fieldObject.is_multiple ) {
						return ! ( value as unknown[] ).every( v => ( fieldValue as unknown[] ).includes( v ) );
					}
					return ! ( value as unknown[] ).includes( fieldValue );
				default:
					return true;
			}
		} );
	}
	return stories;
};

export const sort = ( stories: Story[], fields: Field[], view: Pick< StoriesView, 'sort' > ): Story[] => {
	if ( view.sort?.field ) {
		const { field, direction } = view.sort;
		const fieldObject = fields.find( f => f.slug === field );

		if ( fieldObject?.is_sortable ) {
			stories = stories.sort( ( a, b ) => {
				const aValue = a?.[ field ] as string | number | undefined;
				const bValue = b?.[ field ] as string | number | undefined;
				if ( aValue === undefined && bValue === undefined ) {
					return 0;
				}
				if ( aValue === undefined ) {
					return 1;
				}
				if ( bValue === undefined ) {
					return -1;
				}
				if ( aValue < bValue ) {
					return direction === 'asc' ? -1 : 1;
				}
				if ( aValue > bValue ) {
					return direction === 'asc' ? 1 : -1;
				}
				return 0;
			} );
		}
	}
	return stories;
};
