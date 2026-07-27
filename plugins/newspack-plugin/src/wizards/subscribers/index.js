import '../../shared/js/public-path';

/**
 * Subscribers — people-first subscriber management.
 *
 * Entry point: mounts a Wizard with the two L0 lists — the subscriber list and
 * the group list (both full-width). Row click-through currently opens the
 * native admin screens; the in-wizard person profile and group detail arrive in
 * later slices (NPPD-1753 PR 4/5).
 */

/**
 * WordPress dependencies.
 */
import { render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { Wizard } from '../../../packages/components/src';
import SubscriberList from './screens/SubscriberList';
import GroupList from './screens/GroupList';
import { GROUP_LABEL_PLURAL } from './labels';

function SubscribersApp() {
	return (
		<Wizard
			headerText={ __( 'Audience Management', 'newspack-plugin' ) }
			sections={ [
				{
					label: __( 'Subscribers', 'newspack-plugin' ),
					path: '/',
					exact: true,
					fullWidth: true,
					render: SubscriberList,
				},
				{
					label: GROUP_LABEL_PLURAL,
					path: '/groups',
					exact: true,
					fullWidth: true,
					render: GroupList,
				},
			] }
		/>
	);
}

render( <SubscribersApp />, document.getElementById( 'newspack-subscribers' ) );
