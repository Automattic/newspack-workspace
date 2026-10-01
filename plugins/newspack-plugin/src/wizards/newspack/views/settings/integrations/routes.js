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

/**
 * Add the Logs screen to the Settings sections, ahead of the Integrations
 * section that would otherwise match its route first. It is a section of its
 * own because it swaps the Settings tabs for the Logs tabs.
 *
 * @param {Array} sections Settings sections.
 * @return {Array} The sections, with the Logs section added when Integrations is there.
 */
export const withLogsSection = sections => {
	const index = sections.findIndex( section => section.path === INTEGRATIONS_PATH );
	if ( index === -1 ) {
		return sections;
	}
	const logsSection = {
		...sections[ index ],
		path: `${ INTEGRATIONS_PATH }/:integrationId/logs/:tab?`,
		exact: false,
		activeTabPaths: undefined,
		isHidden: true,
		fullWidth: true,
		tabbedNavigation: getLogsTabs,
	};
	return [ ...sections.slice( 0, index ), logsSection, ...sections.slice( index ) ];
};
