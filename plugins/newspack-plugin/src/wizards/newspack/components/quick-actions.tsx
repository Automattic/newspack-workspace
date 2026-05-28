/**
 * Newspack - Dashboard, Quick Actions
 *
 * Quick Actions component provides editors quick access to content creation and viewing data relating to their site
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Card, Grid } from '../../../../packages/components/src';
import { icons } from './icons';

const {
	newspackDashboard: { quickActions },
} = window;

const QuickActions = () => {
	return (
		<div className="newspack-dashboard__section">
			<h3>{ __( 'Quick actions', 'newspack-plugin' ) }</h3>
			<Grid style={ { '--np-dash-card-icon-size': '40px' } } columns={ 3 } gutter={ 24 }>
				{ quickActions.map( ( action, i ) => {
					return (
						<a href={ action.href } key={ i }>
							<Card className="newspack-dashboard__card">
								<div className="newspack-dashboard__card-icon">
									<Icon icon={ icons[ action.icon ] } />
								</div>
								<h4>{ action.title }</h4>
							</Card>
						</a>
					);
				} ) }
			</Grid>
		</div>
	);
};

export default QuickActions;
