/**
 * Settings Wizard: Connections > Mailchimp
 */

/**
 * WordPress dependencies.
 */
import { ENTER } from '@wordpress/keycodes';
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { useEffect, useState, useRef, Fragment } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import WizardsActionCard from '../../../../wizards-action-card';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { WIZARD_ERROR_MESSAGES, WizardError } from '../../../../errors';
import { Button, Card, Grid, Modal, TextControl } from '../../../../../../packages/components/src';

function Mailchimp() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const { wizardApiFetch, isFetching, errorMessage, setError, resetError } = useWizardApiFetch( '/newspack-settings/connections/apis/mailchimp' );
	const [ authState, setAuthState ] = useState< OAuthData >();
	const [ apiKey, setAPIKey ] = useState< string | undefined >();

	const modalTextRef = useRef< HTMLDivElement | null >( null );
	const isConnected = Boolean( authState && authState.username );

	useEffect( () => {
		wizardApiFetch< OAuthData >(
			{
				path: '/newspack/v1/oauth/mailchimp',
			},
			{
				onSuccess: res => setAuthState( res ),
			}
		);
	}, [] );

	useEffect( () => {
		if ( isModalOpen && modalTextRef.current ) {
			const [ inputElement ] = modalTextRef.current.getElementsByTagName( 'input' );
			if ( inputElement ) {
				inputElement.focus();
			}
		}
	}, [ isModalOpen ] );

	function openModal() {
		return setIsModalOpen( true );
	}

	function closeModal() {
		setIsModalOpen( false );
		setAPIKey( undefined );
	}

	function submitAPIKey() {
		wizardApiFetch< OAuthData >(
			{
				path: '/newspack/v1/oauth/mailchimp',
				method: 'POST',
				data: {
					api_key: apiKey,
				},
				updateCacheMethods: [ 'GET' ],
			},
			{
				onSuccess( response ) {
					setAuthState( response );
					resetError();
				},
				onFinally() {
					closeModal();
				},
			}
		);
	}

	function disconnect() {
		wizardApiFetch< OAuthData >(
			{
				path: '/newspack/v1/oauth/mailchimp',
				method: 'DELETE',
				updateCacheMethods: [ 'GET' ],
			},
			{
				onSuccess( data ) {
					setAuthState( data );
					setError( new WizardError( WIZARD_ERROR_MESSAGES.MAILCHIMP_API_KEY_INVALID, 'MAILCHIMP_API_KEY_INVALID' ) );
				},
			}
		);
	}

	function getDescription() {
		if ( isFetching ) {
			return __( 'Loading…', 'newspack-plugin' );
		}
		if ( isConnected ) {
			// Translators: user connection status message.
			return sprintf(
				/* translators: %s: username */
				__( 'Connected as %s', 'newspack-plugin' ),
				authState?.username ?? {}
			);
		}
		return __( 'Not connected', 'newspack-plugin' );
	}

	function getModalButtonText() {
		if ( ! apiKey ) {
			return __( 'Invalid Mailchimp API Key.', 'newspack' );
		}
		if ( isFetching ) {
			return __( 'Connecting…', 'newspack-plugin' );
		}
		if ( isConnected ) {
			return __( 'Connected', 'newspack-plugin' );
		}
		return __( 'Connect', 'newspack-plugin' );
	}

	return (
		<Fragment>
			<WizardsActionCard
				title="Mailchimp"
				description={ getDescription() }
				isChecked={ isConnected }
				actionText={
					<Button variant="link" isDestructive={ isConnected } onClick={ isConnected ? disconnect : openModal } disabled={ isFetching }>
						{ isConnected ? __( 'Disconnect', 'newspack-plugin' ) : __( 'Connect', 'newspack-plugin' ) }
					</Button>
				}
				error={ errorMessage }
				isMedium
			/>
			{ isModalOpen && (
				<Modal title={ __( 'Add Mailchimp API Key', 'newspack-plugin' ) } onRequestClose={ closeModal }>
					<div ref={ modalTextRef }>
						<Grid columns={ 1 } gutter={ 8 }>
							<TextControl
								placeholder="123457103961b1f4dc0b2b2fd59c137b-us1"
								label={ __( 'Mailchimp API Key', 'newspack-plugin' ) }
								hideLabelFromVision={ true }
								value={ apiKey ?? '' }
								onChange={ ( value: string ) => setAPIKey( value ) }
								onKeyDown={ ( event: KeyboardEvent ) => {
									if ( ENTER === event.keyCode && '' !== apiKey ) {
										event.preventDefault();
										submitAPIKey();
									}
								} }
							/>
							<p>
								<ExternalLink href="https://mailchimp.com/help/about-api-keys/#Find_or_generate_your_API_key">
									{ __( 'Find or generate your API key', 'newspack-plugin' ) }
								</ExternalLink>
							</p>
						</Grid>
					</div>
					<Card buttonsCard noBorder className="justify-end">
						<Button variant="secondary" onClick={ closeModal }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" disabled={ ! apiKey } onClick={ submitAPIKey }>
							{ getModalButtonText() }
						</Button>
					</Card>
				</Modal>
			) }
		</Fragment>
	);
}

export default Mailchimp;
