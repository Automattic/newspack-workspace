/**
 * Add and edit one price in a schedule. The fields stack at full width so the
 * engine's long calculation labels read in full.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import { TextControl, SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Drawer } from '../../../../../packages/components/src';
import { calcTypeHelp, valueLabel, valueHelp } from './calc-copy';

interface SchedulePriceDrawerProps {
	isOpen: boolean;
	price: SchedulePriceInput;
	isNew: boolean;
	takenCycles: number[];
	publicize: boolean;
	calcTypes: PricingRulesCalcType[];
	currency: PricingRulesCurrency;
	onSave: ( price: SchedulePriceInput ) => void;
	onClose: () => void;
}

interface FieldErrors {
	at?: string;
	value?: string;
}

/** Recolours the control's own help text, which is where a rejection is shown. */
const ERROR_CLASS = 'newspack-pricing-rules__field--error';

export default function SchedulePriceDrawer( {
	isOpen,
	price,
	isNew,
	takenCycles,
	publicize,
	calcTypes,
	currency,
	onSave,
	onClose,
}: SchedulePriceDrawerProps ) {
	const [ draft, setDraft ] = useState< SchedulePriceInput >( price );
	const [ errors, setErrors ] = useState< FieldErrors >( {} );
	// Counts rejections rather than tracking them, so the focus effect below fires
	// once per Save and never off a keystroke that merely cleared one of them.
	const [ rejections, setRejections ] = useState( 0 );
	const atRef = useRef< HTMLInputElement >( null );
	const valueRef = useRef< HTMLInputElement >( null );

	// Seeded on the open transition, not at mount (the panel outlives a close to
	// play its exit) and never on a parent re-render, which must not wipe edits.
	// During render: an effect would paint the entrance on the last price first.
	const [ wasOpen, setWasOpen ] = useState( isOpen );
	if ( wasOpen !== isOpen ) {
		setWasOpen( isOpen );
		if ( isOpen ) {
			setDraft( price );
		}
		// Either way: a rejection the publisher walked away from should not play the
		// exit animation in red.
		setErrors( {} );
	}

	// Focus follows the rejection, so the message reaches a screen reader through
	// the field's own aria-describedby rather than needing a live region. `errors`
	// only says which field; `rejections` is what says a Save just rejected one.
	useEffect( () => {
		if ( ! rejections ) {
			return;
		}
		if ( errors.at ) {
			atRef.current?.focus();
		} else if ( errors.value ) {
			valueRef.current?.focus();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ rejections ] );

	const isDirty = draft.at !== price.at || draft.calc_type !== price.calc_type || draft.value !== price.value || draft.label !== price.label;

	// A corrected field drops its rejection as it is typed in, so the help text and
	// `aria-invalid` never outlast the value that earned them.
	const update = ( key: keyof SchedulePriceInput, value: string ) => {
		setDraft( prev => ( { ...prev, [ key ]: value } ) );
		if ( 'at' === key || 'value' === key ) {
			setErrors( prev => ( prev[ key ] ? { ...prev, [ key ]: undefined } : prev ) );
		}
	};

	const save = () => {
		const next: FieldErrors = {};
		const at = Number( draft.at );
		// A schedule written by the previous editor can hold two prices at one cycle:
		// nothing deduplicated them. Repricing one of those should not first require
		// renumbering it, so the collision only counts against a cycle actually moved.
		const keptItsCycle = ! isNew && at === Number( price.at );
		if ( ! Number.isFinite( at ) || at < 1 ) {
			next.at = __( 'Enter a cycle number of 1 or higher.', 'newspack-plugin' );
		} else if ( ! Number.isSafeInteger( at ) ) {
			// Cycles are counted, so 1.5 is not a smaller version of the same mistake.
			// Safe rather than merely whole: 1e21 is an integer PHP cannot be handed.
			next.at = __( 'Enter a whole cycle number.', 'newspack-plugin' );
		} else if ( ! keptItsCycle && takenCycles.includes( at ) ) {
			/* translators: %d: a billing cycle number. */
			next.at = sprintf( __( 'Cycle %d already has a price.', 'newspack-plugin' ), at );
		}
		// Blank is "not set"; a typed 0 is a deliberate free price (NPPD-1854).
		const value = Number( draft.value );
		if ( '' === String( draft.value ).trim() ) {
			next.value = __( 'Enter a value for this price.', 'newspack-plugin' );
		} else if ( ! Number.isFinite( value ) ) {
			// `1e999` clears the number input but serialises as null, so the engine
			// would store a price nobody typed.
			next.value = __( 'Enter a number.', 'newspack-plugin' );
		} else if ( value < 0 ) {
			next.value = __( 'Enter a value of 0 or higher.', 'newspack-plugin' );
		}
		setErrors( next );
		if ( next.at || next.value ) {
			setRejections( n => n + 1 );
			return;
		}
		onSave( draft );
	};

	// A saved price can name a calculation the vocabulary no longer offers. Listing it
	// keeps the control showing what the price actually is instead of silently
	// displaying the first option while the stored value rides along underneath.
	const options = calcTypes.map( c => ( { label: c.label, value: c.value } ) );
	if ( draft.calc_type && ! calcTypes.some( c => c.value === draft.calc_type ) ) {
		options.push( { label: draft.calc_type, value: draft.calc_type } );
	}

	return (
		<Drawer.Root isOpen={ isOpen } isDirty={ isDirty } onRequestClose={ onClose }>
			<Drawer.Header>
				<Drawer.Title>{ isNew ? __( 'Add Price', 'newspack-plugin' ) : __( 'Edit Price', 'newspack-plugin' ) }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<TextControl
					ref={ atRef }
					className={ errors.at ? ERROR_CLASS : undefined }
					label={ __( 'From cycle #', 'newspack-plugin' ) }
					help={ errors.at ?? __( 'Cycle 1 is the initial purchase; cycle 2 is the first renewal.', 'newspack-plugin' ) }
					aria-invalid={ !! errors.at }
					type="number"
					min={ 1 }
					step={ 1 }
					value={ draft.at }
					onChange={ v => update( 'at', v ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Calculation', 'newspack-plugin' ) }
					help={ calcTypeHelp( draft.calc_type, calcTypes.find( c => c.value === draft.calc_type )?.label ?? '' ) }
					value={ draft.calc_type }
					options={ options }
					onChange={ v => update( 'calc_type', v ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<TextControl
					ref={ valueRef }
					className={ errors.value ? ERROR_CLASS : undefined }
					label={ valueLabel( draft.calc_type, currency.symbol ) }
					help={ errors.value ?? valueHelp( draft.calc_type ) }
					aria-invalid={ !! errors.value }
					type="number"
					min={ 0 }
					value={ draft.value }
					onChange={ v => update( 'value', v ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ publicize && (
					<TextControl
						label={ __( 'Name shown to reader', 'newspack-plugin' ) }
						help={ __( 'Optional. Shown on the product page, cart, and checkout.', 'newspack-plugin' ) }
						value={ draft.label }
						onChange={ v => update( 'label', v ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) }
			</Drawer.Content>
			<Drawer.Footer>
				<Drawer.Action variant="secondary" closes>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Drawer.Action>
				<Drawer.Action variant="primary" onClick={ save }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Drawer.Action>
			</Drawer.Footer>
		</Drawer.Root>
	);
}
