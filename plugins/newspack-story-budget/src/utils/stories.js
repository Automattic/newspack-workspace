import { dateI18n, getDate } from '@wordpress/date';

const DATE_TYPES = [ 'date', 'datetime' ];
const DATE_OPERATORS = [ 'on', 'notOn', 'before', 'after', 'beforeInc', 'afterInc', 'between', 'inThePast', 'over' ];
const RELATIVE_OPERATORS = [ 'inThePast', 'over' ];

const toFilterDay = value => ( /^\d{4}-\d{2}-\d{2}$/.test( value ) ? value : dateI18n( 'Y-m-d', getDate( value ) ) );

const toSiteDay = timestamp => {
	const seconds = Number( timestamp );
	return timestamp && Number.isFinite( seconds ) ? dateI18n( 'Y-m-d', seconds * 1000 ) : null;
};

const subtractFromNow = ( amount, unit ) => {
	const date = new Date();
	if ( 'days' === unit || 'weeks' === unit ) {
		date.setDate( date.getDate() - amount * ( 'weeks' === unit ? 7 : 1 ) );
		return date;
	}
	const day = date.getDate();
	date.setDate( 1 );
	if ( 'months' === unit ) {
		date.setMonth( date.getMonth() - amount );
	} else if ( 'years' === unit ) {
		date.setFullYear( date.getFullYear() - amount );
	}
	const lastDay = new Date( date.getFullYear(), date.getMonth() + 1, 0 ).getDate();
	date.setDate( Math.min( day, lastDay ) );
	return date;
};

const matchesDate = ( timestamp, operator, rawValue ) => {
	const day = toSiteDay( timestamp );
	let value = rawValue;
	if ( 'between' === operator ) {
		value = rawValue.map( toFilterDay );
	} else if ( ! RELATIVE_OPERATORS.includes( operator ) ) {
		value = toFilterDay( rawValue );
	}
	switch ( operator ) {
		case 'on':
			return day === value;
		case 'notOn':
			return day !== value;
		case 'before':
			return null !== day && day < value;
		case 'after':
			return null !== day && day > value;
		case 'beforeInc':
			return null !== day && day <= value;
		case 'afterInc':
			return null !== day && day >= value;
		case 'between':
			return null !== day && day >= value[ 0 ] && day <= value[ 1 ];
		case 'inThePast': {
			if ( null === day ) {
				return false;
			}
			const ms = Number( timestamp ) * 1000;
			return ms >= subtractFromNow( value.value, value.unit ).getTime() && ms <= Date.now();
		}
		case 'over':
			return null !== day && Number( timestamp ) * 1000 < subtractFromNow( value.value, value.unit ).getTime();
		default:
			return true;
	}
};

const hasDateValue = ( operator, value ) => {
	if ( 'between' === operator ) {
		return Array.isArray( value ) && 2 === value.length && value.every( v => v );
	}
	if ( 'inThePast' === operator || 'over' === operator ) {
		return value?.value !== undefined && value?.value !== null && value?.value !== '' && !! value?.unit;
	}
	return !! value;
};

export const filter = ( stories, fields, view ) => {
	for ( const { operator, value, field } of view.filters ) {
		const fieldObject = fields.find( f => f.slug === field );

		if ( DATE_TYPES.includes( fieldObject?.type ) && DATE_OPERATORS.includes( operator ) ) {
			if ( hasDateValue( operator, value ) ) {
				stories = stories.filter( story => matchesDate( story?.[ field ], operator, value ) );
			}
			continue;
		}

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
