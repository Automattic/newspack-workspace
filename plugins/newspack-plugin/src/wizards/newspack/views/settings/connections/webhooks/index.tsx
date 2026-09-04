/**
 * Settings Wizard: Connections > Webhooks.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, Fragment } from '@wordpress/element';
import { ExternalLink, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { connection } from '@wordpress/icons';
import { Card as UICard } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { API_NAMESPACE } from './constants';
import EndpointActionsCard from './endpoint-actions-card';
import EndpointActionsModals from './endpoint-actions-modals';
import { useWizardApiFetch } from '../../../../../hooks/use-wizard-api-fetch';
import { Card, Button, SectionHeader } from '../../../../../../../packages/components/src';
import EmptyState from '../../../../../../../packages/components/src/empty-state';

const LEARN_MORE_URL = 'https://help.newspack.com/plugins-and-themes/third-party-services-integrations/webhooks/';

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

	const isEmpty = ! inFlight && ! endpoints?.length;

	return (
		<Card noBorder className="newspack-webhooks">
			<HStack justify="space-between" alignment="bottom" spacing={ 4 } className="newspack-webhooks__header">
				<SectionHeader
					title={ __( 'Webhook Endpoints', 'newspack-plugin' ) }
					heading={ 3 }
					description={ __(
						'Register webhook endpoints to integrate reader activity data to third-party services or private APIs',
						'newspack-plugin'
					) }
					noMargin
				/>
				{ ! isEmpty && (
					<Button variant="primary" onClick={ () => setActionHandler( 'new' ) } disabled={ inFlight }>
						{ inFlight ? __( 'Loading…', 'newspack-plugin' ) : __( 'Add Endpoint', 'newspack-plugin' ) }
					</Button>
				) }
			</HStack>
			{ ! inFlight &&
				( endpoints && endpoints.length > 0 ? (
					<Fragment>
						{ endpoints.map( endpoint => (
							<EndpointActionsCard key={ endpoint.id } endpoint={ endpoint } setAction={ setActionHandler } />
						) ) }
					</Fragment>
				) : (
					<UICard.Root>
						<UICard.Content>
							<EmptyState.Root size="small">
								<EmptyState.Header
									icon={ connection }
									title={ __( 'No endpoints yet', 'newspack-plugin' ) }
									description={ __( 'Add an endpoint to start sending reader activity data.', 'newspack-plugin' ) }
								/>
								<EmptyState.Actions orientation="column" gap="lg">
									<Button variant="primary" onClick={ () => setActionHandler( 'new' ) }>
										{ __( 'Add Endpoint', 'newspack-plugin' ) }
									</Button>
									<ExternalLink
										href={ LEARN_MORE_URL }
										aria-label={ __( 'Learn more about webhooks (opens in a new tab)', 'newspack-plugin' ) }
									>
										{ __( 'Learn more', 'newspack-plugin' ) }
									</ExternalLink>
								</EmptyState.Actions>
							</EmptyState.Root>
						</UICard.Content>
					</UICard.Root>
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
