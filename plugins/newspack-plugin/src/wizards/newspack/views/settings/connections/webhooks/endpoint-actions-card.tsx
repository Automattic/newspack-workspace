/**
 * Settings Wizard: Connections > Webhooks > Endpoint Actions Card
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getEndpointTitle } from './utils';
import EndpointActions from './endpoint-actions';
import WizardsActionCard from '../../../../../wizards-action-card';

function EndpointActionsCard( { endpoint, setAction }: { endpoint: Endpoint; setAction: ( action: WebhookActions, id: number | string ) => void } ) {
	return (
		<WizardsActionCard
			isMedium
			className="newspack-webhooks__endpoint mt16"
			toggleChecked={ ! endpoint.disabled }
			toggleOnChange={ () => setAction( 'toggle', endpoint.id ) }
			key={ endpoint.id }
			title={ getEndpointTitle( endpoint ) }
			description={ () => {
				if ( endpoint.disabled && endpoint.disabled_error ) {
					return `${ __( 'Endpoint disabled due to error', 'newspack-plugin' ) }: ${ endpoint.disabled_error }`;
				}
				return (
					<Fragment>
						{ __( 'Actions:', 'newspack-plugin' ) }{ ' ' }
						{ endpoint.actions.map( action => (
							<span key={ action } className="newspack-webhooks__endpoint-action">
								{ action }
							</span>
						) ) }
					</Fragment>
				);
			} }
			actionText={ <EndpointActions endpoint={ endpoint } setAction={ setAction } isSystem={ endpoint.system } /> }
		/>
	);
}

export default EndpointActionsCard;
