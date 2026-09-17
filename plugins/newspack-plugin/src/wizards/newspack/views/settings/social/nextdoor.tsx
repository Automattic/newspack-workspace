/**
 * Nextdoor integration for Newspack section
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import WizardsActionCard from '../../../../wizards-action-card';

/**
 * Components
 */
import { NextdoorData, NextdoorSettings, NextdoorStatus, OAuthResponse, ClaimPageResponse } from './nextdoor/types';
import { Onboarding } from './nextdoor/onboarding';
import { Settings } from './nextdoor/settings';

function Nextdoor() {
	const [ settings, setSettings ] = useState< NextdoorSettings >( {
		client_id: '',
		client_secret: '',
		publication_url: '',
		allowed_roles: [],
	} );
	const [ status, setStatus ] = useState< NextdoorStatus >( {
		is_connected: false,
		has_credentials: false,
		has_centralized_credentials: false,
		has_tokens: false,
		has_page: false,
		token_valid: false,
	} );
	const [ error, setError ] = useState< string | null >( null );

	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< NextdoorData >( {
		path: '/newspack/v1/wizard/newspack-settings/social/nextdoor',
		apiNamespace: 'newspack-settings/social/nextdoor',
		data: {
			module_enabled_nextdoor: false,
			is_connected: false,
			connection_status: {
				is_connected: false,
				has_credentials: false,
				has_centralized_credentials: false,
				has_tokens: false,
				has_page: false,
				token_valid: false,
			},
			settings: {
				client_id: '',
				client_secret: '',
				publication_url: '',
				allowed_roles: [],
			},
		},
		description: __(
			'Enable publishers to easily connect their Nextdoor account to Newspack and share posts directly to their Nextdoor community.',
			'newspack-plugin'
		),
	} );

	useEffect( () => {
		if ( apiData.connection_status ) {
			setStatus( apiData.connection_status );
			setSettings( { ...settings, allowed_roles: apiData.settings.allowed_roles } );
		}
	}, [ apiData ] );

	const updateSettings = async ( newSettings: Partial< NextdoorSettings > ): Promise< NextdoorSettings > => {
		try {
			setError( null );
			const response = await apiFetch< NextdoorData >( {
				path: '/newspack/v1/wizard/newspack-settings/social/nextdoor',
				method: 'POST',
				data: newSettings,
			} );

			if ( response.settings ) {
				const updatedSettings = { ...settings, ...response.settings };
				setSettings( updatedSettings );
				return updatedSettings;
			}

			return settings;
		} catch ( fetchError ) {
			const errorMsg: string =
				fetchError instanceof Object && 'message' in fetchError
					? ( fetchError as { message: string } ).message
					: __( 'Failed to update settings.', 'newspack-plugin' );
			setError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const startOAuthFlow = async ( email: string, country: string ): Promise< OAuthResponse > => {
		try {
			setError( null );
			const response = await apiFetch( {
				path: '/newspack/v1/nextdoor/oauth/start',
				method: 'POST',
				data: { email, country },
			} );
			return response as OAuthResponse;
		} catch ( fetchError ) {
			const errorMsg: string =
				fetchError instanceof Object && 'message' in fetchError
					? ( fetchError as { message: string } ).message
					: __( 'Failed to start OAuth flow.', 'newspack-plugin' );
			setError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const claimPage = async ( publicationUrl: string, test: boolean = false ): Promise< ClaimPageResponse > => {
		try {
			setError( null );
			const response = await apiFetch( {
				path: '/newspack/v1/nextdoor/claim-page',
				method: 'POST',
				data: { publication_url: publicationUrl, test },
			} );
			return response as ClaimPageResponse;
		} catch ( fetchError ) {
			const errorMsg: string =
				fetchError instanceof Object && 'message' in fetchError
					? ( fetchError as { message: string } ).message
					: __( 'Failed to claim page.', 'newspack-plugin' );
			setError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const disconnect = async (): Promise< void > => {
		try {
			setError( null );
			await apiFetch( {
				path: '/newspack/v1/nextdoor/disconnect',
				method: 'DELETE',
			} );
			handleToggle( true );
		} catch ( fetchError ) {
			const errorMsg: string =
				fetchError instanceof Object && 'message' in fetchError
					? ( fetchError as { message: string } ).message
					: __( 'Failed to disconnect.', 'newspack-plugin' );
			setError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const getDescription = () => {
		if ( isFetching ) {
			return __( 'Loading…', 'newspack-plugin' );
		}

		if ( apiData.module_enabled_nextdoor ) {
			if ( apiData.is_connected ) {
				return __( 'Nextdoor integration is enabled and connected.', 'newspack-plugin' );
			}
			return __( 'Nextdoor integration is enabled but not connected. Complete the setup to start sharing posts.', 'newspack-plugin' );
		}

		return description;
	};

	const handleToggle = ( value: boolean ) => {
		apiFetchToggle( { ...apiData, module_enabled_nextdoor: value }, true );
	};

	return (
		<>
			<WizardsActionCard
				title={ __( 'Nextdoor Integration', 'newspack-plugin' ) }
				description={ getDescription() }
				disabled={ isFetching }
				actionText={ actionText }
				error={ errorMessage }
				toggleChecked={ apiData.module_enabled_nextdoor }
				toggleOnChange={ handleToggle }
			>
				{ apiData.module_enabled_nextdoor && (
					<>
						{ apiData.is_connected ? (
							<Settings
								settings={ settings }
								status={ status }
								error={ error }
								updateSettings={ updateSettings }
								setError={ setError }
								disconnect={ disconnect }
							/>
						) : (
							<Onboarding
								settings={ settings }
								status={ status }
								error={ error }
								updateSettings={ updateSettings }
								startOAuthFlow={ startOAuthFlow }
								claimPage={ claimPage }
								disconnect={ disconnect }
								setError={ setError }
							/>
						) }
					</>
				) }
			</WizardsActionCard>
		</>
	);
}

export default Nextdoor;
