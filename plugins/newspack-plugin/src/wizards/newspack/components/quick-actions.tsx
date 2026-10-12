/**
 * Newspack - Dashboard, Quick Actions
 *
 * Quick Actions component provides editors quick access to content creation and viewing data relating to their site
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DashboardCard from './dashboard-card';
import { Grid, SectionHeader } from '../../../../packages/components/src';

const {
	newspackDashboard: { quickActions },
} = window;

const QuickActions = () => {
	return (
		<div className="newspack-dashboard__section">
			<SectionHeader heading={ 3 } title={ __( 'Quick actions', 'newspack-plugin' ) } />
			<Grid columns={ 3 } gutter={ 16 }>
				{ quickActions.map( ( action, i ) => (
					<DashboardCard key={ i } href={ action.href } icon={ action.icon } title={ action.title } />
				) ) }
			</Grid>
		</div>
	);
};

export default QuickActions;
