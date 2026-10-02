/**
 * Newspack - Dashboard, Sections
 *
 * Component for outputting sections with grid and cards
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import DashboardCard from '../../components/dashboard-card';
import { Divider, Grid, SectionHeader } from '../../../../../packages/components/src';

const {
	newspackDashboard: { sections: dashSections },
} = window;

export default [
	{
		label: __( 'Dashboard', 'newspack' ),
		path: '/',
		breadcrumbs: [ { label: __( 'Dashboard', 'newspack' ) } ],
		render: () => {
			const dashSectionsKeys = Object.keys( dashSections );
			return dashSectionsKeys.map( sectionKey => {
				return (
					<Fragment key={ sectionKey }>
						<Divider variant="tertiary" />
						<div className="newspack-dashboard__section">
							<SectionHeader heading={ 3 } title={ dashSections[ sectionKey ].title } description={ dashSections[ sectionKey ].desc } />
							<Grid columns={ 3 } gutter={ 16 } key={ `${ sectionKey }-grid` }>
								{ dashSections[ sectionKey ].cards.map( ( sectionCard, i ) => (
									<DashboardCard
										key={ `${ sectionKey }-card-${ i }` }
										href={ sectionCard.href }
										icon={ sectionCard.icon }
										title={ sectionCard.title }
										description={ sectionCard.desc }
									/>
								) ) }
							</Grid>
						</div>
					</Fragment>
				);
			} );
		},
	},
];
