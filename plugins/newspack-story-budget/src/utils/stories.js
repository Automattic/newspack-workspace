export const filter = ( stories, fields, view ) => {
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
						return value.some( v => fieldValue.includes( v ) );
					}
					return value.includes( fieldValue );
				case 'isNone':
					if ( fieldObject.is_multiple ) {
						return ! value.some( v => fieldValue.includes( v ) );
					}
					return ! value.includes( fieldValue );
				case 'isAll':
					if ( fieldObject.is_multiple ) {
						return value.every( v => fieldValue.includes( v ) );
					}
					return value.includes( fieldValue );
				case 'isNotAll':
					if ( fieldObject.is_multiple ) {
						return ! value.every( v => fieldValue.includes( v ) );
					}
					return ! value.includes( fieldValue );
				default:
					return true;
			}
		} );
	}
	return stories;
};

export const sort = ( stories, fields, view ) => {
	if ( view.sort?.field ) {
		const { field, direction } = view.sort;
		const fieldObject = fields.find( f => f.slug === field );

		if ( fieldObject?.is_sortable ) {
			stories = stories.sort( ( a, b ) => {
				const aValue = a?.[ field ];
				const bValue = b?.[ field ];
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
