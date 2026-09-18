/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { Tabs } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { SyncActivity } from './sync-activity';
import { ScheduledActions } from './scheduled-actions';
import './style.scss';

const TABS = [
	{ value: 'sync-activity', label: __( 'Sync activity', 'newspack-plugin' ), Content: SyncActivity },
	{ value: 'scheduled-actions', label: __( 'Scheduled actions', 'newspack-plugin' ), Content: ScheduledActions },
];

/**
 * The Logs page of an integration. Sync activity comes first because it
 * answers what a publisher comes here for: what was sent for a reader, and
 * whether it arrived. The scheduled actions are the machinery behind it.
 *
 * @param {Object} props              Props.
 * @param {Object} props.integrations Integrations keyed by ID.
 * @param {Object} props.match        The router match, carrying `integrationId`.
 */
export const LogsView = ( { integrations, match } ) => {
	const integrationId = match?.params?.integrationId;
	const integration = integrationId ? integrations[ integrationId ] : null;
	const { setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ activeTab, setActiveTab ] = useState( TABS[ 0 ].value );

	useEffect( () => {
		if ( integration ) {
			setHeaderData( {
				sectionName: [ { label: integration.name, url: `#/settings/${ integrationId }` }, { label: __( 'Logs', 'newspack-plugin' ) } ],
				actions: [
					{
						type: 'secondary',
						label: __( 'Back to Integrations', 'newspack-plugin' ),
						icon: 'chevronLeft',
						href: '#/settings',
					},
				],
			} );
		}
	}, [ integration, integrationId, setHeaderData ] );

	if ( ! integrationId || ! integration ) {
		return null;
	}

	return (
		<Tabs.Root value={ activeTab } onValueChange={ setActiveTab } className="newspack-integration-logs-tabs">
			<Tabs.List>
				{ TABS.map( ( { value, label } ) => (
					<Tabs.Tab key={ value } value={ value }>
						{ label }
					</Tabs.Tab>
				) ) }
			</Tabs.List>
			{ TABS.map( ( { value, Content } ) => (
				<Tabs.Panel key={ value } value={ value } className="newspack-integration-logs-tabs__panel">
					{ /* Only the open tab fetches: each list loads on mount. */ }
					{ value === activeTab ? <Content integrationId={ integrationId } /> : null }
				</Tabs.Panel>
			) ) }
		</Tabs.Root>
	);
};
