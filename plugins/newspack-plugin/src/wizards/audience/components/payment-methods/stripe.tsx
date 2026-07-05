/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { ActionCard, Button } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

export const Stripe = ( { stripe } ) => {
	const isLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isLoading() );
	const isQuietLoading = useSelect( select => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() );
	const { updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const changeHandler = ( key, value ) =>
		updateWizardSettings( {
			slug: 'newspack-audience/payment',
			path: [ 'payment_gateways', 'stripe', key ],
			value,
		} );

	const { saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const onSave = () =>
		saveWizardSettings( {
			slug: 'newspack-audience/payment',
			section: 'stripe',
			payloadPath: [ 'payment_gateways', 'stripe' ],
		} );
	const testMode = stripe?.testMode;
	const isConnectedApi = testMode ? stripe?.is_connected_api_test : stripe?.is_connected_api_live;
	const isConnectedOauth = testMode ? stripe?.is_connected_oauth_test : stripe?.is_connected_oauth_live;
	const getConnectionStatus = () => {
		if ( ! stripe?.enabled ) {
			return null;
		}
		if ( isLoading || isQuietLoading ) {
			return __( 'Loading…', 'newspack-plugin' );
		}
		if ( ! isConnectedApi ) {
			return __( 'Not connected', 'newspack-plugin' );
		}
		if ( ! isConnectedOauth ) {
			return __( 'Needs attention', 'newspack-plugin' );
		}
		if ( testMode ) {
			return __( 'Connected - test mode', 'newspack-plugin' );
		}
		return __( 'Connected', 'newspack-plugin' );
	};
	const getBadgeLevel = () => {
		if ( ! stripe?.enabled || isLoading || isQuietLoading ) {
			return 'info';
		}
		if ( ! isConnectedApi ) {
			return 'error';
		}
		if ( ! isConnectedOauth ) {
			return 'warning';
		}
		return 'success';
	};

	return (
		<ActionCard
			isMedium
			title={ __( 'Stripe', 'newspack-plugin' ) }
			description={ () => (
				<>
					{ __( 'Enable the Stripe payment gateway for WooCommerce. ', 'newspack-plugin' ) }
					<ExternalLink href="https://woocommerce.com/document/stripe/">{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
				</>
			) }
			hasWhiteHeader
			toggleChecked={ !! stripe.enabled }
			toggleOnChange={ () => {
				changeHandler( 'enabled', ! stripe.enabled );
				onSave();
			} }
			badge={ getConnectionStatus() }
			badgeLevel={ getBadgeLevel() }
			actionContent={
				// eslint-disable-next-line no-nested-ternary
				! stripe?.enabled || isLoading || isQuietLoading ? null : isConnectedOauth ? (
					<Button
						variant="secondary"
						href="/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe&panel=settings"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Configure', 'newspack-plugin' ) }
					</Button>
				) : (
					<Button
						variant="primary"
						href="/wp-admin/admin.php?page=wc-settings&tab=checkout&section=stripe&panel=payment-methods"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Connect', 'newspack-plugin' ) }
					</Button>
				)
			}
		/>
	);
};
