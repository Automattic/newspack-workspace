/**
 * Contextual Prompts opt-in card.
 *
 * The AI Copy Assistant is hidden until an administrator opts the site into AI
 * use and accepts the disclosure. Some newsrooms are contractually barred from
 * using AI, so this gate is deliberate and admin-only.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Notice, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { ActionCard, Button, Modal, Waiting } from '../../../../../../packages/components/src';

const DISCLOSURE = __(
	'Enabling the AI Copy Assistant lets editors generate donation call-to-action copy for their stories using AI. When used, the content of the post is sent to a third-party AI provider to draft suggestions. It is retained by the provider for up to 30 days for abuse monitoring, is not used to train AI models, and never appears in other AI products. Every suggestion is a draft an editor reviews and approves — nothing is ever published automatically.',
	'newspack-plugin'
);

const CONFIRMATION = __(
	'Some newsrooms have policies or union agreements that restrict the use of AI. By enabling this, you confirm your organization permits it. Only administrators can change this setting, and you can turn it off at any time.',
	'newspack-plugin'
);

const ContextualPromptsOptIn = () => {
	const [ status, setStatus ] = useState( null );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/newspack-popups/v1/contextual-prompt/status' } ).then( setStatus ).catch( setError );
	}, [] );

	const setEnabled = enabled => {
		setInFlight( true );
		setError( null );
		apiFetch( {
			path: '/newspack-popups/v1/contextual-prompt/enable',
			method: 'POST',
			data: { enabled },
		} )
			.then( next => {
				setStatus( next );
				setModalOpen( false );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};

	if ( ! status ) {
		return <Waiting />;
	}

	const { enabled, can_manage: canManage } = status;

	let action = null;
	if ( enabled && canManage ) {
		action = (
			<Button isDestructive isLink onClick={ () => setEnabled( false ) } disabled={ inFlight }>
				{ __( 'Disable', 'newspack-plugin' ) }
			</Button>
		);
	} else if ( ! enabled && canManage ) {
		action = (
			<Button variant="primary" onClick={ () => setModalOpen( true ) } disabled={ inFlight }>
				{ __( 'Enable', 'newspack-plugin' ) }
			</Button>
		);
	}

	let description;
	if ( enabled ) {
		description = __( 'Editors can generate story-specific donation prompts with AI.', 'newspack-plugin' );
	} else if ( canManage ) {
		description = __( 'Let editors generate story-specific donation prompts with AI. Off by default.', 'newspack-plugin' );
	} else {
		description = __( 'An administrator must enable this feature before it can be used.', 'newspack-plugin' );
	}

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }
			<ActionCard title={ __( 'AI Copy Assistant', 'newspack-plugin' ) } description={ description } actionText={ action } isMedium />
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

export default ContextualPromptsOptIn;
