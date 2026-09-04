/**
 * Settings Wizard: Connections > Webhooks.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState, Fragment } from '@wordpress/element';
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
import { Card, Button, Notice, SectionHeader } from '../../../../../../../packages/components/src';
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

	// `endpoints` is null until the fetch resolves, and stays null if it fails.
	// Treating either as empty would offer onboarding to a site that has endpoints.
	const isLoaded = null !== endpoints;
	const isEmpty = ! inFlight && isLoaded && 0 === endpoints.length;

	const addRef = useRef< HTMLButtonElement >( null );
	const claimFocus = useRef( false );

	// The empty state's button is unmounted by the very success it triggers, so the
	// modal has nothing to restore focus to; the header button replaces it. Waiting for
	// the request to settle matters: the save flips `inFlight` first, and focusing the
	// header button while it is still disabled silently does nothing.
	useEffect( () => {
		if ( ! claimFocus.current || inFlight ) {
			return;
		}
		claimFocus.current = false;
		if ( ! isEmpty ) {
			addRef.current?.focus();
		}
	}, [ inFlight, isEmpty, selectedEndpoint ] );

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
					<Button ref={ addRef } variant="primary" onClick={ () => setActionHandler( 'new' ) } disabled={ inFlight }>
						{ inFlight ? __( 'Loading…', 'newspack-plugin' ) : __( 'Add Endpoint', 'newspack-plugin' ) }
					</Button>
				) }
			</HStack>
			{ ! inFlight &&
				isLoaded &&
				( endpoints.length > 0 ? (
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
									// Subordinate to the section header above, which is already a level 3.
									heading={ 4 }
									title={ __( 'No endpoints yet', 'newspack-plugin' ) }
									description={ __( 'Add an endpoint to start sending reader activity data.', 'newspack-plugin' ) }
								/>
								<EmptyState.Actions orientation="column" gap="lg">
									<Button
										ref={ addRef }
										variant="primary"
										onClick={ () => {
											claimFocus.current = true;
											setActionHandler( 'new' );
										} }
									>
										{ __( 'Add Endpoint', 'newspack-plugin' ) }
									</Button>
									<ExternalLink
										href={ LEARN_MORE_URL }
										aria-label={
											/* translators: accessibility text. Names the link's destination for screen readers; keep the new-tab clause, which replaces the one the link would otherwise announce. */
											__( 'Learn more about webhooks (opens in a new tab)', 'newspack-plugin' )
										}
									>
										{ __( 'Learn more', 'newspack-plugin' ) }
									</ExternalLink>
								</EmptyState.Actions>
							</EmptyState.Root>
						</UICard.Content>
					</UICard.Root>
				) ) }
			{ /* A failed load leaves `endpoints` null, which is neither a list nor an
			     empty state; without this the section renders as blank space. */ }
			{ ! inFlight && ! isLoaded && (
				<Notice isError noticeText={ errorMessage || __( 'Webhook endpoints could not be loaded.', 'newspack-plugin' ) } />
			) }
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
