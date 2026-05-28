/**
 * Settings Wizard: Connections > Google OAuth
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Button } from '../../../../../../packages/components/src';
import WizardsActionCard from '../../../../wizards-action-card';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { WizardError, WIZARD_ERROR_MESSAGES } from '../../../../errors';

function getURLParams() {
	const searchParams = new URLSearchParams( window.location.search );
	const params: { [ key: string ]: string } = {};
	for ( const [ key, value ] of searchParams.entries() ) {
		params[ key ] = value;
	}
	return params;
}

function GoogleOAuth( {
	onSuccess,
	isOnboarding,
}: {
	onInit?: ( str: Error | null ) => void;
	onSuccess?: ( arg: OAuthData ) => void;
	isOnboarding?: ( str: string ) => void;
} ) {
	const [ authState, setAuthState ] = useState< OAuthData >( {} );

	const userBasicInfo = authState?.user_basic_info;
	const isConnected = Boolean( userBasicInfo && userBasicInfo.email );

	const { setError, errorMessage, wizardApiFetch, isFetching: inFlight } = useWizardApiFetch( '/newspack-settings/connections/apis/google-oauth' );

	// We only want to autofetch the current auth state if we're not onboarding.
	useEffect( () => {
		if ( ! isOnboarding ) {
			getCurrentAuth();
		}
	}, [] );

	useEffect( () => {
		if ( isConnected && userBasicInfo && ! userBasicInfo.has_refresh_token ) {
			setError( new WizardError( WIZARD_ERROR_MESSAGES.GOOGLEOAUTH_REFRESH_TOKEN_EXPIRED, 'googleoauth_refresh_token_expired' ) );
		}
	}, [ isConnected ] );

	function getCurrentAuth() {
		const params = getURLParams();
		if ( ! params.access_token ) {
			wizardApiFetch< OAuthData >(
				{
					path: '/newspack/v1/oauth/google',
					isCached: false,
				},
				{
					onSuccess( data ) {
						setAuthState( data );
						if ( data?.user_basic_info ) {
							if ( typeof onSuccess === 'function' ) {
								onSuccess( data );
							}
							setError( null );
						}
					},
				}
			);
		}
	}

	function openAuth() {
		let authWindow: Window | null = null;
		wizardApiFetch< string >(
			{
				path: '/newspack/v1/oauth/google/start',
				isCached: false,
			},
			{
				onSuccess( url ) {
					if ( url === null ) {
						setError( new WizardError( WIZARD_ERROR_MESSAGES.GOOGLE.URL_INVALID, 'googleoauth_popup_blocked' ) );
						return;
					}
					authWindow = window.open( url, 'newspack_google_oauth', 'width=500,height=600' );
					/** authWindow can be 'null' due to browser's popup blocker. */
					if ( authWindow === null ) {
						setError( new WizardError( WIZARD_ERROR_MESSAGES.GOOGLE.OAUTH_POPUP_BLOCKED, 'googleoauth_popup_blocked' ) );
						return;
					}
					const interval = setInterval( () => {
						if ( authWindow?.closed ) {
							clearInterval( interval );
							getCurrentAuth();
						}
					}, 500 );
				},
				onError() {
					if ( authWindow ) {
						authWindow.close();
					}
				},
			}
		);
	}

	// Redirect user to Google auth screen.
	function disconnect() {
		wizardApiFetch< void >(
			{
				path: '/newspack/v1/oauth/google/revoke',
				method: 'DELETE',
			},
			{
				onSuccess: () => setAuthState( {} ),
			}
		);
	}

	function getDescription() {
		if ( inFlight ) {
			return __( 'Loading…', 'newspack-plugin' );
		}
		if ( isConnected ) {
			return sprintf(
				// translators: %s: connected user's email address.
				__( 'Connected as %s', 'newspack-plugin' ),
				userBasicInfo?.email
			);
		}
		return __( 'Not connected', 'newspack-plugin' );
	}

	return (
		<WizardsActionCard
			title={ __( 'Google', 'newspack-plugin' ) }
			description={ getDescription() }
			isChecked={ isConnected }
			actionText={
				<Button variant="link" isDestructive={ isConnected } onClick={ isConnected ? disconnect : openAuth } disabled={ inFlight }>
					{ isConnected ? __( 'Disconnect', 'newspack-plugin' ) : __( 'Connect', 'newspack-plugin' ) }
				</Button>
			}
			error={ errorMessage }
			isMedium
		/>
	);
}

export default GoogleOAuth;
