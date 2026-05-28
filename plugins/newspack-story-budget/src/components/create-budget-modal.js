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
 * Internal dependencies.
 */
import { NAMESPACE as storeNamespace, NOTICE_CONTEXT } from '../store/constants';

const CreateBudgetModal = ( { onClose } ) => {
	const [ budgetName, setBudgetName ] = useState( '' );

	const { budgetError, isCreatingBudget } = useSelect( select => ( {
		budgetError: select( storeNamespace ).getErrors()?.budgetError || null,
		isCreatingBudget: select( storeNamespace ).isCreatingBudget(),
	} ) );

	const { createBudget, clearErrors, fetchFields } = useDispatch( storeNamespace );
	const { createNotice, removeNotice } = useDispatch( noticesStore );

	const handleSubmit = async e => {
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
