/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ClipboardButton, ExternalLink } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { PluginSettings, Notice } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

const Salesforce = () => {
	const { salesforce_redirect_url: redirectUrl } = window?.newspackAudience || {};
	const salesforceData = useWizardData( 'newspack-audience/salesforce' );
	const [ isConnected, setIsConnected ] = useState( salesforceData.refresh_token );
	const [ error, setError ] = useState( null );
	const [ hasCopied, setHasCopied ] = useState( false );

	const { saveWizardSettings, wizardApiFetch } = useDispatch( WIZARD_STORE_NAMESPACE );
	const saveAllSettings = value =>
		saveWizardSettings( {
			slug: 'newspack-audience/salesforce',
			payloadPath: [ 'salesforce_settings' ],
			updatePayload: {
				path: [ 'salesforce_settings' ],
				value,
			},
		} );

	/**
	 * Use auth code to request access and refresh tokens for Salesforce API.
	 * Saves tokens to options table.
	 * https://help.salesforce.com/articleView?id=remoteaccess_oauth_web_server_flow.htm&type=5
	 *
	 * @param {string} authorizationCode Auth code fetched from Salesforce.
	 * @return {void}
	 */
	const getTokens = async authorizationCode => {
		try {
			// Get the tokens.
			const response = await wizardApiFetch( {
				path: '/newspack/salesforce/v1/tokens',
				method: 'POST',
				data: {
					code: authorizationCode,
					redirect_uri: redirectUrl,
				},
			} );

			const { access_token, client_id, client_secret, instance_url, refresh_token } = response;

			if ( access_token && refresh_token ) {
				saveAllSettings( {
					access_token,
					client_id,
					client_secret,
					instance_url,
					refresh_token,
				} );
			}
			setIsConnected( true );
		} catch ( e ) {
			setError(
				__(
					'We couldn’t establish a connection to Salesforce. Please verify your Consumer Key and Secret and try connecting again.',
					'newspack-plugin'
				)
			);
		}
	};

	/**
	 * Check validity of refresh token and show an error message if it's no longer active.
	 * The refresh token is valid until it's manually revoked in the Salesforce dashboard,
	 * or the Connected App is deleted there.
	 */
	const checkConnectionStatus = async () => {
		const response = await wizardApiFetch( {
			path: '/newspack/salesforce/v1/connection-status',
			method: 'POST',
			isQuietFetch: true,
		} );
		if ( response.error ) {
			setError( response.error );
		}
	};

	useEffect( () => {
		const query = parse( window.location.search );
		const authorizationCode = query.code;
		if ( authorizationCode ) {
			// Remove `code` param from URL without adding history.
			window.history.replaceState( {}, '', redirectUrl );
			getTokens( authorizationCode, redirectUrl );
		}
	}, [] );

	useEffect( () => {
		if ( isConnected ) {
			checkConnectionStatus();
		} else {
			setError( null );
		}
	}, [ isConnected ] );

	return (
		<>
			<PluginSettings
				afterUpdate={ settings => {
					let clientId, clientSecret;
					( settings?.salesforce || [] ).forEach( setting => {
						if ( 'newspack_salesforce_client_id' === setting.key ) {
							clientId = setting.value;
						} else if ( 'newspack_salesforce_client_secret' === setting.key ) {
							clientSecret = setting.value;
						}
					} );

					if ( clientId && clientSecret && redirectUrl ) {
						const loginUrl = addQueryArgs( 'https://login.salesforce.com/services/oauth2/authorize', {
							response_type: 'code',
							client_id: encodeURIComponent( clientId ),
							client_secret: encodeURIComponent( clientSecret ),
							redirect_uri: encodeURI( redirectUrl ),
						} );

						window.location.assign( loginUrl );
					} else {
						setIsConnected( false );
					}
				} }
				pluginSlug="newspack/salesforce"
				title={ __( 'Salesforce Settings', 'newspack-plugin' ) }
				description={ () => (
					<>
						{ error && <Notice noticeText={ error } isWarning /> }

						{ isConnected && ! error && (
							<Notice noticeText={ __( 'Your site is connected to Salesforce.', 'newspack-plugin' ) } isSuccess />
						) }

						{ __(
							'Establish a connection to sync WooCommerce order data to Salesforce. To connect with Salesforce, create or choose a Connected App for this site in your Salesforce dashboard. Make sure to paste the full URL for this page (',
							'newspack-plugin'
						) }

						<ClipboardButton
							text={ redirectUrl }
							onCopy={ () => setHasCopied( true ) }
							onFinishCopy={ () => setHasCopied( false ) }
							className="newspack-button is-link"
						>
							{ hasCopied ? __( 'copied to clipboard!', 'newspack-plugin' ) : __( 'copy to clipboard', 'newspack-plugin' ) }{ ' ' }
						</ClipboardButton>

						{ __( ') into the “Callback URL” field in the Connected App’s settings. ', 'newspack-plugin' ) }

						<ExternalLink href="https://help.salesforce.com/articleView?id=connected_app_create.htm">
							{ __( 'Learn how to create a Connected App', 'newspack-plugin' ) }
						</ExternalLink>
					</>
				) }
			/>
		</>
	);
};

export default Salesforce;
