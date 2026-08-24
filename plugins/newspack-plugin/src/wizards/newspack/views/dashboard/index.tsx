/**
 * Newspack - Dashboard
 *
 * WP Admin Newspack Dashboard page.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import sections from './sections';
import BrandHeader from '../../components/brand-header';
import QuickActions from '../../components/quick-actions';
import SiteStatuses from '../../components/site-statuses';
import { Divider, Wizard } from '../../../../../packages/components/src';

function Dashboard() {
	return (
		<Wizard
			headerText={ __( 'Newspack / Dashboard', 'newspack' ) }
			sections={ sections }
			renderAboveSections={ () => (
				<>
					<BrandHeader />
					<SiteStatuses />
					<Divider variant="tertiary" />
					<QuickActions />
				</>
			) }
		/>
	);
}

export default Dashboard;
