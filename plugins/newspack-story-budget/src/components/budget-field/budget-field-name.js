/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalInputControl as InputControl,
	__experimentalSpacer as Spacer,
	Button,
} from '@wordpress/components';
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import BudgetField from './budget-field';

const BudgetNameField = ( { budget, onUpdateBudget = () => {} } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ name, setName ] = useState( budget.name );

	const onSave = () => {
		if ( name !== budget.name ) {
			onUpdateBudget( budget.id, { ...budget, name } );
		}
		setIsOpen( false );
	};

	const onCancel = () => {
		setName( budget.name );
		setIsOpen( false );
	};

	const toggleButton = (
		<Button
			className={ classnames( 'newspack-story-budget__field__button', 'newspack-story-budget__field__popover-button' ) }
			variant="tertiary"
			onClick={ () => setIsOpen( true ) }
		>
			<h2>{ budget.name }</h2>
		</Button>
	);

	const popoverContent = onClose => (
		<>
			<InspectorPopoverHeader title={ __( 'Edit Budget', 'newspack-story-budget' ) } onClose={ onClose } />
			<VStack spacing={ 2 }>
				<div className="newspack-story-budget__field__content">
					<InputControl
						value={ name }
						label={ __( 'Name', 'newspack-story-budget' ) }
						onChange={ newName => {
							setName( newName );
						} }
					/>
				</div>
				<Spacer margin={ 2 } />
				<HStack expanded spacing={ 2 } justify="end" direction="row-reverse">
					<Button variant="primary" disabled={ name === budget.name || '' === name } type="submit" onClick={ onSave }>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
					<Button variant="secondary" onClick={ onCancel }>
						{ __( 'Cancel', 'newspack-story-budget' ) }
					</Button>
				</HStack>
			</VStack>
		</>
	);

	return (
		<BudgetField
			isOpen={ isOpen }
			toggleButton={ toggleButton }
			popoverContent={ popoverContent }
			onClose={ () => setIsOpen( false ) }
			className="newspack-story-budget__name-field"
		/>
	);
};

export default BudgetNameField;
