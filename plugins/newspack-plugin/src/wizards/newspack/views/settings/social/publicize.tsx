/**
 * Newspack > Settings > Social: Publicize.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Notice as WPNotice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Button, CardForm, Handoff } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { useErrorAnnouncement } from './context';

const JETPACK_EDIT_LINK = 'admin.php?page=jetpack#/sharing';

const CARD_DESCRIPTION = __(
	"Publicize makes it easy to share your site's posts on several social media networks automatically when you publish a new post.",
	'newspack-plugin'
);

const Publicize = () => {
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( '/newspack/wizards/plugins/jetpack' );
	const [ plugin, setPlugin ] = useState< { status: string; configured: boolean } | null >( null );
	const [ isReloading, setIsReloading ] = useState( false );
	const [ errorNonce, setErrorNonce ] = useState( 0 );

	const bumpErrorNonce = () => setErrorNonce( current => current + 1 );

	useErrorAnnouncement( errorMessage, errorNonce );

	// Clears first so a retry that succeeds also clears the Error badge and the
	// notice the previous failure left behind.
	const load = () => {
		resetError();
		return wizardApiFetch< PluginResponse >(
			{ path: '/newspack/v1/plugins/jetpack' },
			{ onSuccess: res => setPlugin( { status: res.Status, configured: res.Configured } ) }
		).catch( bumpErrorNonce );
	};

	useEffect( () => {
		load();
	}, [] );

	const isInstalled = !! plugin && plugin.status !== 'uninstalled';
	const isActive = plugin?.status === 'active';
	const isConfigured = !! plugin?.configured;

	const badge = ( () => {
		if ( errorMessage ) {
			return { level: 'error' as const, text: __( 'Error', 'newspack-plugin' ) };
		}
		if ( ! isActive ) {
			return undefined;
		}
		if ( ! isConfigured ) {
			return { level: 'error' as const, text: __( 'Not connected', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Enabled', 'newspack-plugin' ) };
	} )();

	const install = () => {
		resetError();
		return wizardApiFetch< PluginResponse >(
			{ path: '/newspack/v1/plugins/jetpack/activate', method: 'POST' },
			{
				onSuccess: () => {
					setIsReloading( true );
					window.location.reload();
				},
			}
		).catch( bumpErrorNonce );
	};

	const actions = ( () => {
		if ( isReloading ) {
			return <span className="newspack-text-muted">{ __( 'Page reloading…', 'newspack-plugin' ) }</span>;
		}
		if ( ! plugin ) {
			// A failed mount fetch leaves `plugin` null with nothing in flight, so
			// the card would otherwise sit on a dead "Loading…" button for good.
			if ( ! isFetching && errorMessage ) {
				return (
					<Button variant="secondary" size="compact" onClick={ load }>
						{ __( 'Retry', 'newspack-plugin' ) }
					</Button>
				);
			}
			return (
				<Button variant="secondary" size="compact" isBusy={ isFetching } disabled>
					{ __( 'Loading…', 'newspack-plugin' ) }
				</Button>
			);
		}
		if ( isActive ) {
			// `url` rather than `plugin`, so Handoff skips the duplicate plugin GET it
			// would otherwise fire on mount. Both endpoints register the return banner.
			return (
				<Handoff url={ JETPACK_EDIT_LINK } variant="tertiary" size="compact" compact>
					{ isConfigured ? __( 'Configure', 'newspack-plugin' ) : __( 'Complete setup', 'newspack-plugin' ) }
				</Handoff>
			);
		}
		return (
			<Button variant="secondary" size="compact" isBusy={ isFetching } disabled={ isFetching } onClick={ install }>
				{ isInstalled ? __( 'Activate Jetpack', 'newspack-plugin' ) : __( 'Install Jetpack', 'newspack-plugin' ) }
			</Button>
		);
	} )();

	return (
		<CardForm
			title={ __( 'Jetpack Publicize', 'newspack-plugin' ) }
			description={ CARD_DESCRIPTION }
			badge={ badge }
			actions={ actions }
			isOpen={ !! errorMessage }
		>
			{ errorMessage && (
				<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
					{ errorMessage }
				</WPNotice>
			) }
		</CardForm>
	);
};

export default Publicize;
