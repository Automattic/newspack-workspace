/**
 * Newspack - Dashboard
 *
 * WP Admin Newspack Dashboard page.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';
import sections from './sections';
import QuickActions from '../../components/quick-actions';
import SiteStatuses from '../../components/site-statuses';
import { Divider, GlobalNotices, Wizard } from '../../../../../packages/components/src';

function Dashboard() {
	return (
		<Fragment>
			<GlobalNotices />
			<Wizard
				headerText={ __( 'Newspack / Dashboard', 'newspack' ) }
				sections={ sections }
				renderAboveSections={ () => (
					<>
						<SiteStatuses />
						<Divider variant="tertiary" />
						<QuickActions />
					</>
				) }
			/>
		</Fragment>
	);
}

export default Dashboard;
