/**
 * Newspack > Settings > Social: Publicize.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, CardForm, Handoff } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

const JETPACK_EDIT_LINK = 'admin.php?page=jetpack#/sharing';

const DESCRIPTION = __(
	"Powered by Jetpack. Publicize makes it easy to share your site's posts on several social media networks automatically when you publish a new post.",
	'newspack-plugin'
);

const Publicize = () => {
	const { wizardApiFetch, isFetching, errorMessage } = useWizardApiFetch( '/newspack/wizards/plugins/jetpack' );
	const [ plugin, setPlugin ] = useState< { status: string; configured: boolean } | null >( null );

	const load = () =>
		wizardApiFetch< PluginResponse >(
			{ path: '/newspack/v1/plugins/jetpack' },
			{ onSuccess: res => setPlugin( { status: res.Status, configured: res.Configured } ) }
		).catch( () => {} );

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
		if ( ! plugin ) {
			return undefined;
		}
		if ( ! isInstalled ) {
			return { level: 'default' as const, text: __( 'Not installed', 'newspack-plugin' ) };
		}
		if ( ! isActive ) {
			return { level: 'default' as const, text: __( 'Inactive', 'newspack-plugin' ) };
		}
		if ( ! isConfigured ) {
			return { level: 'warning' as const, text: __( 'Not connected', 'newspack-plugin' ) };
		}
		return { level: 'success' as const, text: __( 'Connected', 'newspack-plugin' ) };
	} )();

	const install = () =>
		wizardApiFetch< PluginResponse >(
			{ path: '/newspack/v1/plugins/jetpack/activate', method: 'POST' },
			{ onSuccess: () => window.location.reload() }
		).catch( () => {} );

	const actions = ( () => {
		if ( ! plugin ) {
			return null;
		}
		if ( isActive ) {
			return (
				<Handoff plugin="jetpack" editLink={ JETPACK_EDIT_LINK } variant="tertiary" size="compact" compact>
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
			title={ __( 'Publicize', 'newspack-plugin' ) }
			description={ errorMessage ?? DESCRIPTION }
			badge={ badge }
			actions={ actions }
			isOpen={ false }
		/>
	);
};

export default Publicize;
