/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const INTEGRATIONS_PATH = '/integrations';

export const SCHEDULED_ACTIONS_TAB = 'scheduled-actions';

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
		path: `${ INTEGRATIONS_PATH }/${ integrationId }/logs`,
		exact: true,
	},
	{
		label: __( 'Scheduled Actions', 'newspack-plugin' ),
		path: `${ INTEGRATIONS_PATH }/${ integrationId }/logs/${ SCHEDULED_ACTIONS_TAB }`,
		exact: true,
	},
];
