/* eslint @wordpress/no-unsafe-wp-apis: 0 */
/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __experimentalVStack as VStack, __experimentalHStack as HStack, Button, TextControl, Notice } from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';

/**
 * External dependencies.
 */
import type { FormEvent } from 'react';

/**
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace, NOTICE_CONTEXT } from '../store/constants';
import type { StoryBudgetSelectors } from './types';

export interface CreateBudgetModalProps {
	onClose: () => void;
}

const CreateBudgetModal = ( { onClose }: CreateBudgetModalProps ) => {
	const [ budgetName, setBudgetName ] = useState( '' );

	const { budgetError, isCreatingBudget } = useSelect( select => {
		const selectors = select( storeNamespace ) as StoryBudgetSelectors;
		return {
			budgetError: selectors.getErrors()?.budgetError || null,
			isCreatingBudget: selectors.isCreatingBudget(),
		};
	} );

	const { createBudget, clearErrors, fetchFields } = useDispatch( storeNamespace );
	const { createNotice, removeNotice } = useDispatch( noticesStore );

	const handleSubmit = async ( e: FormEvent ) => {
		e.preventDefault();
		clearErrors();

		if ( ! budgetName.trim() ) {
			return;
		}

		const result = await createBudget( { name: budgetName.trim() } );
		if ( result?.id ) {
			fetchFields();
			createNotice( 'success', `"${ budgetName }" budget saved. `, {
				id: result.id,
				type: 'snackbar',
				context: NOTICE_CONTEXT,
				onDismiss: () => {
					removeNotice( result.id, NOTICE_CONTEXT );
				},
			} );
			onClose();
		}
	};

	return (
		<form onSubmit={ handleSubmit }>
			<VStack spacing={ 4 }>
				<div>
					<TextControl
						label={ __( 'Budget Name', 'newspack-story-budget' ) }
						value={ budgetName }
						onChange={ setBudgetName }
						disabled={ isCreatingBudget }
						required
					/>
				</div>

				{ budgetError && (
					<Notice status="error" onRemove={ clearErrors }>
						{ budgetError.message }
					</Notice>
				) }

				<HStack justify="end">
					<Button variant="secondary" onClick={ onClose } disabled={ isCreatingBudget }>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
					<Button variant="primary" type="submit" disabled={ ! budgetName.trim() || isCreatingBudget } isBusy={ isCreatingBudget }>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</form>
	);
};

export default CreateBudgetModal;
