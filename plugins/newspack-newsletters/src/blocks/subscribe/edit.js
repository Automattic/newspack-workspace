/* globals newspack_newsletters_blocks */
/* eslint-disable jsx-a11y/label-has-for, jsx-a11y/label-has-associated-control */
/**
 * External dependencies.
 */
import classnames from 'classnames';
import { CardSortableList } from 'newspack-components';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Fragment, createInterpolateElement, useState, useEffect, useMemo } from '@wordpress/element';
import { TextControl, ToggleControl, CheckboxControl, PanelBody, Notice, Spinner, Button, SelectControl } from '@wordpress/components';
import { useBlockProps, InspectorControls, RichText, PanelColorSettings, getColorObjectByColorValue } from '@wordpress/block-editor';
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { hasGatedLists, isListGated, moveItem, toggleList } from './utils';
import './editor.scss';

const getListCheckboxId = listId => {
	return 'newspack-newsletters-list-checkbox-' + listId;
};

const settingsUrl = newspack_newsletters_blocks.settings_url;

// Access gates per list, keyed by list ID. Empty unless something restricts lists.
const listGates = newspack_newsletters_blocks.list_gates || {};

/**
 * "Gated by <gate>, <gate>", each gate linked when the user can edit it.
 *
 * @param {Object}   props       Component props.
 * @param {Object[]} props.gates Gates restricting a list.
 */
const GatedBy = ( { gates } ) =>
	createInterpolateElement(
		// translators: <gates /> is replaced with the names of the access gates restricting a list. Keep it as is.
		__( 'Gated by <gates />', 'newspack-newsletters' ),
		{
			gates: (
				<>
					{ gates.map( ( gate, index ) => (
						<Fragment key={ gate.id }>
							{ index > 0 && ', ' }
							{ gate.edit_url ? <a href={ gate.edit_url }>{ gate.title }</a> : gate.title }
						</Fragment>
					) ) }
				</>
			),
		}
	);

/**
 * A list's description, with its line breaks kept.
 *
 * @param {Object} props             Component props.
 * @param {string} props.description The description.
 */
const ListDescription = ( { description } ) => (
	<span className="list-description">
		{ ( description || '' ).split( '\n' ).map( ( line, index, arr ) => (
			<Fragment key={ index }>
				{ line }
				{ index < arr.length - 1 && <br /> }
			</Fragment>
		) ) }
	</span>
);

const editedStateOptions = [
	{ label: __( 'Initial', 'newspack-newsletters' ), value: 'initial' },
	{ label: __( 'Success', 'newspack-newsletters' ), value: 'success' },
];

function getColorName( colorvalue ) {
	let colorName = '';
	if ( colorvalue ) {
		const settings = select( 'core/editor' ).getEditorSettings();
		const colorObject = getColorObjectByColorValue( settings.colors, colorvalue );
		if ( colorObject ) {
			colorName = colorObject.slug;
		}
	}
	return colorName;
}

export default function SubscribeEdit( {
	setAttributes,
	attributes: {
		displayInputLabels,
		placeholder,
		emailLabel,
		displayNameField,
		displayLastNameField,
		namePlaceholder,
		nameLabel,
		lastNamePlaceholder,
		lastNameLabel,
		label,
		successMessage,
		afterSuccessBehavior,
		afterSuccessButtonLabel,
		afterSuccessURL,
		lists,
		listsCheckboxes,
		displayDescription,
		showPremium,
		showPremiumLabel,
		mailchimpDoubleOptIn,
		textColor,
		backgroundColor,
	},
} ) {
	const blockProps = useBlockProps();
	const [ editedState, setEditedState ] = useState( editedStateOptions[ 0 ].value );
	const [ inFlight, setInFlight ] = useState( false );
	const [ listConfig, setListConfig ] = useState( {} );
	const [ hasFetchedLists, setHasFetchedLists ] = useState( false );
	const [ hasMissingLists, setHasMissingLists ] = useState( false );

	const fetchLists = () => {
		setInFlight( true );
		apiFetch( {
			path: '/newspack-newsletters/v1/lists_config',
		} )
			.then( setListConfig )
			.finally( () => {
				setHasFetchedLists( true );
				setInFlight( false );
			} );
	};
	useEffect( fetchLists, [] );

	// Whether any of the selected lists are missing from the list config.
	const isConfigMissingList = () => {
		return lists.some( listId => ! listConfig.hasOwnProperty( listId ) );
	};

	useEffect( () => {
		if ( ! hasFetchedLists ) {
			return;
		}
		if ( lists.length ) {
			if ( ! hasMissingLists && isConfigMissingList() ) {
				setHasMissingLists( true );
				// Remove the missing lists from the selected lists.
				setAttributes( { lists: lists.filter( listId => listConfig.hasOwnProperty( listId ) ) } );
			}
		} else {
			setAttributes( { lists: [ Object.keys( listConfig )[ 0 ] ] } );
		}
	}, [ hasFetchedLists ] );

	useEffect( () => {
		if ( lists.length && hasMissingLists && ! isConfigMissingList() ) {
			setHasMissingLists( false );
		}
	}, [ lists ] );

	const onChangeBackgroundColor = newBackgroundColor => {
		setAttributes( { backgroundColorName: getColorName( newBackgroundColor ) } );
		setAttributes( { backgroundColor: newBackgroundColor } );
	};

	const onChangeTextColor = newTextColor => {
		setAttributes( { textColorName: getColorName( newTextColor ) } );
		setAttributes( { textColor: newTextColor } );
	};

	const isListSelected = listId => {
		return ! listsCheckboxes.hasOwnProperty( listId ) || listsCheckboxes[ listId ];
	};
	const toggleListCheckbox = listId => () => {
		const newListsCheckboxes = { ...listsCheckboxes };
		newListsCheckboxes[ listId ] = ! isListSelected( listId );
		setAttributes( { listsCheckboxes: newListsCheckboxes } );
	};

	// Enabled lists that still exist, in block order. The sortable list reports a
	// reorder as indices into the items it was given, so those items and the array
	// passed to moveItem() both come from this, never from `lists` directly: `lists`
	// can hold the ID of a list that has since been removed.
	const enabledLists = useMemo( () => lists.filter( listId => listConfig.hasOwnProperty( listId ) ), [ lists, listConfig ] );
	const sortableItems = useMemo(
		() =>
			enabledLists.map( listId => ( {
				id: listId,
				title: listConfig[ listId ].title,
				description: isListGated( listGates, listId ) ? <GatedBy gates={ listGates[ listId ] } /> : undefined,
				toggleChecked: true,
				onToggleChange: () => setAttributes( { lists: toggleList( lists, listId ) } ),
			} ) ),
		[ enabledLists, lists, listConfig, setAttributes ]
	);
	const isGatedPreview = listId => showPremium && isListGated( listGates, listId );
	// The front end shows the list when there is more than one row, or any gated row.
	const showListUi = lists.length > 1 || lists.some( isGatedPreview );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Form settings', 'newspack-newsletters' ) }>
					<ToggleControl
						label={ __( 'Display input labels', 'newspack-newsletters' ) }
						checked={ displayInputLabels }
						onChange={ value => setAttributes( { displayInputLabels: value } ) }
					/>
					<TextControl
						label={ __( 'Email placeholder', 'newspack-newsletters' ) }
						value={ placeholder }
						onChange={ value => setAttributes( { placeholder: value } ) }
					/>
					<ToggleControl
						label={ __( 'Display name field', 'newspack-newsletters' ) }
						checked={ displayNameField }
						onChange={ value => setAttributes( { displayNameField: value } ) }
					/>
					{ displayNameField && (
						<>
							<TextControl
								label={ __( 'Name placeholder', 'newspack-newsletters' ) }
								value={ namePlaceholder }
								onChange={ value => setAttributes( { namePlaceholder: value } ) }
							/>
							<ToggleControl
								label={ __( 'Display "Last Name" field', 'newspack-newsletters' ) }
								checked={ displayLastNameField }
								onChange={ value => setAttributes( { displayLastNameField: value } ) }
							/>
							{ displayLastNameField && (
								<TextControl
									label={ __( '"Last Name" placeholder', 'newspack-newsletters' ) }
									value={ lastNamePlaceholder }
									onChange={ value => setAttributes( { lastNamePlaceholder: value } ) }
								/>
							) }
						</>
					) }
					{ showListUi && (
						<ToggleControl
							label={ __( 'Display list description', 'newspack-newsletters' ) }
							checked={ displayDescription }
							onChange={ () => setAttributes( { displayDescription: ! displayDescription } ) }
						/>
					) }
					{ hasGatedLists( listGates ) && (
						<>
							<ToggleControl
								label={ __( 'Always show premium lists', 'newspack-newsletters' ) }
								help={ __( 'Readers without access see premium lists, but can’t sign up for them.', 'newspack-newsletters' ) }
								checked={ showPremium }
								onChange={ value => setAttributes( { showPremium: value } ) }
							/>
							{ showPremium && (
								<TextControl
									label={ __( 'Premium list label', 'newspack-newsletters' ) }
									value={ showPremiumLabel }
									onChange={ value => setAttributes( { showPremiumLabel: value } ) }
								/>
							) }
						</>
					) }
				</PanelBody>
				<PanelBody title={ __( 'After subscribe', 'newspack-newsletters' ) } initialOpen={ false }>
					<SelectControl
						label={ __( 'Post-signup button', 'newspack-newsletters' ) }
						help={ __( 'After a successful signup, present a button that sends the reader to another page.', 'newspack-newsletters' ) }
						value={ afterSuccessBehavior }
						options={ [
							{ label: __( 'Show the success message', 'newspack-newsletters' ), value: '' },
							{ label: __( 'Go to a custom URL', 'newspack-newsletters' ), value: 'custom' },
							{ label: __( 'Go to the previous page', 'newspack-newsletters' ), value: 'referrer' },
						] }
						onChange={ value => setAttributes( { afterSuccessBehavior: value } ) }
					/>
					{ '' !== afterSuccessBehavior && (
						<TextControl
							label={ __( 'Button label', 'newspack-newsletters' ) }
							value={ afterSuccessButtonLabel || '' }
							onChange={ value => setAttributes( { afterSuccessButtonLabel: value } ) }
						/>
					) }
					{ 'custom' === afterSuccessBehavior && (
						<TextControl
							label={ __( 'Custom URL', 'newspack-newsletters' ) }
							placeholder={ __( 'https://example.com', 'newspack-newsletters' ) }
							value={ afterSuccessURL || '' }
							onChange={ value => setAttributes( { afterSuccessURL: value } ) }
						/>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Styles', 'newspack-newsletters' ) } className="styles-container">
					<p>
						{ __(
							"Make sure to pick a color that will contrast against the rest of your site's color scheme, to help this block stand out!",
							'newspack-newsletters'
						) }
					</p>
					<PanelColorSettings
						initialOpen={ true }
						colorSettings={ [
							{
								value: textColor,
								onChange: onChangeTextColor,
								label: __( 'Text', 'newspack-newsletters' ),
							},
							{
								value: backgroundColor,
								onChange: onChangeBackgroundColor,
								label: __( 'Background', 'newspack-newsletters' ),
							},
						] }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Subscription Lists', 'newspack-newsletters' ) }>
					{ inFlight && <Spinner /> }
					{ ! inFlight && ! Object.keys( listConfig ).length && (
						<div style={ { marginBottom: '1.5rem' } }>
							<Notice isDismissible={ false } status="error">
								{ __( 'You must enable lists for subscription.', 'newspack-newsletters' ) }
							</Notice>
						</div>
					) }
					{ lists.length < 1 && (
						<div style={ { marginBottom: '1.5rem' } }>
							<Notice isDismissible={ false } status="error">
								{ __( 'You must select at least one list.', 'newspack-newsletters' ) }
							</Notice>
						</div>
					) }
					{ sortableItems.length > 0 && (
						<CardSortableList
							items={ sortableItems }
							onDragCallback={ ( from, to ) => setAttributes( { lists: moveItem( enabledLists, from, to ) } ) }
						/>
					) }
					{ Object.keys( listConfig )
						.filter( listId => ! lists.includes( listId ) )
						.map( listId => (
							<ToggleControl
								key={ listId }
								label={ listConfig[ listId ].title }
								help={ isListGated( listGates, listId ) ? <GatedBy gates={ listGates[ listId ] } /> : undefined }
								checked={ false }
								onChange={ () => setAttributes( { lists: toggleList( lists, listId ) } ) }
							/>
						) ) }
					<p>
						<a href={ settingsUrl }>{ __( 'Manage your subscription lists', 'newspack-newsletters' ) }</a>
					</p>
				</PanelBody>
				{ newspack_newsletters_blocks.provider === 'mailchimp' && (
					<PanelBody title={ __( 'Mailchimp Settings', 'newspack-newsletters' ) }>
						<CheckboxControl
							label={ __( 'Enable double opt-in', 'newspack-newsletters' ) }
							help={ __(
								'Whether the new contact will have its status as "pending" until email confirmation',
								'newspack-newsletters'
							) }
							checked={ mailchimpDoubleOptIn }
							onChange={ value => setAttributes( { mailchimpDoubleOptIn: value } ) }
						/>
					</PanelBody>
				) }
				{ newspack_newsletters_blocks.supports_recaptcha && (
					<PanelBody title={ __( 'Spam protection', 'newspack-newsletters' ) }>
						<p>
							{ sprintf(
								// translators: %s is either 'enabled' or 'disabled'.
								__( 'reCAPTCHA is currently %s.', 'newspack-newsletters' ),
								newspack_newsletters_blocks.has_recaptcha
									? __( 'enabled', 'newspack-newsletters' )
									: __( 'disabled', 'newspack-newsletters' )
							) }
						</p>
						{ ! newspack_newsletters_blocks.has_recaptcha && (
							<p>
								{ __(
									"It's highly recommended that you enable reCAPTCHA protection to prevent spambots from using this form!",
									'newspack-newsletters'
								) }
							</p>
						) }
						<p>
							<a href={ newspack_newsletters_blocks.recaptcha_url }>
								{ __( 'Configure your reCAPTCHA settings.', 'newspack-newsletters' ) }
							</a>
						</p>
					</PanelBody>
				) }
			</InspectorControls>
			<div { ...blockProps }>
				<div className="newspack-newsletters-subscribe__state-bar">
					<span>{ __( 'Edited State', 'newspack-newsletters' ) }</span>
					<div>
						{ editedStateOptions.map( option => (
							<Button
								key={ option.value }
								data-is-active={ editedState === option.value }
								onClick={ () => setEditedState( option.value ) }
							>
								{ option.label }
							</Button>
						) ) }
					</div>
				</div>

				{ ! hasFetchedLists ? (
					<Spinner />
				) : (
					<div
						className={ classnames( {
							'newspack-newsletters-subscribe': true,
							'multiple-lists': lists.length > 1,
						} ) }
						data-status="200"
					>
						{ editedState === 'initial' && (
							<form onSubmit={ ev => ev.preventDefault() }>
								{ hasMissingLists && (
									<Notice isDismissible={ false } status="error">
										{ __(
											'A previously selected list is no longer available. Please, revise the subscription lists selection.',
											'newspack-newsletters'
										) }
									</Notice>
								) }
								{ showListUi && (
									<div className="newspack-newsletters-lists">
										<ul>
											{ lists.map( listId =>
												isGatedPreview( listId ) ? (
													<li key={ listId } className="is-gated">
														<span className="list-checkbox" aria-hidden="true" />
														<span className="list-details">
															<span className="list-label">
																<span className="list-title">
																	{ listConfig[ listId ]?.title }
																	{ showPremiumLabel?.trim() && (
																		<span className="newspack-ui__badge newspack-ui__badge--secondary">
																			{ showPremiumLabel.trim() }
																		</span>
																	) }
																</span>
																{ displayDescription && (
																	<ListDescription description={ listConfig[ listId ]?.description } />
																) }
															</span>
														</span>
													</li>
												) : (
													<li key={ listId }>
														<span className="list-checkbox">
															<input
																id={ getListCheckboxId( listId ) }
																type="checkbox"
																checked={ isListSelected( listId ) }
																onChange={ toggleListCheckbox( listId ) }
															/>
														</span>
														<span className="list-details">
															<label htmlFor={ getListCheckboxId( listId ) }>
																<span className="list-title">{ listConfig[ listId ]?.title }</span>
																{ displayDescription && (
																	<ListDescription description={ listConfig[ listId ]?.description } />
																) }
															</label>
														</span>
													</li>
												)
											) }
										</ul>
									</div>
								) }
								{ displayNameField && (
									<div className="newspack-newsletters-name-input">
										<div className="newspack-newsletters-name-input-item">
											<label>
												{ displayInputLabels && (
													<RichText
														onChange={ value => setAttributes( { nameLabel: value } ) }
														placeholder={ __( 'Name', 'newspack-newsletters' ) }
														value={ nameLabel }
														tagName="span"
													/>
												) }
											</label>
											<input type="text" placeholder={ namePlaceholder } />
										</div>
										{ displayLastNameField && (
											<div className="newspack-newsletters-name-input-item">
												<label>
													{ displayInputLabels && (
														<RichText
															onChange={ value => setAttributes( { lastNameLabel: value } ) }
															placeholder={ __( 'Last Name', 'newspack-newsletters' ) }
															value={ lastNameLabel }
															tagName="span"
														/>
													) }
												</label>
												<input type="text" placeholder={ lastNamePlaceholder } />
											</div>
										) }
									</div>
								) }
								<div className="newspack-newsletters-email-input">
									<label>
										{ displayInputLabels && (
											<RichText
												onChange={ value => setAttributes( { emailLabel: value } ) }
												placeholder={ __( 'Email Address', 'newspack-newsletters' ) }
												value={ emailLabel }
												tagName="span"
											/>
										) }
									</label>
									<input type="email" placeholder={ placeholder } />
									<div className="submit-button" style={ { backgroundColor, color: textColor } }>
										<RichText
											onChange={ value => setAttributes( { label: value } ) }
											placeholder={ __( 'Sign up', 'newspack-newsletters' ) }
											value={ label }
											tagName="span"
										/>
									</div>
								</div>
							</form>
						) }
						{ editedState === 'success' && (
							<div className="newspack-newsletters-subscribe__response">
								<div className="newspack-newsletters-subscribe__icon" />
								<div className="newspack-newsletters-subscribe__message">
									<RichText
										onChange={ value => setAttributes( { successMessage: value } ) }
										placeholder={ __( 'Success message', 'newspack-newsletters' ) }
										value={ successMessage }
										tagName="p"
										className="message status-200"
										allowedFormats={ [ 'core/bold', 'core/italic' ] }
									/>
								</div>
								{ afterSuccessBehavior &&
									// Mirror the front-end guard: a 'custom' redirect with no URL
									// renders no button on the published page, so don't preview one.
									! ( 'custom' === afterSuccessBehavior && ! afterSuccessURL ) && (
										<div
											className="newspack-newsletters-subscribe__continue submit-button"
											style={ { backgroundColor, color: textColor } }
										>
											{ afterSuccessButtonLabel || __( 'Continue', 'newspack-newsletters' ) }
										</div>
									) }
							</div>
						) }
					</div>
				) }
			</div>
		</>
	);
}
