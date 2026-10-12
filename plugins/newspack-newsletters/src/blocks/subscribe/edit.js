/* globals newspack_newsletters_blocks */
/* eslint-disable jsx-a11y/label-has-for, jsx-a11y/label-has-associated-control */
/**
 * External dependencies.
 */
import classnames from 'classnames';
import CardSortableList from 'newspack-components/dist/esm/card-sortable-list';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Fragment, createInterpolateElement, useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import {
	BaseControl,
	TextControl,
	ToggleControl,
	CheckboxControl,
	PanelBody,
	Notice,
	Spinner,
	Button,
	SelectControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useBlockProps, InspectorControls, RichText, PanelColorSettings, getColorObjectByColorValue } from '@wordpress/block-editor';
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { enabledInOrder, hasGatedLists, isListGated, moveItem, syncListOrder } from './utils';
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
		listsPosition,
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

	// Every list, in sidebar order. The block only stores the lists that are on,
	// so where a list that is off sits is kept here, for this editing session.
	const [ storedListOrder, setStoredListOrder ] = useState( [] );
	const allListIds = useMemo( () => Object.keys( listConfig ), [ listConfig ] );
	const listOrder = useMemo( () => syncListOrder( storedListOrder, lists, allListIds ), [ storedListOrder, lists, allListIds ] );

	const moveList = ( from, to ) => {
		const reordered = moveItem( listOrder, from, to );
		setStoredListOrder( reordered );
		// Moving a list that is off changes nothing the block stores. Skip the
		// write, or the post is marked as changed with nothing to save.
		const next = enabledInOrder( reordered, lists );
		if ( next.length !== lists.length || next.some( ( id, index ) => id !== lists[ index ] ) ) {
			setAttributes( { lists: next } );
		}
	};
	// Memoized because it feeds sortableItems below, which must keep its identity.
	const toggleListEnabled = useCallback(
		listId => {
			// Keep the order as it is shown, so the card stays where it is.
			setStoredListOrder( listOrder );
			setAttributes( {
				lists: lists.includes( listId ) ? lists.filter( id => id !== listId ) : enabledInOrder( listOrder, [ ...lists, listId ] ),
			} );
		},
		[ listOrder, lists, setAttributes ]
	);
	// CardSortableList copies `items` into its own state whenever the prop's identity
	// changes. A fresh array on every render would reset it mid-drag or mid-animation,
	// so this only changes when the lists, their order or their settings do.
	const sortableItems = useMemo(
		() =>
			listOrder.map( listId => ( {
				id: listId,
				title: listConfig[ listId ].title,
				description: isListGated( listGates, listId ) ? <GatedBy gates={ listGates[ listId ] } /> : undefined,
				toggleChecked: lists.includes( listId ),
				onToggleChange: () => toggleListEnabled( listId ),
			} ) ),
		[ listOrder, lists, listConfig, toggleListEnabled ]
	);

	const premiumLabel = showPremiumLabel?.trim();
	// The front end lists the block's lists when there is more than one, or when
	// a reader without access would be shown a premium one.
	const showsPremiumRow = listId => showPremium && isListGated( listGates, listId );
	const showListUi = lists.length > 1 || lists.some( showsPremiumRow );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Form settings', 'newspack-newsletters' ) }>
					{ showListUi && (
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'List position', 'newspack-newsletters' ) }
							help={ __( 'Show lists before or after the email input field.', 'newspack-newsletters' ) }
							value={ listsPosition }
							onChange={ value => setAttributes( { listsPosition: value } ) }
						>
							<ToggleGroupControlOption value="before" label={ __( 'Before', 'newspack-newsletters' ) } />
							<ToggleGroupControlOption value="after" label={ __( 'After', 'newspack-newsletters' ) } />
						</ToggleGroupControl>
					) }
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
							<TextControl
								label={ __( 'Premium list label', 'newspack-newsletters' ) }
								value={ showPremiumLabel }
								onChange={ value => setAttributes( { showPremiumLabel: value } ) }
							/>
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
						<BaseControl __nextHasNoMarginBottom>
							<CardSortableList items={ sortableItems } onDragCallback={ moveList } />
						</BaseControl>
					) }
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
							'has-lists-after': 'after' === listsPosition,
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
											{ lists.map( listId => (
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
															<span className="list-title">
																{ listConfig[ listId ]?.title }
																{ premiumLabel && isListGated( listGates, listId ) && (
																	<span className="newspack-ui__badge newspack-ui__badge--primary">
																		{ premiumLabel }
																	</span>
																) }
															</span>
															{ displayDescription && (
																<ListDescription description={ listConfig[ listId ]?.description } />
															) }
														</label>
													</span>
												</li>
											) ) }
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
