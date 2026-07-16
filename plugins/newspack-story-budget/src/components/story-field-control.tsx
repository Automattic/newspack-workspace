/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalInputControl as InputControl,
	__experimentalVStack as VStack,
	CheckboxControl,
	SelectControl,
	DatePicker,
	DateTimePicker,
	TextareaControl,
} from '@wordpress/components';
import { useId } from '@wordpress/element';

/**
 * External dependencies.
 */
import type { ComponentProps } from 'react';

/**
 * Internal dependencies.
 */
import type { Field, FieldOption, FieldValue } from './types';

const EMPTY_STRING = '';

export interface StoryFieldControlProps {
	field: Field;
	value?: FieldValue;
	onChange?: ( value: FieldValue ) => void;
	disabled?: boolean;
	[ key: string ]: unknown;
}

export default ( { field, value, onChange = () => {}, ...props }: StoryFieldControlProps ) => {
	const componentId = useId();

	const getOptionId = ( option: FieldOption ) => `${ componentId }-${ option.value }`;

	if ( ! field ) {
		return null;
	}

	const controlProps = {
		label: field.title,
		hideLabelFromVision: true,
		onChange: ( val: FieldValue ) => {
			if ( field.type === 'date' || field.type === 'datetime' ) {
				// This callback is only ever wired up to DatePicker/DateTimePicker below,
				// which always call it with a date string.
				val = parseInt( ( new Date( val as string ).getTime() / 1000 ).toString(), 10 );
			}
			if ( field.type === 'number' && val !== '' ) {
				if ( field.is_multiple ) {
					// Only reached via the multi-value checkbox branch below, which always
					// calls this with an array of raw (string) option values.
					val = ( val as ( string | number )[] ).map( v => ( v as unknown as number ) * 1 );
				} else {
					val = ( val as unknown as number ) * 1;
				}
			}
			// If the value is an empty string, set it to null so it skips type check and clears the field.
			if ( val === '' ) {
				val = null;
			}
			onChange( val );
		},
		...props,
	};

	// The budgets field should always render a select control.
	if ( field.slug === 'budgets' ) {
		// `SelectControl`'s props are a discriminated union keyed on the literal `multiple`
		// (`true` vs `false`), which a plain `boolean` can't satisfy; the props bag is cast
		// as a whole rather than picking one variant, since `field.is_multiple` is only known
		// at runtime. Note: when multiple and falsy, `value` falls back to `''` here (not `[]`),
		// which is a pre-existing quirk of the original code, not introduced by this cast.
		const budgetsSelectProps = {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			options: [
				{
					value: '',
					label: __( 'No budget', 'newspack-story-budget' ),
				},
				// Budget options are always sourced server-side with both `value` and
				// `label` set (see `includes/fields/class-fields.php`), unlike the generic
				// `FieldOption` shape (which allows either to be absent for other fields).
				...( ( field.options || [] ) as { value: string; label: string }[] ),
			],
			value: ( value || EMPTY_STRING ) as string,
			multiple: field.is_multiple,
			...controlProps,
		} as ComponentProps< typeof SelectControl >;
		return <SelectControl { ...budgetsSelectProps } />;
	}

	if ( field.options?.length ) {
		const options = field.options.map( option => ( {
			...option,
			label: option.label || option.name,
			disabled: ( option.hasOwnProperty( 'user_can_apply' ) && ! option.user_can_apply ) || option.disabled || props.disabled,
		} ) );

		if ( field.is_multiple ) {
			return (
				<VStack spacing={ 2 }>
					{ options.map( option => (
						<div key={ getOptionId( option ) } className="newspack-story-budget__control__checkbox-option">
							<input
								id={ getOptionId( option ) }
								type="checkbox"
								checked={ Array.isArray( value ) && value.includes( option.value as string | number ) }
								disabled={ option.disabled }
								onChange={ ev => {
									const currentValue = ( value || [] ) as ( string | number )[];
									onChange(
										ev.target.checked
											? [ ...currentValue, option.value as string | number ]
											: currentValue.filter( v => v !== option.value ) || []
									);
								} }
							/>
							<label htmlFor={ getOptionId( option ) }>{ option.label }</label>
						</div>
					) ) }
				</VStack>
			);
		}
		return (
			<VStack spacing={ 2 }>
				{ options.map( option => (
					<div key={ getOptionId( option ) } className="newspack-story-budget__control__radio-option">
						<input
							id={ getOptionId( option ) }
							type="radio"
							checked={ value === option.value }
							value={ option.value as string | number }
							onChange={ ev => onChange( ev.target.value ) }
							disabled={ option.disabled }
						/>
						<label htmlFor={ getOptionId( option ) }>{ option.label }</label>
					</div>
				) ) }
			</VStack>
		);
	}

	if ( field.type === 'date' ) {
		return <DatePicker currentDate={ new Date( ( value as number ) * 1000 ) } { ...controlProps } />;
	}

	if ( field.type === 'datetime' ) {
		return <DateTimePicker currentDate={ new Date( ( value as number ) * 1000 ) } { ...controlProps } />;
	}

	if ( field.type === 'longtext' ) {
		// `__next40pxDefaultSize` isn't part of `TextareaControlProps` (unlike the other
		// controls in this file) -- likely copied from a sibling branch. WordPress form
		// controls ignore unrecognized boolean props at runtime, so this is a harmless no-op,
		// kept as-is rather than removed.
		const textareaProps = {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			value: ( value || EMPTY_STRING ) as string,
			...controlProps,
		} as ComponentProps< typeof TextareaControl >;
		return <TextareaControl { ...textareaProps } />;
	}

	if ( field.type === 'boolean' ) {
		return <CheckboxControl checked={ !! value } label={ field.description || field.name } onChange={ onChange } />;
	}

	if ( field.type === 'number' ) {
		( controlProps as { type?: string } ).type = 'number';
	}

	return <InputControl __next40pxDefaultSize value={ ( value || EMPTY_STRING ) as string } { ...controlProps } />;
};
