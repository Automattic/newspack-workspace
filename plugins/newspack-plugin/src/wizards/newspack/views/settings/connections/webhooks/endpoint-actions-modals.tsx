/**
 * Settings Wizard: Connections > Webhooks > Endpoint Actions Modals
 */

/**
 * WordPress dependencies
 */
import { Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ModalView from './modals/view';
import { getDisplayUrl } from './utils';
import ModalUpsert from './modals/upsert';
import ModalConfirmation from './modals/confirmation';
import { ENDPOINTS_CACHE_KEY } from './constants';

const EndpointActionsModals = ( {
	endpoint,
	actions,
	action = null,
	errorMessage = null,
	inFlight = false,
	setAction,
	setError,
	setEndpoints,
	wizardApiFetch,
}: ModalComponentProps ) => {
	const onSuccess = ( endpointId: string | number, response: Endpoint[] ) => {
		setEndpoints( response );
		setAction( null, endpointId );
	};

	// API
	function toggleEndpoint( endpointToToggle: Endpoint ) {
		wizardApiFetch< Endpoint[] >(
			{
				path: `/newspack/v1/webhooks/endpoints/${ endpointToToggle.id }`,
				method: 'POST',
				data: { disabled: ! endpointToToggle.disabled },
				updateCacheKey: ENDPOINTS_CACHE_KEY,
			},
			{
				onSuccess: endpoints => onSuccess( endpointToToggle.id, endpoints ),
			}
		);
	}
	function deleteEndpoint( endpointToDelete: Endpoint ) {
		wizardApiFetch< Endpoint[] >(
			{
				path: `/newspack/v1/webhooks/endpoints/${ endpointToDelete.id }`,
				method: 'DELETE',
				updateCacheKey: ENDPOINTS_CACHE_KEY,
			},
			{
				onSuccess: endpoints => onSuccess( endpointToDelete.id, endpoints ),
			}
		);
	}

	return (
		<Fragment>
			{ action === 'delete' && (
				<ModalConfirmation
					title={ __( 'Remove Endpoint', 'newspack-plugin' ) }
					description={ sprintf(
						/* translators: %s: endpoint title */
						__( 'Are you sure you want to remove the endpoint %s?', 'newspack-plugin' ),
						`"${ getDisplayUrl( endpoint.url ) }"`
					) }
					onClose={ () => setAction( null, endpoint.id ) }
					onConfirm={ () => deleteEndpoint( endpoint ) }
					disabled={ inFlight }
				/>
			) }
			{ action === 'toggle' && (
				<ModalConfirmation
					title={ endpoint.disabled ? __( 'Enable Endpoint', 'newspack-plugin' ) : __( 'Disable Endpoint', 'newspack-plugin' ) }
					description={
						endpoint.disabled
							? sprintf(
									/* translators: %s: endpoint title */
									__( 'Are you sure you want to enable the endpoint %s?', 'newspack-plugin' ),
									`"${ getDisplayUrl( endpoint.url ) }"`
							  )
							: sprintf(
									/* translators: %s: endpoint title */
									__( 'Are you sure you want to disable the endpoint %s?', 'newspack-plugin' ),
									`"${ getDisplayUrl( endpoint.url ) }"`
							  )
					}
					onClose={ () => setAction( null, endpoint.id ) }
					onConfirm={ () => toggleEndpoint( endpoint ) }
					disabled={ inFlight }
				/>
			) }
			{ action === 'view' && (
				<ModalView
					{ ...{
						action,
						actions,
						endpoint,
						inFlight,
						setAction,
						setError,
						errorMessage,
						setEndpoints,
						wizardApiFetch,
					} }
				/>
			) }
			{ [ 'edit', 'new' ].includes( action ?? '' ) && (
				<ModalUpsert
					{ ...{
						endpoint,
						actions,
						errorMessage,
						inFlight,
						setError,
						setAction,
						setEndpoints,
						wizardApiFetch,
					} }
				/>
			) }
		</Fragment>
	);
};

export default EndpointActionsModals;
