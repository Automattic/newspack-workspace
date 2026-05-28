/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, DateTimePicker, Modal, Notice, Popover, SelectControl, TextareaControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { calendar } from '@wordpress/icons';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies.
 */
import './style.scss';
import moment from 'moment';

/**
 * Correction types.
 *
 * @type {Object[]} The correction types.
 */
const types = [
	{ label: __( 'Correction', 'newspack-plugin' ), value: 'correction' },
	{ label: __( 'Clarification', 'newspack-plugin' ), value: 'clarification' },
];

/**
 * Correction piority.
 *
 * @type {Object[]} The correction piority.
 */
const piority = [
	{ label: __( 'High', 'newspack-plugin' ), value: 'high' },
	{ label: __( 'Low', 'newspack-plugin' ), value: 'low' },
];

/**
 * Save the corrections data.
 *
 * @param {number} postId  The post ID.
 * @param {Object} payload The corrections data.
 *
 * @return {Promise} The response.
 */
const saveData = async ( postId, payload ) => {
	const response = await apiFetch( {
		path: `${ window.NewspackCorrectionsData.restPath }/${ postId }`,
		method: 'POST',
		data: payload,
	} );
	return response;
};

/**
 * The corrections modal component.
 *
 * @return {JSX.Element} The corrections modal component.
 */
const CorrectionsModal = () => {
	/**
	 * Get the current post ID.
	 */
	const postId = useSelect( select => select( 'core/editor' ).getCurrentPostId(), [] );

	/**
	 * Define the state variables.
	 */
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ corrections, setCorrections ] = useState( [] );
	const [ newCorrection, setNewCorrection ] = useState( '' );
	const [ newCorrectionType, setNewCorrectionType ] = useState( 'correction' );
	const [ newCorrectionPriority, setNewCorrectionPriority ] = useState( 'low' );
	const [ isDatePopoverOpen, setIsDatePopoverOpen ] = useState( null );
	const [ isAddingCorrection, setIsAddingCorrection ] = useState( false );

	/**
	 * Prepare actions.
	 */
	const { createNotice } = useDispatch( noticesStore );

	// Fetch corrections when modal opens
	useEffect( () => {
		if ( window.NewspackCorrectionsData.corrections ) {
			setCorrections(
				window.NewspackCorrectionsData.corrections.map( correction => ( {
					...correction,
					type: correction.correction_type || 'correction',
					date: correction.correction_date,
					priority: correction.correction_priority || 'low',
				} ) )
			);
		}
	}, [] );

	// Send Corrections.
	useEffect( () => {
		if ( isSaving ) {
			saveCorrections();
		}
	}, [ isSaving ] );

	// Add a new correction to the list.
	const saveCorrection = () => {
		// Check if the correction is empty.
		if ( ! newCorrection ) {
			return;
		}

		// Add date as per site's timezone.
		const adjustedDate = new Date().toLocaleString( 'en-US', {
			timeZone: window.NewspackCorrectionsData.siteTimezone,
			hour12: false,
		} );

		setCorrections( [
			{
				ID: Date.now(),
				post_content: newCorrection,
				type: newCorrectionType,
				date: adjustedDate,
				priority: newCorrectionPriority,
				isNew: true,
			},
			...corrections,
		] );
		setNewCorrection( '' );
		setNewCorrectionType( 'correction' );
	};

	// Remove unsaved corrections.
	const removeUnsavedCorrections = () => {
		setCorrections( corrections.filter( correction => ! correction.isNew ) );
		setNewCorrection( '' );
		setNewCorrectionType( 'correction' );
	};

	// Update an existing correction.
	const updateCorrection = ( correctionId, postContent, type, date, priority ) => {
		setCorrections(
			corrections.map( correction => {
				if ( correction.ID === correctionId ) {
					return { ...correction, post_content: postContent, type, date, priority };
				}
				return correction;
			} )
		);
	};

	// Delete a correction.
	const deleteCorrection = correctionId => {
		setCorrections( corrections.filter( correction => correction.ID !== correctionId ) );
	};

	// Save all corrections.
	const saveCorrections = async () => {
		setSaveError( null );

		const payload = {
			post_id: postId,
			corrections: corrections.map( ( { ID, post_content, type, date, priority, isNew } ) => ( {
				id: isNew ? null : ID, // Null means create a new correction
				content: post_content,
				type,
				priority,
				date: moment( new Date( date ) ).format( 'YYYY-MM-DD HH:mm:ss' ),
			} ) ),
		};

		try {
			await saveData( postId, payload );
			setIsOpen( false );
		} catch ( error ) {
			setSaveError( error.message );
		} finally {
			setIsSaving( false );
			createNotice( 'success', __( 'Changes have been saved successfully.', 'newspack-plugin' ), {
				type: 'snackbar',
				isDismissible: true,
			} );
		}
	};

	return (
		<>
			<PluginDocumentSettingPanel
				name="newspack-corrections-panel"
				title={ __( 'Corrections & Clarifications', 'newspack-plugin' ) }
				className="newspack-corrections-panel"
			>
				<Button
					variant="secondary"
					onClick={ () => setIsOpen( true ) }
					label={ __( 'Manage corrections and clarifications', 'newspack-plugin' ) }
					__next40pxDefaultSize
				>
					{ __( 'Manage corrections', 'newspack-plugin' ) }
				</Button>
			</PluginDocumentSettingPanel>

			{ isOpen && (
				<Modal
					title={
						isAddingCorrection ? __( 'Add New Correction', 'newspack-plugin' ) : __( 'Corrections & Clarifications', 'newspack-plugin' )
					}
					onRequestClose={ () => setIsOpen( false ) }
					className="newspack-corrections-modal"
					overlayClassName="newspack-corrections-modal-overlay"
					size="medium"
					isDismissible={ false }
					shouldCloseOnClickOutside={ false }
					shouldCloseOnEsc={ false }
				>
					{ saveError && <p className="error-message">{ saveError }</p> }

					{ ! isAddingCorrection && corrections.length === 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'No corrections or clarifications have been added.', 'newspack-plugin' ) }
						</Notice>
					) }

					{ ! isAddingCorrection && corrections.length > 0 && (
						<>
							{ corrections.map( correction => (
								<div key={ correction.ID } className="correction-item">
									<div>
										<SelectControl
											label={ __( 'Type', 'newspack-plugin' ) }
											value={ correction.type }
											options={ types }
											onChange={ value =>
												updateCorrection(
													correction.ID,
													correction.post_content,
													value,
													correction.date,
													correction.priority
												)
											}
											__next40pxDefaultSize
										/>
										<SelectControl
											label={ __( 'Priority', 'newspack-plugin' ) }
											value={ correction.priority }
											options={ piority }
											onChange={ value =>
												updateCorrection( correction.ID, correction.post_content, correction.type, correction.date, value )
											}
											__next40pxDefaultSize
										/>
										<BaseControl id={ `correction-date-${ correction.ID }` } label={ __( 'Date', 'newspack-plugin' ) }>
											<Button
												variant="secondary"
												className="correction-date-button"
												onClick={ () => setIsDatePopoverOpen( correction.ID ) }
												icon={ calendar }
												iconPosition="right"
												__next40pxDefaultSize
											>
												{ new Date( correction.date ).toLocaleString() }
											</Button>
										</BaseControl>
										{ isDatePopoverOpen === correction.ID && (
											<Popover
												className="correction-date-popover"
												position="bottom center"
												onClose={ () => setIsDatePopoverOpen( null ) }
											>
												<DateTimePicker
													label={ __( 'Date', 'newspack-plugin' ) }
													className="correction-date"
													is12Hour={ true }
													currentDate={ new Date( correction.date ) }
													onChange={ value =>
														updateCorrection(
															correction.ID,
															correction.post_content,
															correction.type,
															value,
															correction.priority
														)
													}
												/>
											</Popover>
										) }
									</div>
									<TextareaControl
										label={ __( 'Description', 'newspack-plugin' ) }
										rows={ 3 }
										value={ correction.post_content }
										onChange={ value =>
											updateCorrection( correction.ID, value, correction.type, correction.date, correction.priority )
										}
									/>
									<Button
										text={ __( 'Delete', 'newspack-plugin' ) }
										variant="secondary"
										onClick={ () => {
											deleteCorrection( correction.ID );
											createNotice(
												'success',
												sprintf(
													// translators: %s: type of correction.
													__( '%s deleted successfully.', 'newspack-plugin' ),
													correction.type.replace( /^./, char => char.toUpperCase() )
												),
												{
													type: 'snackbar',
													isDismissible: true,
												}
											);
										} }
										isDestructive
									/>
								</div>
							) ) }
						</>
					) }

					{ ! isSaving && isAddingCorrection && (
						<>
							<SelectControl
								label={ __( 'Type', 'newspack-plugin' ) }
								value={ newCorrectionType }
								options={ types }
								onChange={ value => setNewCorrectionType( value ) }
								__next40pxDefaultSize
							/>
							<SelectControl
								label={ __( 'Priority', 'newspack-plugin' ) }
								value={ newCorrectionPriority }
								options={ piority }
								onChange={ value => setNewCorrectionPriority( value ) }
								__next40pxDefaultSize
							/>
							<TextareaControl
								label={ __( 'Description', 'newspack-plugin' ) }
								rows={ 3 }
								value={ newCorrection }
								onChange={ value => setNewCorrection( value ) }
							/>
						</>
					) }

					<div className="correction-actions">
						{ ! isSaving && isAddingCorrection ? (
							<>
								<Button
									text={ __( 'Add', 'newspack-plugin' ) }
									variant="primary"
									onClick={ () => {
										saveCorrection();
										setIsAddingCorrection( false );
										createNotice(
											'success',
											sprintf(
												// translators: %s: type of correction.
												__( '%s added successfully.', 'newspack-plugin' ),
												newCorrectionType.replace( /^./, char => char.toUpperCase() )
											),
											{
												type: 'snackbar',
												isDismissible: true,
											}
										);
									} }
									isBusy={ isSaving }
									disabled={ ! newCorrection }
								/>
								<Button
									text={ __( 'Go back', 'newspack-plugin' ) }
									variant="tertiary"
									onClick={ () => setIsAddingCorrection( false ) }
								/>
							</>
						) : (
							<>
								<Button
									variant="primary"
									onClick={ () => {
										saveCorrection();
										setIsSaving( true );
										setIsAddingCorrection( false );
									} }
									isBusy={ isSaving }
								>
									{ isSaving ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save & close', 'newspack-plugin' ) }
								</Button>
								<Button
									variant="secondary"
									disabled={ isAddingCorrection }
									onClick={ () => {
										setIsAddingCorrection( ! isAddingCorrection );
									} }
								>
									{ __( 'Add new correction', 'newspack-plugin' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => {
										setIsOpen( false );
										setIsAddingCorrection( false );
										removeUnsavedCorrections();
									} }
								>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
							</>
						) }
					</div>
				</Modal>
			) }
		</>
	);
};

registerPlugin( 'newspack-corrections', {
	render: CorrectionsModal,
	icon: null,
} );
