/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import Router from '../../../../../../packages/components/src/proxied-imports/router';
import { SyncActivity } from './sync-activity';
import { ScheduledActions } from './scheduled-actions';
import { hasSettingsToShow } from './settings-field';
import { INTEGRATIONS_PATH, SCHEDULED_ACTIONS_TAB } from './routes';
import './style.scss';

const { Redirect } = Router;

/**
 * The Logs page of an integration: the content of whichever tab the route names.
 *
 * @param {Object} props              Props.
 * @param {Object} props.integrations Integrations keyed by ID.
 * @param {Object} props.match        The router match, carrying `integrationId` and `tab`.
 */
export const LogsView = ( { integrations, match } ) => {
	const integrationId = match?.params?.integrationId;
	const tab = match?.params?.tab;
	const integration = integrationId ? integrations[ integrationId ] : null;
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		if ( integration ) {
			// The name links to the integration's settings page, unless it has none.
			const integrationCrumb = hasSettingsToShow( integration.settings )
				? { label: integration.name, url: `#${ INTEGRATIONS_PATH }/${ integrationId }` }
				: { label: integration.name };
			setHeaderData( {
				sectionName: [ integrationCrumb, { label: __( 'Logs', 'newspack-plugin' ) } ],
			} );
		}
	}, [ integration, integrationId, setHeaderData ] );

	if ( ! integrationId || ! integration ) {
		return null;
	}

	if ( tab && SCHEDULED_ACTIONS_TAB !== tab ) {
		return <Redirect to={ `${ INTEGRATIONS_PATH }/${ integrationId }/logs` } />;
	}

	return SCHEDULED_ACTIONS_TAB === tab ? <ScheduledActions integrationId={ integrationId } /> : <SyncActivity integrationId={ integrationId } />;
};
