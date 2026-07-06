/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { __experimentalVStack as VStack, __experimentalHStack as HStack, Dropdown, Button, Notice, Tooltip } from '@wordpress/components';
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useMemo } from '@wordpress/element';

/**
 * External dependencies.
 */
import type { ComponentProps, ReactNode } from 'react';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import StoryFieldControl from './story-field-control';
import utils from '../utils';
import { useStory, useStoryField } from '../hooks';
import type { Field, FieldValue, Story, StoryBudgetSelectors } from './types';

type DropdownPopoverProps = ComponentProps< typeof Dropdown >[ 'popoverProps' ];

const DEFAULT_POPOVER_PROPS: DropdownPopoverProps = {
	placement: 'right-start',
	shift: true,
};

export interface StoryFieldProps {
	fieldId: string;
	storyId: Story[ 'id' ];
	value?: FieldValue;
	onChange?: ( value: FieldValue ) => void;
	onCloseEdit?: ( value: FieldValue ) => void;
	allowEdit?: boolean;
	saveInPlace?: boolean;
	showPostLinks?: boolean;
	popoverProps?: DropdownPopoverProps;
}

export default ( {
	fieldId,
	storyId,
	value,
	onChange = () => {},
	onCloseEdit = () => {},
	allowEdit = true,
	saveInPlace = false,
	showPostLinks = false,
	popoverProps,
}: StoryFieldProps ): ReactNode => {
	const { canEditStory, isLoadingStory, fieldError } = useSelect(
		select => {
			const selectors = select( storeNamespace ) as StoryBudgetSelectors;
			return {
				isLoadingStory: selectors.isLoadingStory( storyId ),
				canEditStory: selectors.canEditStory( storyId ),
				fieldError: selectors.getFieldError( storyId, fieldId ),
			};
		},
		[ storyId, fieldId ]
	);

	const story = useStory( storyId ) as Story;
	const field = useStoryField( storyId, fieldId ) as Field;

	const { saveStoryField, clearErrors, fetchStory } = useDispatch( storeNamespace );

	value = value !== undefined ? value : ( story[ fieldId ] as FieldValue );

	const [ editedValue, setEditedValue ] = useState( value );
	const [ isOpen, setIsOpen ] = useState( saveInPlace && !! fieldError );

	const displayValue = useMemo( () => {
		// `utils/fields`' `Field` (its own, store-level type) isn't identical to this
		// subtree's local `Field` (e.g. `name` is required there, optional here); cast at
		// this cross-module boundary rather than unify the two types.
		return field
			? ( utils.fields.getDisplayValue( field as Parameters< typeof utils.fields.getDisplayValue >[ 0 ], value ) as string | null )
			: null;
	}, [ field, value ] );

	const collapsedValue = useMemo( () => {
		return displayValue && displayValue.length > 70 ? `${ displayValue.slice( 0, 67 ) }...` : null;
	}, [ displayValue ] );

	// Pre-existing hazard (not introduced here): this reads `field.is_editable` before the
	// `!field` guard below, so a falsy `field` on an early render would throw.
	const canEdit = useMemo( () => allowEdit && canEditStory && field.is_editable, [ allowEdit, canEditStory, field ] );

	if ( ! field ) {
		return null;
	}

	// `applyFilters()` is untyped (returns `unknown`); this filter conventionally returns
	// either `null` (no override) or a ReactNode to render instead.
	const customRender = applyFilters( 'newspack-story-budget.story-field', null, displayValue, field, story, allowEdit ) as ReactNode;
	if ( customRender ) {
		return customRender;
	}

	if ( ! canEdit ) {
		return (
			<div className="newspack-story-budget__field">
				{ collapsedValue ? (
					<Tooltip
						text={ displayValue as string }
						delay={ 300 }
						placement="bottom-start"
						className="newspack-story-budget__field__value-tooltip"
					>
						<span className="newspack-story-budget__field__value">{ collapsedValue }</span>
					</Tooltip>
				) : (
					<span className="newspack-story-budget__field__value">{ displayValue !== null ? displayValue : '--' }</span>
				) }
			</div>
		);
	}

	return (
		// Disable reason: we need to prevent the click event from bubbling up to
		// the table row, which may trigger bulk edit selection and stop the
		// popover from opening.
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
		<div className="newspack-story-budget__field" onClick={ e => e.stopPropagation() }>
			<Dropdown
				open={ isOpen }
				popoverProps={ popoverProps || DEFAULT_POPOVER_PROPS }
				contentClassName="newspack-story-budget__field__popover"
				onToggle={ () => setIsOpen( ! isOpen ) }
				renderToggle={ ( { onToggle } ) => (
					<Button
						className="newspack-story-budget__field__button"
						variant="tertiary"
						onClick={ onToggle }
						disabled={ isLoadingStory }
						aria-expanded={ isOpen }
						title={ field.is_editable ? `Edit ${ field.name }` : undefined }
					>
						{ displayValue === null ? (
							<span className="newspack-story-budget__field__empty-value">{ __( 'Click to set', 'newspack-story-budget' ) }</span>
						) : (
							collapsedValue || displayValue
						) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => (
					<>
						<InspectorPopoverHeader title={ field.name } onClose={ onClose } />
						{ saveInPlace && fieldError && (
							<Notice className="newspack-story-budget__error" isDismissible={ false } status="error">
								{ fieldError }
							</Notice>
						) }
						{ field.description && field.type !== 'boolean' && <p>{ field.description }</p> }
						<form
							onSubmit={ async e => {
								clearErrors( storyId, fieldId );
								setIsOpen( false );
								e.preventDefault();
								if ( saveInPlace ) {
									const response = await saveStoryField( storyId, fieldId, editedValue );

									// Reopen the popover if there is an error.
									if ( response?.payload?.message ) {
										setIsOpen( true );
									}
								}
							} }
						>
							<VStack spacing={ 4 }>
								<div
									style={ {
										maxHeight: '200px',
										overflowY: 'auto',
									} }
								>
									<StoryFieldControl
										field={ field }
										value={ editedValue }
										onChange={ val => {
											setEditedValue( val );
											onChange( val );
										} }
									/>
								</div>
								{ saveInPlace && (
									<HStack expanded spacing={ 2 } justify="space-between">
										<HStack spacing={ 2 } justify="start">
											{ showPostLinks && field.slug === 'name' && (
												<Button
													variant="link"
													onClick={ () => {
														onClose();
														fetchStory( storyId );
														window.location.hash = '#/stories/' + storyId;
													} }
												>
													{ __( 'View', 'newspack-story-budget' ) }
												</Button>
											) }
											{ showPostLinks && field.slug === 'name' && story.metadata?.edit_url && (
												<Button variant="link" href={ story.metadata.edit_url } target="_blank">
													{ __( 'Edit Post', 'newspack-story-budget' ) }
												</Button>
											) }
										</HStack>
										<HStack spacing={ 2 } justify="end" direction="row-reverse">
											<Button
												variant="primary"
												disabled={ value === editedValue || isLoadingStory }
												isBusy={ isLoadingStory }
												type="submit"
											>
												{ __( 'Save', 'newspack-story-budget' ) }
											</Button>
											<Button
												variant="secondary"
												disabled={ isLoadingStory }
												onClick={ () => {
													onClose();
													setEditedValue( value );
												} }
											>
												{ __( 'Cancel', 'newspack-story-budget' ) }
											</Button>
										</HStack>
									</HStack>
								) }
							</VStack>
						</form>
					</>
				) }
				onClose={ () => {
					clearErrors( storyId, fieldId );
					onCloseEdit( editedValue );
				} }
			/>
		</div>
	);
};
