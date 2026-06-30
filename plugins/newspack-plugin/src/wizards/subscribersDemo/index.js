import '../../shared/js/public-path';

/**
 * Subscribers Demo — people-first subscriber management prototype.
 *
 * Entry point: mounts a Wizard with two routed sections — the DataViews
 * list (full-width) and the person profile.
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
import GroupDetail from './screens/GroupDetail';
import PersonProfile from './screens/PersonProfile';
import { GROUP_LABEL_PLURAL } from './labels';
import { purgeStaleStorage } from './data/storage';

function SubscribersDemoApp() {
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
				{
					path: '/group/:id',
					render: GroupDetail,
					isHidden: true,
				},
				{
					path: '/profile/:id',
					render: PersonProfile,
					isHidden: true,
				},
			] }
		/>
	);
}

purgeStaleStorage();

render( <SubscribersDemoApp />, document.getElementById( 'newspack-subscribers-demo' ) );
