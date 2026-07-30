/**
 * WordPress dependencies.
 */
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

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

/**
 * Rolling windows offered as one-click presets. Each is the trailing window
 * ending today, i.e. `start: -N days`, `end: today` — the same shape the custom
 * rows produce, so a preset is a shortcut rather than a separate stored format.
 */
const PRESET_DAYS = [ 7, 30, 365 ];

const PRESETS = [
	{ label: __( 'Any', 'newspack-plugin' ), value: '' },
	/* translators: %d: number of days in a trailing date window. */
	...PRESET_DAYS.map( days => ( { label: sprintf( __( 'Last %d days', 'newspack-plugin' ), days ), value: String( days ) } ) ),
	{ label: __( 'Custom', 'newspack-plugin' ), value: 'custom' },
];

/**
 * Which preset a stored range corresponds to.
 *
 * @param {*} start The start bound.
 * @param {*} end   The end bound.
 * @return {string} A PRESETS value: '' when unbounded, the day count when the
 *                  range is exactly one of the presets, otherwise 'custom'.
 */
const presetOf = ( start, end ) => {
	if ( ! start && ! end ) {
		return '';
	}
	if (
		start &&
		end &&
		'relative' === start.type &&
		'relative' === end.type &&
		0 === end.days &&
		start.days < 0 &&
		PRESET_DAYS.includes( Math.abs( start.days ) )
	) {
		return String( Math.abs( start.days ) );
	}
	return 'custom';
};

const DateRangeSetting = ( { start, end, onChange, ...props } ) => {
	const derivedPreset = presetOf( start, end );
	// A range that matches a preset is indistinguishable from the same range built
	// by hand, and an empty one is indistinguishable from "Custom, nothing filled
	// in yet" — so a deliberate Custom choice has to be remembered rather than
	// re-derived. Starts null: nothing chosen yet, so the stored value decides.
	const [ chosenPreset, setChosenPreset ] = useState( null );

	// Custom explains any range, so it is never overridden by what the value looks
	// like. Every other selection stays in step with a range that arrived from
	// storage or changed elsewhere.
	useEffect( () => {
		setChosenPreset( chosen => ( 'custom' === chosen ? chosen : derivedPreset ) );
	}, [ derivedPreset ] );

	const preset = null === chosenPreset ? derivedPreset : chosenPreset;

	// Both keys are always emitted: the consumer merges the value into the stored
	// criterion, so omitting one would leave a stale bound behind. An empty range
	// emits nothing at all, which drops the criterion — an empty `{}` would reach
	// the matcher as "no bounds to check" and match every reader with a date.
	const changeRange = ( nextStart, nextEnd ) => onChange( nextStart || nextEnd ? { start: nextStart, end: nextEnd } : undefined );

	const changePreset = next => {
		setChosenPreset( next );
		if ( 'custom' === next ) {
			// Keep the current range so the rows open on what the preset meant.
			return;
		}
		if ( '' === next ) {
			onChange( undefined );
			return;
		}
		changeRange( { type: 'relative', days: -Number( next ) }, { type: 'relative', days: 0 } );
	};

	return (
		<div { ...props }>
			<div className="newspack-settings__date-range-preset">
				<SelectControl
					// The criterion's own name is the visible heading of the section
					// this renders into, so a visible label here would just repeat it.
					label={ __( 'Date range', 'newspack-plugin' ) }
					hideLabelFromVision
					__next40pxDefaultSize
					data-testid="date-range-preset"
					value={ preset }
					options={ PRESETS }
					onChange={ changePreset }
				/>
			</div>
			{ 'custom' === preset && (
				<Fragment>
					<DateRangeBound
						label={ __( 'From', 'newspack-plugin' ) }
						testId="date-range-start-value"
						bound={ start }
						onChange={ nextStart => changeRange( nextStart, end ) }
					/>
					<DateRangeBound
						label={ __( 'To', 'newspack-plugin' ) }
						testId="date-range-end-value"
						bound={ end }
						onChange={ nextEnd => changeRange( start, nextEnd ) }
					/>
				</Fragment>
			) }
		</div>
	);
};

export default DateRangeSetting;
