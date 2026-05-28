/* global newspack_blocks */

/**
 * External dependencies
 */
import intersection from 'lodash/intersection';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useBlockProps, RichText, InspectorControls, useInnerBlocksProps, InnerBlocks } from '@wordpress/block-editor';
import { Spinner, Notice, TextControl, ToggleControl, PanelBody, Button } from '@wordpress/components';
import { Icon, check } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './editor.scss';

const getListCheckboxId = listId => {
	return 'newspack-reader-registration-list-checkbox-' + listId;
};

const editedStateOptions = [
	{ label: __( 'Initial', 'newspack-plugin' ), value: 'initial' },
	{ label: __( 'Registration Success', 'newspack-plugin' ), value: 'registration' },
];
export default function ReaderRegistrationEdit( {
	isSelected,
	setAttributes,
	attributes: {
		title,
		description,
		placeholder,
		label,
		privacyLabel,
		newsletterSubscription,
		displayListDescription,
		hideSubscriptionInput,
		newsletterLabel,
		lists,
		listsCheckboxes,
		hideOauth,
	},
} ) {
	const blockProps = useBlockProps();
	const [ editedState, setEditedState ] = useState( editedStateOptions[ 0 ].value );
	let { reader_activation_terms: defaultTermsText, reader_activation_url: defaultTermsUrl } = window.newspack_blocks;

	if ( defaultTermsUrl ) {
		defaultTermsText = `<a href="${ defaultTermsUrl }">` + defaultTermsText + '</a>';
	}

	const innerBlocksProps = useInnerBlocksProps(
		{},
		{
			renderAppender: InnerBlocks.ButtonBlockAppender,
			template: [
				// Quirk: this will only get applied to the block (as inner blocks) if it's *rendered* in the editor.
				// If the user never switches the state view, it will not be applied, so PHP code contains a fallback.
				[
					'core/paragraph',
					{
						align: 'center',
						content: __( 'Success! Your account was created and you’re signed in.', 'newspack-plugin' ),
					},
				],
			],
		}
	);
	const [ inFlight, setInFlight ] = useState( false );
	const [ listConfig, setListConfig ] = useState( {} );

	const fetchLists = () => {
		if ( newspack_blocks.has_newsletters && newsletterSubscription ) {
			setInFlight( true );
			apiFetch( {
				path: '/newspack-newsletters/v1/lists_config',
			} )
				.then( setListConfig )
				.finally( () => setInFlight( false ) );
		}
	};

	useEffect( fetchLists, [] );
	useEffect( fetchLists, [ newsletterSubscription ] );

	useEffect( () => {
		const listIds = Object.keys( listConfig );
		if ( listIds.length && ( ! lists.length || ! intersection( lists, listIds ).length ) ) {
			setAttributes( { lists: [ Object.keys( listConfig )[ 0 ] ] } );
		}
	}, [ listConfig ] );

	const shouldHideSubscribeInput = () => {
		return lists.length === 1 && hideSubscriptionInput;
	};

	const isListSelected = listId => {
		return listsCheckboxes.hasOwnProperty( listId ) && listsCheckboxes[ listId ];
	};
	const toggleListCheckbox = listId => () => {
		const newListsCheckboxes = { ...listsCheckboxes };
		newListsCheckboxes[ listId ] = ! isListSelected( listId );
		setAttributes( { listsCheckboxes: newListsCheckboxes } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Form settings', 'newspack-plugin' ) }>
					<TextControl
						label={ __( 'Input placeholder', 'newspack-plugin' ) }
						value={ placeholder }
						disabled={ inFlight }
						onChange={ value => setAttributes( { placeholder: value } ) }
					/>
					{ newspack_blocks.has_google_oauth && (
						<ToggleControl
							label={ __( 'Show Google sign-in button', 'newspack-plugin' ) }
							checked={ ! hideOauth }
							onChange={ () => setAttributes( { hideOauth: ! hideOauth } ) }
						/>
					) }
				</PanelBody>
				{ newspack_blocks.has_newsletters && (
					<PanelBody title={ __( 'Newsletter Subscription', 'newspack-plugin' ) }>
						<ToggleControl
							label={ __( 'Enable newsletter subscription', 'newspack-plugin' ) }
							checked={ !! newsletterSubscription }
							disabled={ inFlight }
							onChange={ () => setAttributes( { newsletterSubscription: ! newsletterSubscription } ) }
						/>
						{ newsletterSubscription && (
							<>
								{ inFlight && <Spinner /> }
								{ ! inFlight && ! Object.keys( listConfig ).length && (
									<div style={ { marginBottom: '1.5rem' } }>
										{ __(
											'To enable newsletter subscription, you must configure subscription lists on Newspack Newsletters.',
											'newspack-plugin'
										) }
									</div>
								) }
								{ Object.keys( listConfig ).length > 0 && (
									<>
										<ToggleControl
											label={ __( 'Display list description', 'newspack-plugin' ) }
											checked={ displayListDescription }
											disabled={ inFlight }
											onChange={ () =>
												setAttributes( {
													displayListDescription: ! displayListDescription,
												} )
											}
										/>
										<ToggleControl
											label={ __( 'Hide newsletter selection and always subscribe', 'newspack-plugin' ) }
											checked={ hideSubscriptionInput }
											disabled={ inFlight || lists.length !== 1 }
											onChange={ () =>
												setAttributes( {
													hideSubscriptionInput: ! hideSubscriptionInput,
												} )
											}
										/>
										{ lists.length < 1 && (
											<div style={ { marginBottom: '1.5rem' } }>
												<Notice isDismissible={ false } status="error">
													{ __( 'You must select at least one list.', 'newspack-plugin' ) }
												</Notice>
											</div>
										) }
										{ Object.keys( listConfig ).length > 0 && <p>{ __( 'Lists', 'newspack-plugin' ) }:</p> }
										{ Object.keys( listConfig ).map( listId => (
											<ToggleControl
												key={ listId }
												label={ listConfig[ listId ].title }
												checked={ lists.includes( listId ) }
												disabled={ inFlight }
												onChange={ () => {
													if ( ! lists.includes( listId ) ) {
														setAttributes( { lists: lists.concat( listId ) } );
													} else {
														setAttributes( {
															lists: lists.filter( id => id !== listId ),
														} );
													}
												} }
											/>
										) ) }
									</>
								) }
								<p>
									<a href={ newspack_blocks.newsletters_url }>{ __( 'Configure your subscription lists', 'newspack-plugin' ) }</a>.
								</p>
							</>
						) }
					</PanelBody>
				) }
				<PanelBody title={ __( 'Spam protection', 'newspack-plugin' ) }>
					<p>
						{ sprintf(
							// translators: %s is either 'enabled' or 'disabled'.
							__( 'reCAPTCHA is currently %s.', 'newspack-plugin' ),
							newspack_blocks.has_recaptcha ? __( 'enabled', 'newspack-plugin' ) : __( 'disabled', 'newspack-plugin' )
						) }
					</p>
					{ ! newspack_blocks.has_recaptcha && (
						<p>
							{ __(
								"It's highly recommended that you enable reCAPTCHA protection to prevent spambots from using this form!",
								'newspack-plugin'
							) }
						</p>
					) }
					<p>
						<a href={ newspack_blocks.recaptcha_url }>{ __( 'Configure your reCAPTCHA settings.', 'newspack-plugin' ) }</a>
					</p>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="newspack-registration__state-bar">
					<span>{ __( 'Edited State', 'newspack-plugin' ) }</span>
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
				{ editedState === 'initial' && (
					<div className="newspack-registration newspack-ui">
						<form onSubmit={ ev => ev.preventDefault() }>
							{ ( isSelected || title ) && (
								<div className="newspack-registration__header">
									<RichText
										onChange={ value => setAttributes( { title: value } ) }
										placeholder={ __( 'Add title', 'newspack-plugin' ) }
										value={ title }
										allowedFormats={ [] }
										tagName="h3"
										className="newspack-registration__title"
									/>
								</div>
							) }
							{ ( isSelected || description ) && (
								<RichText
									onChange={ value => setAttributes( { description: value } ) }
									placeholder={ __( 'Add description', 'newspack-plugin' ) }
									value={ description }
									tagName="p"
									className="newspack-registration__description"
								/>
							) }
							<div className="newspack-registration__form-content">
								{ ! shouldHideSubscribeInput() && newsletterSubscription && lists.length ? (
									<>
										{ lists.map( listId => (
											<label key={ listId } htmlFor={ getListCheckboxId( listId ) } className="newspack-ui__input-card">
												<input
													id={ getListCheckboxId( listId ) }
													type="checkbox"
													checked={ isListSelected( listId ) }
													onChange={ toggleListCheckbox( listId ) }
												/>
												<strong>
													{ lists.length === 1 ? (
														<RichText
															onChange={ value => setAttributes( { newsletterLabel: value } ) }
															placeholder={ __( 'Subscribe to our newsletter', 'newspack-plugin' ) }
															value={ newsletterLabel }
															allowedFormats={ [] }
															tagName="span"
														/>
													) : (
														listConfig[ listId ]?.title
													) }
												</strong>
												{ displayListDescription && (
													<span className="newspack-ui__helper-text">{ listConfig[ listId ]?.description }</span>
												) }
											</label>
										) ) }
									</>
								) : null }
								<div className="newspack-registration__main">
									{ newspack_blocks.has_google_oauth && ! hideOauth && (
										<div className="newspack-ui">
											<button className="newspack-ui__button newspack-ui__button--wide newspack-ui__button--secondary newspack-ui__button--google-oauth">
												<span
													dangerouslySetInnerHTML={ {
														__html: newspack_blocks.google_logo_svg,
													} }
												/>
												{ __( 'Continue with Google', 'newspack-plugin' ) }
											</button>
											<div className="newspack-ui__word-divider">{ __( 'Or', 'newspack-plugin' ) }</div>
										</div>
									) }
									<div>
										<div className="newspack-registration__inputs">
											<input type="email" placeholder={ placeholder } />
											<button type="submit" className="newspack-ui__button newspack-ui__button--primary">
												<RichText
													onChange={ value => setAttributes( { label: value } ) }
													placeholder={ __( 'Continue', 'newspack-plugin' ) }
													value={ label }
													allowedFormats={ [] }
													tagName="span"
												/>
											</button>
										</div>
										<div className="newspack-registration__response" />
									</div>
								</div>
							</div>
							<div className="newspack-registration__help-text">
								<RichText
									onChange={ value => setAttributes( { privacyLabel: value } ) }
									placeholder={ __( 'Terms & Conditions statement…', 'newspack-plugin' ) }
									value={ privacyLabel || defaultTermsText }
									allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
									tagName="p"
								/>
							</div>
						</form>
					</div>
				) }
				{ editedState === 'registration' && (
					<div className="newspack-registration newspack-ui">
						<div className="newspack-ui__box newspack-ui__box--success newspack-ui__box--x-large newspack-ui__box--text-center">
							<span className="newspack-ui__icon newspack-ui__icon--success">
								<Icon icon={ check } />
							</span>
							<div { ...innerBlocksProps } />
						</div>
					</div>
				) }
			</div>
		</>
	);
}
