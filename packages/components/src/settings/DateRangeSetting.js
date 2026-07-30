/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
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

// `seed` is set only when the publisher picks a bound type, so a newly chosen
// bound starts on a usable value instead of being half-filled. It must stay off
// for edits to the value itself: a date input reports '' both when cleared and
// transiently while its value is being retyped, and substituting today() there
// would silently move a saved window out from under the publisher.
const makeBound = ( type, rawValue, seed = false ) => {
	if ( 'absolute' === type ) {
		return { type: 'absolute', date: rawValue || ( seed ? today() : '' ) };
	}
	const days = Math.abs( parseInt( rawValue, 10 ) || 0 );
	return { type: 'relative', days: 'future' === type ? days : -days };
};

const DateRangeBound = ( { label, testId, bound, onChange } ) => {
	// The bound type is normally derived from the stored value, but a zero-magnitude
	// relative bound (`days: 0`) is the same day whether the publisher chose "ago" or
	// "from now" — the sign can't tell them apart. `chosenType` records the direction
	// to fall back on while the bound stays ambiguous, and starts out `null` — no
	// direction known yet — since the bound this control receives can arrive
	// asynchronously (e.g. an existing segment fetched after first mount).
	const [ chosenType, setChosenType ] = useState( null );
	const derivedType = boundTypeOf( bound );
	const isAmbiguous = 'relative' === bound?.type && 0 === bound.days;

	// Keep chosenType in step with any unambiguous bound — freshly chosen by the
	// publisher in this control, or loaded from storage — so the direction survives
	// if the value is later cleared down to an ambiguous zero. A bound of '' (no
	// bound at all, e.g. before an async load resolves) doesn't establish a
	// direction; only a real, unambiguous one does.
	useEffect( () => {
		if ( '' !== derivedType && ! isAmbiguous ) {
			setChosenType( derivedType );
		}
	}, [ derivedType, isAmbiguous ] );

	const type = isAmbiguous && null !== chosenType ? chosenType : derivedType;
	return (
		<div className="newspack-settings__date-range-bound">
			<SelectControl
				label={ label }
				// Without this the select renders at 32px while the sibling input is
				// 40px, so the two boxes in a row don't match.
				__next40pxDefaultSize
				value={ type }
				options={ BOUND_TYPES }
				onChange={ nextType => {
					setChosenType( nextType );
					onChange( nextType ? makeBound( nextType, '', true ) : undefined );
				} }
			/>
			{ '' !== type && (
				<TextControl
					data-testid={ testId }
					// The visible label sits on the sibling select, so without one here
					// the input has no accessible name at all.
					label={ 'absolute' === type ? __( 'Date', 'newspack-plugin' ) : __( 'Days', 'newspack-plugin' ) }
					hideLabelFromVision
					type={ 'absolute' === type ? 'date' : 'number' }
					min={ 'absolute' === type ? undefined : 0 }
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
