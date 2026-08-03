/**
 * Ad Providers view.
 */

/**
 * WordPress dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { PluginToggle, ActionCard, Modal, Card, Button, withWizardScreen } from '../../../../../packages/components/src';
import GAMOnboarding from '../../components/onboarding';

/**
 * Advertising management screen.
 */
const Providers = ( { services, fetchAdvertisingData, toggleService } ) => {
	const { google_ad_manager } = services;
	const [ inFlight, setInFlight ] = useState( false );
	const [ networkCode, setNetworkCode ] = useState( '' );
	const [ isOnboarding, setIsOnboarding ] = useState( false );

	const updateGAMNetworkCode = () => {
		setInFlight( true );
		apiFetch( {
			path: '/newspack/v1/wizard/billboard/network_code/',
			method: 'POST',
			data: { network_code: networkCode, is_gam: false },
			quiet: true,
		} ).finally( () => {
			fetchAdvertisingData();
			setInFlight( false );
			setIsOnboarding( false );
		} );
	};

	let notifications = [];

	if ( google_ad_manager.enabled && google_ad_manager.status.error ) {
		notifications = notifications.concat( [ google_ad_manager.status.error, '\u00A0' ] );
	} else if ( google_ad_manager?.created_targeting_keys?.length > 0 ) {
		notifications = notifications.concat( [
			__( 'Created custom targeting keys:', 'newspack-plugin' ) + '\u00A0',
			google_ad_manager.created_targeting_keys.join( ', ' ) + '. \u00A0',
			// eslint-disable-next-line react/jsx-indent
			<ExternalLink
				href={ `https://admanager.google.com/${ google_ad_manager.network_code }#inventory/custom_targeting/list` }
				key="google-ad-manager-custom-targeting-link"
			>
				{ __( 'Visit your GAM dashboard', 'newspack-plugin' ) }
			</ExternalLink>,
		] );
	}

	if ( google_ad_manager.enabled && google_ad_manager.available && ! google_ad_manager.status.connected ) {
		notifications.push(
			<Button key="gam-connect-account" isLink onClick={ () => setIsOnboarding( true ) }>
				{ __( 'Click here to connect your account.', 'newspack-plugin' ) }
			</Button>
		);
	}

	return (
		<Fragment>
			<h1>{ __( 'Providers', 'newspack-plugin' ) }</h1>
			<ActionCard
				title={ __( 'Google Ad Manager', 'newspack-plugin' ) }
				description={ __( 'Manage Google Ad Manager ad units and placements directly from the Newspack dashboard.', 'newspack-plugin' ) }
				actionText={ google_ad_manager && google_ad_manager.enabled && __( 'Configure', 'newspack-plugin' ) }
				toggle
				toggleChecked={ google_ad_manager && google_ad_manager.enabled }
				toggleOnChange={ value => {
					toggleService( 'google_ad_manager', value ).then( () => {
						if ( value === true && ! google_ad_manager.status.connected && ! google_ad_manager.status.network_code ) {
							setIsOnboarding( true );
						}
					} );
				} }
				titleLink={ google_ad_manager?.enabled ? '#/google_ad_manager' : null }
				href={ google_ad_manager?.enabled && '#/google_ad_manager' }
				notification={ notifications.length ? notifications : null }
				notificationLevel={ google_ad_manager.created_targeting_keys?.length ? 'success' : 'error' }
			/>
			<PluginToggle
				plugins={ {
					broadstreet: {
						actionText: __( 'Configure', 'newspack-plugin' ),
						href: '/wp-admin/admin.php?page=Broadstreet',
					},
				} }
			/>
			{ isOnboarding && (
				<Modal title={ __( 'Google Ad Manager Setup', 'newspack-plugin' ) } onRequestClose={ () => setIsOnboarding( false ) }>
					<GAMOnboarding
						onUpdate={ data => setNetworkCode( data.networkCode ) }
						onSuccess={ () => {
							fetchAdvertisingData();
							setIsOnboarding( false );
						} }
					/>
					<Card buttonsCard noBorder className="justify-end">
						<Button isSecondary disabled={ inFlight } onClick={ () => setIsOnboarding( false ) }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button isPrimary disabled={ inFlight || ! networkCode } onClick={ () => updateGAMNetworkCode() }>
							{ __( 'Save', 'newspack-plugin' ) }
						</Button>
					</Card>
				</Modal>
			) }
		</Fragment>
	);
};

export default withWizardScreen( Providers );
