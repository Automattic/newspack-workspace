/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __experimentalVStack as VStack, __experimentalHStack as HStack, Button, TextControl, SelectControl } from '@wordpress/components';
import StoryFieldControl from './story-field-control';

/**
 * External dependencies.
 */
import type { FormEvent } from 'react';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace } from '../store/constants';
import { useField, useFields } from '../hooks';
import type { Field, FieldValue, StoryBudgetSelectors } from './types';

interface NewStoryArgs {
	name: string;
	budgets: ( string | number )[];
	newBudgetName?: string;
	[ key: string ]: unknown;
}

export interface CreateNewStoryProps {
	onClose: () => void;
}

const CreateStoryModal = ( { onClose }: CreateNewStoryProps ) => {
	const [ storyName, setStoryName ] = useState( '' );
	const [ selectedBudget, setSelectedBudget ] = useState( '' );
	const [ newBudgetName, setNewBudgetName ] = useState( '' );
	const [ customFieldValues, setCustomFieldValues ] = useState< Record< string, FieldValue > >( {} );

	const { storyError, budgetError, isCreatingStory, isCreatingBudget } = useSelect( select => {
		const selectors = select( storeNamespace ) as StoryBudgetSelectors;
		return {
			storyError: selectors.getErrors()?.storyError || null,
			budgetError: selectors.getErrors()?.budgetError || null,
			isCreatingStory: selectors.isCreatingStory(),
			isCreatingBudget: selectors.isCreatingBudget(),
		};
	} );

	const budgets = useField( 'budgets' ) as Field | null;
	const fields = useFields() as Field[];

	const { createStory, fetchFields, clearErrors } = useDispatch( storeNamespace );

	const isSubmitting = isCreatingStory || isCreatingBudget;
	const error = storyError || budgetError;

	const budgetOptions = useMemo(
		() => [
			{
				value: '',
				label: __( 'Select a budget', 'newspack-story-budget' ),
			},
			{
				value: 'new',
				label: __( 'Add new budget', 'newspack-story-budget' ),
			},
			...( ( budgets?.options || [] ) as { value: string; label: string }[] ),
		],
		[ budgets ]
	);

	const handleFieldChange = ( fieldSlug: string, newValue: FieldValue ) => {
		setCustomFieldValues( prev => ( {
			...prev,
			[ fieldSlug ]: newValue,
		} ) );
	};

	const handleSubmit = async ( e: FormEvent ) => {
		e.preventDefault();

		clearErrors();

		const createStoryArgs: NewStoryArgs = {
			name: storyName.trim(),
			budgets: [],
			...customFieldValues,
		};

		if ( selectedBudget === 'new' ) {
			createStoryArgs.newBudgetName = newBudgetName.trim();
		} else if ( selectedBudget ) {
			createStoryArgs.budgets = [ selectedBudget ];
		}
		const result = await createStory( createStoryArgs );
		if ( result?.id ) {
			if ( selectedBudget === 'new' ) {
				await fetchFields();
			}
			onClose();
		}
	};
	return (
		<form onSubmit={ handleSubmit }>
			<VStack spacing={ 4 }>
				<div>
					<TextControl
						label={ __( 'Story Name', 'newspack-story-budget' ) }
						id="story-name"
						value={ storyName }
						onChange={ setStoryName }
						disabled={ isSubmitting }
						required
					/>
				</div>

				<div>
					<SelectControl
						label={ __( 'Budget', 'newspack-story-budget' ) }
						id="story-budget"
						value={ selectedBudget }
						options={ budgetOptions }
						onChange={ setSelectedBudget }
						disabled={ isSubmitting }
						required
					/>
				</div>

				{ selectedBudget === 'new' && (
					<div>
						<TextControl
							label={ __( 'New Budget Name', 'newspack-story-budget' ) }
							id="new-budget-name"
							value={ newBudgetName }
							onChange={ setNewBudgetName }
							disabled={ isSubmitting }
							required={ selectedBudget === 'new' }
						/>
					</div>
				) }

				{ Object.entries( fields )
					.filter( ( [ , field ] ) => field?.show_in_add_new_story )
					.map( ( [ , field ] ) => (
						<div key={ field.slug }>
							<div className="newspack-story-budget__field-label">{ field.name }</div>
							<StoryFieldControl
								field={ field }
								value={ customFieldValues[ field.slug ] }
								onChange={ newValue => handleFieldChange( field.slug, newValue ) }
							/>
						</div>
					) ) }
				{ error && <div className="newspack-story-budget__error-message">{ error.message }</div> }

				<HStack justify="end">
					<Button variant="tertiary" onClick={ onClose } disabled={ isSubmitting }>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						disabled={ ! storyName.trim() || ! selectedBudget || isSubmitting || ( selectedBudget === 'new' && ! newBudgetName.trim() ) }
						isBusy={ isSubmitting }
					>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
};

export default CreateStoryModal;
