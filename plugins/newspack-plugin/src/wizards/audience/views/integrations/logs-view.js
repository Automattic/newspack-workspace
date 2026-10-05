/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import { SyncActivity } from './sync-activity';
import { ScheduledActions } from './scheduled-actions';
import { hasSettingsToShow } from './settings-field';
import './style.scss';

const { Redirect } = Router;

const SCHEDULED_ACTIONS_TAB = 'scheduled-actions';

/**
 * The Logs tabs of an integration, for the wizard's tabbed navigation. Sync
 * Activity comes first, on the bare Logs route, because it answers what a
 * publisher comes here for: what was sent for a reader, and whether it arrived.
 * The scheduled actions are the machinery behind it.
 *
 * @param {Object} params               Route params.
 * @param {string} params.integrationId The integration ID.
 * @return {Array} Tabbed navigation items.
 */
export const getLogsTabs = ( { integrationId } ) => [
	{
		label: __( 'Sync Activity', 'newspack-plugin' ),
		path: `/settings/${ integrationId }/logs`,
		exact: true,
	},
	{
		label: __( 'Scheduled Actions', 'newspack-plugin' ),
		path: `/settings/${ integrationId }/logs/${ SCHEDULED_ACTIONS_TAB }`,
		exact: true,
	},
];

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
				? { label: integration.name, url: `#/settings/${ integrationId }` }
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
		return <Redirect to={ `/settings/${ integrationId }/logs` } />;
	}

	return SCHEDULED_ACTIONS_TAB === tab ? <ScheduledActions integrationId={ integrationId } /> : <SyncActivity integrationId={ integrationId } />;
};
