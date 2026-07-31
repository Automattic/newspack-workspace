import '../../shared/js/public-path';

/**
 * Subscribers — people-first subscriber management.
 *
 * Entry point: mounts a Wizard with the two L0 lists — the subscriber list and
 * the group list (both full-width) — plus the L1 person profile, which is routed
 * but hidden from the tabbed navigation because it is reached by clicking a
 * person, never by picking a tab.
 *
 * Detail routes are declared alongside the tabs rather than nested under them:
 * the wizard maps each section straight onto a react-router `<Route>`, so the
 * profile reads its id with the router's `useParams()`. The Subscribers tab
 * claims `/subscribers/:id` via `activeTabPaths`, so the profile renders inside
 * that tab's panel and the tab stays selected while it is open — rather than
 * relying on the wizard's no-tab-owns-this fallback. Both list sections are
 * `exact`, so neither swallows a detail path.
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
import PersonProfile from './screens/PersonProfile';
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
					// A person profile is a sub-view of this tab, so keep the tab
					// selected (and its panel owning the content) while one is open,
					// rather than falling through to the no-tab-owns-this branch.
					activeTabPaths: [ '/', '/subscribers/*' ],
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
					// One person, in full. Hidden from the tabs (it has no tab of its
					// own — it is reached by clicking a person) and rendered in the
					// standard content column rather than full-width: unlike the
					// lists, the profile is a two-column, form-style layout designed
					// to a readable measure.
					path: '/subscribers/:id',
					isHidden: true,
					render: PersonProfile,
				},
			] }
		/>
	);
}

render( <SubscribersApp />, document.getElementById( 'newspack-subscribers' ) );
