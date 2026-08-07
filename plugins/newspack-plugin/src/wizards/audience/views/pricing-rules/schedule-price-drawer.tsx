/**
 * Add and edit one price in a schedule. The fields stack at full width so the
 * engine's long calculation labels read in full, which they cannot do inside a
 * table cell in the form's column.
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
	calcTypes: { value: string; label: string }[];
	currency: PricingRulesCurrency;
	onSave: ( price: SchedulePriceInput ) => void;
	onClose: () => void;
}

interface FieldErrors {
	at?: string;
	value?: string;
}

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
	const atRef = useRef< HTMLInputElement >( null );
	const valueRef = useRef< HTMLInputElement >( null );
	const wasOpen = useRef( false );

	// The panel outlives a close so it can play its exit, so the draft is seeded
	// on the way in rather than at mount, and only on that transition: a parent
	// re-render that hands down a fresh price object must not wipe the edits.
	useEffect( () => {
		if ( isOpen && ! wasOpen.current ) {
			setDraft( price );
			setErrors( {} );
		}
		wasOpen.current = isOpen;
	}, [ isOpen, price ] );

	// Focus follows the rejection, so the message reaches a screen reader through
	// the field's own aria-describedby rather than needing a live region.
	useEffect( () => {
		if ( errors.at ) {
			atRef.current?.focus();
		} else if ( errors.value ) {
			valueRef.current?.focus();
		}
	}, [ errors ] );

	const isDirty = draft.at !== price.at || draft.calc_type !== price.calc_type || draft.value !== price.value || draft.label !== price.label;

	const update = ( key: keyof SchedulePriceInput, value: string ) => setDraft( prev => ( { ...prev, [ key ]: value } ) );

	const save = () => {
		const next: FieldErrors = {};
		const at = Number( draft.at );
		if ( ! Number.isInteger( at ) || at < 1 ) {
			next.at = __( 'Enter a cycle number of 1 or higher.', 'newspack-plugin' );
		} else if ( takenCycles.includes( at ) ) {
			/* translators: %d: a billing cycle number. */
			next.at = sprintf( __( 'Cycle %d already has a price.', 'newspack-plugin' ), at );
		}
		// Blank is "not set"; a typed 0 is a deliberate free price (NPPD-1854).
		if ( '' === String( draft.value ).trim() ) {
			next.value = __( 'Enter a value for this price.', 'newspack-plugin' );
		}
		setErrors( next );
		if ( ! next.at && ! next.value ) {
			onSave( draft );
		}
	};

	return (
		<Drawer.Root isOpen={ isOpen } isDirty={ isDirty } onRequestClose={ onClose }>
			<Drawer.Header>
				<Drawer.Title>{ isNew ? __( 'Add Price', 'newspack-plugin' ) : __( 'Edit Price', 'newspack-plugin' ) }</Drawer.Title>
				<Drawer.CloseIcon />
			</Drawer.Header>
			<Drawer.Content>
				<TextControl
					ref={ atRef }
					label={ __( 'From cycle #', 'newspack-plugin' ) }
					help={ errors.at ?? __( 'Cycle 1 is the initial purchase; cycle 2 is the first renewal.', 'newspack-plugin' ) }
					aria-invalid={ !! errors.at }
					type="number"
					min={ 1 }
					value={ draft.at }
					onChange={ v => update( 'at', v ) }
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Calculation', 'newspack-plugin' ) }
					help={ calcTypeHelp( draft.calc_type, calcTypes.find( c => c.value === draft.calc_type )?.label ?? '' ) }
					value={ draft.calc_type }
					options={ calcTypes.map( c => ( { label: c.label, value: c.value } ) ) }
					onChange={ v => update( 'calc_type', v ) }
					__next40pxDefaultSize
				/>
				<TextControl
					ref={ valueRef }
					label={ valueLabel( draft.calc_type, currency.symbol ) }
					help={ errors.value ?? valueHelp( draft.calc_type ) }
					aria-invalid={ !! errors.value }
					type="number"
					value={ draft.value }
					onChange={ v => update( 'value', v ) }
					__next40pxDefaultSize
				/>
				{ publicize && (
					<TextControl
						label={ __( 'Name shown to reader', 'newspack-plugin' ) }
						help={ __( 'Optional. Shown on the product page, cart, and checkout.', 'newspack-plugin' ) }
						value={ draft.label }
						onChange={ v => update( 'label', v ) }
						__next40pxDefaultSize
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
