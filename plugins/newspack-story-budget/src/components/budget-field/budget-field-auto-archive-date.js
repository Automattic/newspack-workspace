/* eslint @wordpress/no-unsafe-wp-apis: 0 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import {
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	Button,
	DatePicker,
} from '@wordpress/components';
import { __experimentalInspectorPopoverHeader as InspectorPopoverHeader } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import BudgetField from './budget-field';

const BudgetAutoArchiveDateField = ( { budget, onUpdateBudget = () => {} } ) => {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ autoArchiveDate, setAutoArchiveDate ] = useState( budget.archive_at || '' );

	const onSave = () => {
		if ( autoArchiveDate !== budget.archive_at ) {
			onUpdateBudget( budget.id, { ...budget, archive_at: autoArchiveDate } );
		}
		setIsOpen( false );
	};

	const onCancel = () => {
		setAutoArchiveDate( budget.archive_at );
		setIsOpen( false );
	};

	const formatDate = dateString => {
		if ( ! dateString ) {
			return '';
		}

		return new Date( dateString ).toLocaleDateString( 'en-US', {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		} );
	};

	if ( budget.archived ) {
		return null;
	}

	const toggleButton = (
		<Button
			className={ classnames( 'newspack-story-budget__field__button', 'newspack-story-budget__field__popover-button' ) }
			variant="tertiary"
			onClick={ () => setIsOpen( true ) }
		>
			{ budget.archive_at && (
				<Text variant="muted" size="small" className="newspack-story-budget__field__auto-archive--set">
					{ __( 'Auto-archive set for ', 'newspack-story-budget' ) }
					{ formatDate( budget.archive_at ) }
				</Text>
			) }
			{ ! budget.archive_at && (
				<Text variant="muted" size="small" style={ { fontStyle: 'italic' } }>
					{ __( 'Click to set an auto-archive date', 'newspack-story-budget' ) }
				</Text>
			) }
		</Button>
	);

	const popoverContent = onClose => (
		<>
			<InspectorPopoverHeader title={ __( 'Auto-Archive', 'newspack-story-budget' ) } onClose={ onClose } />
			<VStack spacing={ 4 }>
				<DatePicker
					currentDate={ autoArchiveDate }
					isInvalidDate={ date => {
						return date < new Date();
					} }
					onChange={ newDate => {
						setAutoArchiveDate( newDate );
					} }
				/>
				{ autoArchiveDate && (
					<div>
						<Text>{ __( 'Automatically archive on ', 'newspack-story-budget' ) }</Text>
						<Text weight={ 600 }>{ formatDate( autoArchiveDate ) }</Text>
					</div>
				) }
				<HStack expanded spacing={ 2 } justify="end" direction="row-reverse">
					<Button variant="primary" disabled={ autoArchiveDate === budget.archive_at } type="submit" onClick={ onSave }>
						{ __( 'Save', 'newspack-story-budget' ) }
					</Button>
					{ autoArchiveDate && (
						<Button
							onClick={ () => {
								setAutoArchiveDate( '' );
							} }
							variant="secondary"
							isDestructive
						>
							{ __( 'Reset', 'newspack-story-budget' ) }
						</Button>
					) }
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
			className="newspack-story-budget__auto-archive-field"
		/>
	);
};

export default BudgetAutoArchiveDateField;
