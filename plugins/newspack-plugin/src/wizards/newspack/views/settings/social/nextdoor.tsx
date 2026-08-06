/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, CardForm, Notice } from '../../../../../../packages/components/src';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import { useSocialCards } from './context';

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

	const { description, apiData, isFetching, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< NextdoorData >( {
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
		} catch ( fetchError ) {
			const errorMsg: string =
				fetchError instanceof Object && 'message' in fetchError
					? ( fetchError as { message: string } ).message
					: __( 'Failed to disconnect.', 'newspack-plugin' );
			setError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const { notify } = useSocialCards();
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isEnabling, setIsEnabling ] = useState( false );
	const [ hasAutoOpened, setHasAutoOpened ] = useState( false );

	const isEnabled = apiData.module_enabled_nextdoor;
	const isConnected = apiData.is_connected;

	// A user returning from the Nextdoor OAuth redirect arrives on a fresh page
	// load, so an unfinished setup has to reopen itself or the step they were on
	// is invisible.
	useEffect( () => {
		if ( hasAutoOpened || isFetching || ! isEnabled || isConnected ) {
			return;
		}
		setHasAutoOpened( true );
		setIsOpen( true );
	}, [ hasAutoOpened, isFetching, isEnabled, isConnected ] );

	const badge = ( () => {
		if ( ! isEnabled || ( isOpen && isEnabling ) ) {
			return undefined;
		}
		if ( ! isConnected ) {
			return { level: 'warning' as const, text: __( 'Setup incomplete', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Connected', 'newspack-plugin' ) };
	} )();

	const setModuleEnabled = ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_nextdoor: value }, true );

	const enable = async () => {
		try {
			await setModuleEnabled( true );
		} catch {
			return;
		}
		setIsEnabling( true );
		setIsOpen( true );
	};

	const disable = async () => {
		try {
			await setModuleEnabled( false );
		} catch {
			return;
		}
		setIsEnabling( false );
		setIsOpen( false );
		notify( __( 'Nextdoor Integration disabled.', 'newspack-plugin' ) );
	};

	const close = async () => {
		if ( isEnabling ) {
			try {
				await setModuleEnabled( false );
			} catch {
				return;
			}
			setIsEnabling( false );
		}
		setIsOpen( false );
	};

	const actions = isEnabled ? (
		<Button
			variant="tertiary"
			size="compact"
			aria-label={
				isOpen ? __( 'Cancel editing Nextdoor Integration', 'newspack-plugin' ) : __( 'Edit Nextdoor Integration', 'newspack-plugin' )
			}
			disabled={ isFetching }
			onClick={ () => ( isOpen ? close() : setIsOpen( true ) ) }
		>
			<span className="newspack-social-settings__toggle-label">
				<span className={ classnames( { 'is-visible': isOpen } ) }>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className={ classnames( { 'is-visible': ! isOpen } ) }>{ __( 'Edit', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	) : (
		<Button
			variant="secondary"
			size="compact"
			aria-label={ __( 'Enable Nextdoor Integration', 'newspack-plugin' ) }
			isBusy={ isFetching }
			disabled={ isFetching }
			onClick={ enable }
		>
			{ __( 'Enable', 'newspack-plugin' ) }
		</Button>
	);

	const renderSecondaryActions = () => (
		<Button variant="tertiary" size="compact" isDestructive isBusy={ isFetching } disabled={ isFetching } onClick={ disable }>
			{ __( 'Disable', 'newspack-plugin' ) }
		</Button>
	);

	return (
		<CardForm
			title={ __( 'Nextdoor Integration', 'newspack-plugin' ) }
			description={ description }
			badge={ badge }
			actions={ actions }
			isOpen={ isOpen }
			onRequestClose={ close }
		>
			<VStack spacing={ 4 }>
				{ errorMessage && <Notice isError noticeText={ errorMessage } /> }
				{ isConnected ? (
					<Settings
						settings={ settings }
						status={ status }
						error={ error }
						updateSettings={ updateSettings }
						setError={ setError }
						disconnect={ disconnect }
						renderSecondaryActions={ renderSecondaryActions }
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
						renderSecondaryActions={ renderSecondaryActions }
					/>
				) }
			</VStack>
		</CardForm>
	);
}

export default Nextdoor;
