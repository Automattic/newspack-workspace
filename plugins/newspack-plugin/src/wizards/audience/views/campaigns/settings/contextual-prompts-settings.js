/**
 * Contextual Prompts (AI Copy Assistant) settings.
 *
 * Mirrors the Experimental Tools pattern: a single feature card with an
 * admin-only opt-in + AI-use disclosure, and a configure view for the
 * publisher-profile fields. Hidden behind opt-in because some newsrooms are
 * contractually barred from using AI.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Notice, TextControl, TextareaControl, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { chevronLeft } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { CardFeature, Button, Grid, Modal, Waiting } from '../../../../../../packages/components/src';

const DISCLOSURE = __(
	'Enabling the AI Copy Assistant lets editors generate donation call-to-action copy for their stories using AI. When used, the content of the post is sent to a third-party AI provider to draft suggestions. It is retained by the provider for up to 30 days for abuse monitoring, is not used to train AI models, and never appears in other AI products. Every suggestion is a draft an editor reviews and approves — nothing is ever published automatically.',
	'newspack-plugin'
);

const CONFIRMATION = __(
	'Some newsrooms have policies or union agreements that restrict the use of AI. By enabling this, you confirm your organization permits it. Only administrators can change this setting, and you can turn it off at any time.',
	'newspack-plugin'
);

const fieldsToValues = fields => ( fields || [] ).reduce( ( acc, field ) => ( { ...acc, [ field.key ]: field.value ?? '' } ), {} );

const ContextualPromptsSettings = () => {
	const [ status, setStatus ] = useState( null );
	const [ view, setView ] = useState( 'card' );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ values, setValues ] = useState( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/newspack-popups/v1/contextual-prompt/status' } )
			.then( next => {
				setStatus( next );
				setValues( fieldsToValues( next.fields ) );
			} )
			.catch( setError );
	}, [] );

	const request = ( path, data ) => {
		setInFlight( true );
		setError( null );
		return apiFetch( { path, method: 'POST', data } )
			.then( next => {
				setStatus( next );
				setValues( fieldsToValues( next.fields ) );
				return next;
			} )
			.catch( err => {
				setError( err );
				throw err;
			} )
			.finally( () => setInFlight( false ) );
	};

	const setEnabled = enabled =>
		request( '/newspack-popups/v1/contextual-prompt/enable', { enabled } )
			.then( () => setModalOpen( false ) )
			.catch( () => {} );

	const saveProfile = () =>
		request( '/newspack-popups/v1/contextual-prompt/profile', { fields: values } )
			.then( () => setView( 'card' ) )
			.catch( () => {} );

	if ( ! status ) {
		return <Waiting />;
	}

	const { enabled, can_manage: canManage, fields } = status;

	// Configure view: the publisher-profile fields, mirroring the Experimental
	// Tools configure screen.
	if ( 'configure' === view ) {
		return (
			<form
				className="newspack-wizard__sections"
				onSubmit={ e => {
					e.preventDefault();
					saveProfile();
				} }
			>
				<HStack justify="flex-start" spacing={ 2 }>
					<Button icon={ chevronLeft } label={ __( 'Back', 'newspack-plugin' ) } onClick={ () => setView( 'card' ) } isLink />
					<h2 className="newspack-wizard__heading">{ __( 'AI Copy Assistant', 'newspack-plugin' ) }</h2>
				</HStack>
				<p>{ __( 'Details used to tailor AI-generated Contextual Prompt copy to your newsroom.', 'newspack-plugin' ) }</p>

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error.message }
					</Notice>
				) }

				{ ( fields || [] ).map( field => {
					const Control = 'textarea' === field.type ? TextareaControl : TextControl;
					return (
						<Control
							key={ field.key }
							label={ field.label }
							help={ field.help }
							value={ values[ field.key ] ?? '' }
							onChange={ value => setValues( prev => ( { ...prev, [ field.key ]: value } ) ) }
						/>
					);
				} ) }

				<Button variant="primary" type="submit" disabled={ inFlight }>
					{ inFlight ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save', 'newspack-plugin' ) }
				</Button>
			</form>
		);
	}

	// Card view.
	const description = enabled
		? __( 'Editors can generate story-specific donation prompts with AI.', 'newspack-plugin' )
		: __( 'Let editors generate story-specific donation prompts with AI. Off by default.', 'newspack-plugin' );

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }
			<Grid columns={ 1 } gutter={ 0 }>
				<CardFeature
					title={ __( 'AI Copy Assistant', 'newspack-plugin' ) }
					description={ description }
					enabled={ enabled }
					busy={ inFlight }
					requirements={ ! canManage && ! enabled ? __( 'An administrator must enable this feature.', 'newspack-plugin' ) : undefined }
					onEnable={ canManage ? () => setModalOpen( true ) : undefined }
					onConfigure={ canManage && enabled ? () => setView( 'configure' ) : undefined }
					moreControls={
						canManage && enabled ? [ { title: __( 'Disable', 'newspack-plugin' ), onClick: () => setEnabled( false ) } ] : undefined
					}
				/>
			</Grid>
			{ modalOpen && (
				<Modal
					title={ __( 'Enable the AI Copy Assistant?', 'newspack-plugin' ) }
					onRequestClose={ () => ! inFlight && setModalOpen( false ) }
				>
					<p>{ DISCLOSURE }</p>
					<Notice status="warning" isDismissible={ false }>
						{ CONFIRMATION }
					</Notice>
					<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
						<Button variant="secondary" onClick={ () => setModalOpen( false ) } disabled={ inFlight }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" onClick={ () => setEnabled( true ) } disabled={ inFlight }>
							{ __( 'Enable', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</Modal>
			) }
		</>
	);
};

export default ContextualPromptsSettings;
