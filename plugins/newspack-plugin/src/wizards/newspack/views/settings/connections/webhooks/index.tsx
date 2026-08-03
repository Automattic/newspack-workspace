/**
 * Settings Wizard: Connections > Webhooks.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { API_NAMESPACE } from './constants';
import EndpointActionsCard from './endpoint-actions-card';
import EndpointActionsModals from './endpoint-actions-modals';
import { useWizardApiFetch } from '../../../../../hooks/use-wizard-api-fetch';
import { Card, Button, Notice, SectionHeader } from '../../../../../../../packages/components/src';

const defaultEndpoint: Endpoint = {
	url: '',
	label: '',
	requests: [],
	disabled: false,
	disabled_error: false,
	id: 0,
	system: '',
	actions: [],
	bearer_token: '',
};

function Webhooks() {
	const { setError, resetError, errorMessage, wizardApiFetch, isFetching: inFlight } = useWizardApiFetch( API_NAMESPACE );

	const [ action, setAction ] = useState< WebhookActions >( null );
	const [ actions, setActions ] = useState< string[] >( [] );
	const [ endpoints, setEndpoints ] = useState< Endpoint[] | null >( null );
	const [ selectedEndpoint, setSelectedEndpoint ] = useState< Endpoint | null >( null );

	useEffect( () => {
		fetchActions();
		fetchEndpoints();
	}, [] );

	function fetchActions() {
		wizardApiFetch< never[] >(
			{
				path: '/newspack/v1/data-events/actions',
			},
			{
				onSuccess: newActions => setActions( newActions ),
			}
		);
	}

	function fetchEndpoints() {
		wizardApiFetch< Endpoint[] >(
			{ path: '/newspack/v1/webhooks/endpoints' },
			{
				onSuccess: newEndpoints => setEndpoints( newEndpoints ),
			}
		);
	}

	function setActionHandler( newAction: WebhookActions, id?: number | string ) {
		resetError();
		setAction( newAction );
		if ( newAction === null ) {
			setSelectedEndpoint( null );
		} else if ( newAction === 'new' ) {
			resetError();
			setSelectedEndpoint( { ...defaultEndpoint } );
		} else if ( endpoints && [ 'edit', 'delete', 'view', 'toggle' ].includes( newAction ) ) {
			setSelectedEndpoint( endpoints.find( endpoint => endpoint.id === id ) || null );
		}
	}

	return (
		<Card noBorder className="newspack-webhooks">
			<div className="flex justify-between items-end mb4">
				<SectionHeader
					title={ __( 'Webhook Endpoints', 'newspack-plugin' ) }
					heading={ 3 }
					description={ __(
						'Register webhook endpoints to integrate reader activity data to third-party services or private APIs',
						'newspack-plugin'
					) }
					noMargin
				/>
				<Button variant="primary" onClick={ () => setActionHandler( 'new' ) } disabled={ inFlight }>
					{ inFlight ? __( 'Loading…', 'newspack-plugin' ) : __( 'Add New Endpoint', 'newspack-plugin' ) }
				</Button>
			</div>
			{ ! inFlight &&
				( endpoints && endpoints.length > 0 ? (
					<Fragment>
						{ endpoints.map( endpoint => (
							<EndpointActionsCard key={ endpoint.id } endpoint={ endpoint } setAction={ setActionHandler } />
						) ) }
					</Fragment>
				) : (
					<Notice noticeText={ __( 'No endpoints found', 'newspack-plugin' ) } />
				) ) }
			{ selectedEndpoint && (
				<EndpointActionsModals
					actions={ actions }
					setError={ setError }
					action={ action }
					errorMessage={ errorMessage }
					inFlight={ inFlight }
					wizardApiFetch={ wizardApiFetch }
					endpoint={ selectedEndpoint }
					setAction={ setActionHandler }
					setEndpoints={ setEndpoints }
				/>
			) }
		</Card>
	);
}

export default Webhooks;
