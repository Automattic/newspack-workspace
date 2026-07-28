/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { SelectControl, TextControl } from '../';

/**
 * A bound is stored as { type: 'absolute', date } or { type: 'relative', days },
 * where days is negative for the past and positive for the future. The UI splits
 * the relative case in two so a publisher never has to type a minus sign.
 */
const BOUND_TYPES = [
	{ label: __( 'Any', 'newspack-plugin' ), value: '' },
	{ label: __( 'Date', 'newspack-plugin' ), value: 'absolute' },
	{ label: __( 'Days ago', 'newspack-plugin' ), value: 'past' },
	{ label: __( 'Days from now', 'newspack-plugin' ), value: 'future' },
];

// Today as YYYY-MM-DD in local time. toISOString() converts to UTC and would give
// the wrong day for anyone west of Greenwich.
const today = () => {
	const date = new Date();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );
	return `${ date.getFullYear() }-${ month }-${ day }`;
};

// Zero days is the same day either way, and normalizes to "Days ago".
const boundTypeOf = bound => {
	if ( ! bound || ! bound.type ) {
		return '';
	}
	if ( 'absolute' === bound.type ) {
		return 'absolute';
	}
	return bound.days > 0 ? 'future' : 'past';
};

const boundValueOf = bound => {
	if ( ! bound ) {
		return '';
	}
	return 'absolute' === bound.type ? bound.date || '' : String( Math.abs( bound.days || 0 ) );
};

const makeBound = ( type, rawValue ) => {
	if ( 'absolute' === type ) {
		return { type: 'absolute', date: rawValue || today() };
	}
	const days = Math.abs( parseInt( rawValue, 10 ) || 0 );
	return { type: 'relative', days: 'future' === type ? days : -days };
};

const DateRangeBound = ( { label, testId, bound, onChange } ) => {
	// The bound type is normally derived from the stored value, but a zero-magnitude
	// relative bound (`days: 0`) is the same day whether the publisher chose "ago" or
	// "from now" — the sign can't tell them apart. `chosenType` records an explicit
	// choice the publisher made in this control, as opposed to one merely derived
	// from the stored value, and starts out `null` — no choice made yet — since the
	// bound this control receives can arrive asynchronously (e.g. an existing segment
	// fetched after first mount) and a value from a later render is not a choice.
	// It's consulted only while the bound stays ambiguous and only once it holds a
	// real choice; once a magnitude is entered, the stored value is unambiguous again
	// and wins.
	const [ chosenType, setChosenType ] = useState( null );
	const derivedType = boundTypeOf( bound );
	const isAmbiguous = 'relative' === bound?.type && 0 === bound.days;
	const type = isAmbiguous && null !== chosenType ? chosenType : derivedType;
	return (
		<div className="newspack-settings__date-range-bound">
			<SelectControl
				label={ label }
				value={ type }
				options={ BOUND_TYPES }
				onChange={ nextType => {
					setChosenType( nextType );
					onChange( nextType ? makeBound( nextType, '' ) : undefined );
				} }
			/>
			{ '' !== type && (
				<TextControl
					data-testid={ testId }
					type={ 'absolute' === type ? 'date' : 'number' }
					value={ boundValueOf( bound ) }
					onChange={ nextValue => onChange( makeBound( type, nextValue ) ) }
				/>
			) }
		</div>
	);
};

const DateRangeSetting = ( { start, end, onChangeStart, onChangeEnd, ...props } ) => (
	<div { ...props }>
		<DateRangeBound label={ __( 'From', 'newspack-plugin' ) } testId="date-range-start-value" bound={ start } onChange={ onChangeStart } />
		<DateRangeBound label={ __( 'To', 'newspack-plugin' ) } testId="date-range-end-value" bound={ end } onChange={ onChangeEnd } />
	</div>
);

export default DateRangeSetting;
