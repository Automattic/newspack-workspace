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
import { Notice as WPNotice, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, CardForm } from '../../../../../../packages/components/src';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';
import { useErrorAnnouncement, useSocialCards } from './context';

/**
 * Components
 */
import { NextdoorData, NextdoorSettings, NextdoorStatus, NextdoorUpdatePayload, OAuthResponse, ClaimPageResponse } from './nextdoor/types';
import { Onboarding } from './nextdoor/onboarding';
import { Settings } from './nextdoor/settings';

// Brand name, deliberately untranslated.
const TITLE = 'Nextdoor';

const isOAuthReturn = () => {
	const params = new URLSearchParams( window.location.search );
	return params.get( 'oauth_success' ) === '1' || !! params.get( 'nextdoor_oauth_error' );
};

// While the module is off the endpoint returns `connection_status` and
// `settings` as an empty JSON array, which is truthy — so the presence of a
// real field, not the object itself, is what says the payload is populated.
const hasConnectionStatus = ( data: NextdoorData ) => typeof data?.connection_status?.is_connected === 'boolean';

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
	const [ hasSyncedStatus, setHasSyncedStatus ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ errorNonce, setErrorNonce ] = useState( 0 );

	const bumpErrorNonce = () => setErrorNonce( current => current + 1 );

	// Every error the card shows goes through here, so a retry that fails the
	// same way still counts as a fresh occurrence for the announcement.
	const reportError = ( message: string | null ) => {
		setError( message );
		if ( message ) {
			bumpErrorNonce();
		}
	};

	const { description, apiData, isFetching, apiFetchToggle, errorMessage, refresh, resetError } = useWizardApiFetchToggle<
		NextdoorData,
		NextdoorUpdatePayload
	>( {
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
			'Enable publishers to easily connect their Nextdoor account and share posts directly to their Nextdoor community.',
			'newspack-plugin'
		),
	} );

	useEffect( () => {
		if ( ! hasConnectionStatus( apiData ) ) {
			return;
		}
		setStatus( apiData.connection_status );
		setHasSyncedStatus( true );
		// The secret is the one field left blank: the GET returns it in plaintext
		// and the onboarding field is a password input the publisher retypes.
		setSettings( current => ( { ...current, ...apiData.settings, client_secret: '' } ) );
	}, [ apiData ] );

	// Routed through the toggle hook rather than a raw `apiFetch`, so a save also
	// updates `apiData` and the store's GET cache — the status grid below reads
	// the former, and a remount (switching settings tabs) is served the latter.
	// Failures surface through `errorMessage`, which the card already renders.
	const updateSettings = async ( payload: NextdoorUpdatePayload ): Promise< void > => {
		setError( null );
		resetError();
		try {
			await apiFetchToggle( payload, true );
		} catch ( fetchError ) {
			bumpErrorNonce();
			throw fetchError;
		}
	};

	const startOAuthFlow = async ( email: string, country: string ): Promise< OAuthResponse > => {
		try {
			setError( null );
			resetError();
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
			reportError( errorMsg );
			throw new Error( errorMsg );
		}
	};

	const claimPage = async ( publicationUrl: string, test: boolean = false ): Promise< ClaimPageResponse > => {
		try {
			setError( null );
			resetError();
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
			reportError( errorMsg );
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
			reportError( errorMsg );
			throw new Error( errorMsg );
		}

		// The tokens are gone, so stop claiming a connection before the refresh
		// lands: a refresh that fails would otherwise leave the card reporting
		// "Connected" and offering Disconnect for a connection that no longer exists.
		setStatus( current => ( { ...current, is_connected: false, has_tokens: false, has_page: false, token_valid: false } ) );

		// The disconnect endpoint only clears tokens; it doesn't touch apiData, so
		// apiData would still read "connected" without this.
		await refresh();
	};

	const { notify } = useSocialCards();
	useErrorAnnouncement( errorMessage ?? error, errorNonce );
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isEnabling, setIsEnabling ] = useState( false );
	const [ hasAutoOpened, setHasAutoOpened ] = useState( false );

	const isEnabled = apiData.module_enabled_nextdoor;
	// `status` is what the body reads and what `disconnect()` clears optimistically,
	// so the badge follows it — but only once it has been synced: its initial `false`
	// is indistinguishable from a real "not connected" and would flash the wrong badge
	// on every load of a connected site.
	const isConnected = hasSyncedStatus ? status.is_connected : apiData.is_connected;

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
			return { level: 'error' as const, text: __( 'Not connected', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Enabled', 'newspack-plugin' ) };
	} )();

	// Only the flag: a full snapshot would write back read-only fields the endpoint
	// ignores, and put the plaintext client secret on the wire for no reason.
	const setModuleEnabled = ( value: boolean ) =>
		apiFetchToggle( { module_enabled_nextdoor: value }, true ).catch( fetchError => {
			bumpErrorNonce();
			throw fetchError;
		} );

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
		/* translators: %s: integration name (e.g. "Nextdoor"). */
		notify( sprintf( __( '%s disabled.', 'newspack-plugin' ), TITLE ) );
	};

	// Cancel: abandoning a fresh Enable rolls the module back off.
	const cancel = async () => {
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

	// Escape: a pure UI dismissal. It must never deactivate a module the user may
	// already have saved credentials into, so the module stays on and the card
	// leaves its enabling session behind.
	const dismiss = () => {
		resetError();
		setIsEnabling( false );
		setIsOpen( false );
	};

	/* translators: %s: integration name (e.g. "Nextdoor"). */
	const editLabel = sprintf( __( 'Edit %s', 'newspack-plugin' ), TITLE );
	/* translators: %s: integration name (e.g. "Nextdoor"). */
	const cancelLabel = sprintf( __( 'Cancel editing %s', 'newspack-plugin' ), TITLE );
	/* translators: %s: integration name (e.g. "Nextdoor"). */
	const enableLabel = sprintf( __( 'Enable %s', 'newspack-plugin' ), TITLE );

	const actions = isEnabled ? (
		<Button
			variant="tertiary"
			size="compact"
			aria-label={ isOpen ? cancelLabel : editLabel }
			disabled={ isFetching }
			onClick={ () => ( isOpen ? cancel() : setIsOpen( true ) ) }
		>
			<span className="newspack-social-settings__toggle-label">
				<span className={ classnames( { 'is-visible': isOpen } ) }>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className={ classnames( { 'is-visible': ! isOpen } ) }>{ __( 'Edit', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	) : (
		<Button variant="secondary" size="compact" aria-label={ enableLabel } isBusy={ isFetching } disabled={ isFetching } onClick={ enable }>
			<span className="newspack-social-settings__toggle-label">
				<span>{ __( 'Cancel', 'newspack-plugin' ) }</span>
				<span className="is-visible">{ __( 'Enable', 'newspack-plugin' ) }</span>
			</span>
		</Button>
	);

	const renderSecondaryActions = () => (
		<Button variant="tertiary" __next40pxDefaultSize isDestructive isBusy={ isFetching } disabled={ isFetching } onClick={ disable }>
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
			onRequestClose={ dismiss }
		>
			<VStack spacing={ 4 }>
				{ errorMessage && (
					<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
						{ errorMessage }
					</WPNotice>
				) }
				{ isConnected ? (
					<Settings
						settings={ settings }
						status={ status }
						error={ error }
						updateSettings={ updateSettings }
						setError={ reportError }
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
						setError={ reportError }
						renderSecondaryActions={ secondaryActions }
					/>
				) }
			</VStack>
		</CardForm>
	);
}

export default Nextdoor;
