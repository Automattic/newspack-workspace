/**
 * Newspack > Settings > Social: Nextdoor integration.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
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

const TITLE = __( 'Nextdoor Integration', 'newspack-plugin' );

const isOAuthReturn = () => {
	const params = new URLSearchParams( window.location.search );
	return params.get( 'oauth_success' ) === '1' || !! params.get( 'nextdoor_oauth_error' );
};

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

	const { description, apiData, isFetching, apiFetchToggle, errorMessage, refresh, resetError } = useWizardApiFetchToggle< NextdoorData >( {
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
			resetError();
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

		// The disconnect endpoint only clears tokens; it doesn't touch apiData, so
		// apiData/status would still read "connected" without this.
		await refresh();
	};

	const { notify } = useSocialCards();
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isEnabling, setIsEnabling ] = useState( false );
	const [ hasAutoOpened, setHasAutoOpened ] = useState( false );

	const isEnabled = apiData.module_enabled_nextdoor;
	const isConnected = apiData.is_connected;

	// Only the OAuth redirect reopens the card: that user arrives on a fresh page
	// load mid-setup, so the step they were on would otherwise be invisible. Any
	// other unfinished setup stays collapsed behind its badge.
	useEffect( () => {
		if ( hasAutoOpened || isFetching || ! isEnabled || isConnected || ! isOAuthReturn() ) {
			return;
		}
		setHasAutoOpened( true );
		setIsOpen( true );
	}, [ hasAutoOpened, isFetching, isEnabled, isConnected ] );

	const badge = ( () => {
		if ( errorMessage ) {
			return { level: 'error' as const, text: __( 'Error', 'newspack-plugin' ) };
		}
		if ( ! isEnabled || ( isOpen && isEnabling ) ) {
			return undefined;
		}
		if ( ! isConnected ) {
			return { level: 'warning' as const, text: __( 'Setup incomplete', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Connected', 'newspack-plugin' ) };
	} )();

	const setModuleEnabled = ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_nextdoor: value }, true );

	// Every user-initiated action starts from a clean error state, so a failure
	// the user has since retried past stops showing in the header.
	const enable = async () => {
		resetError();
		try {
			await setModuleEnabled( true );
		} catch {
			return;
		}
		setIsEnabling( true );
		setIsOpen( true );
	};

	const disable = async () => {
		resetError();
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
		resetError();
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

	/* translators: %s: integration name (e.g. "Nextdoor Integration"). */
	const editLabel = sprintf( __( 'Edit %s', 'newspack-plugin' ), TITLE );
	/* translators: %s: integration name (e.g. "Nextdoor Integration"). */
	const cancelLabel = sprintf( __( 'Cancel editing %s', 'newspack-plugin' ), TITLE );
	/* translators: %s: integration name (e.g. "Nextdoor Integration"). */
	const enableLabel = sprintf( __( 'Enable %s', 'newspack-plugin' ), TITLE );

	const actions = isEnabled ? (
		<Button
			variant="tertiary"
			size="compact"
			aria-label={ isOpen ? cancelLabel : editLabel }
			disabled={ isFetching }
			onClick={ () => ( isOpen ? close() : setIsOpen( true ) ) }
		>
			<span className="newspack-social-settings__toggle-label">
				<span className={ classnames( { 'is-visible': isOpen } ) }>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className={ classnames( { 'is-visible': ! isOpen } ) }>{ __( 'Edit', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	) : (
		<Button variant="secondary" size="compact" aria-label={ enableLabel } isBusy={ isFetching } disabled={ isFetching } onClick={ enable }>
			{ __( 'Enable', 'newspack-plugin' ) }
		</Button>
	);

	const renderSecondaryActions = () => (
		<Button variant="tertiary" size="compact" isDestructive isBusy={ isFetching } disabled={ isFetching } onClick={ disable }>
			{ __( 'Disable', 'newspack-plugin' ) }
		</Button>
	);
	// Suppressed during a fresh Enable: Cancel already covers that exit, and Disable
	// would otherwise announce a module the user hasn't finished enabling.
	const secondaryActions = isEnabling ? undefined : renderSecondaryActions;

	return (
		<CardForm
			title={ TITLE }
			description={ ! isOpen && errorMessage ? errorMessage : description }
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
						renderSecondaryActions={ secondaryActions }
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
						renderSecondaryActions={ secondaryActions }
					/>
				) }
			</VStack>
		</CardForm>
	);
}

export default Nextdoor;
