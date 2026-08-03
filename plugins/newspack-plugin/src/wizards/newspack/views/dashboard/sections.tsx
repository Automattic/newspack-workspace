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
import { Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
/* eslint import/namespace: ['error', { allowComputed: true }] */
import { icons } from '../../components/icons';
import { Card, Divider, Grid } from '../../../../../packages/components/src';

const {
	newspackDashboard: { sections: dashSections },
} = window;

function getIcon( iconName: keyof typeof icons ) {
	if ( iconName in icons ) {
		return icons[ iconName ];
	}
	return icons.help;
}

export default [
	{
		label: __( 'Dashboard', 'newspack' ),
		path: '/',
		render: () => {
			const dashSectionsKeys = Object.keys( dashSections );
			return dashSectionsKeys.map( sectionKey => {
				return (
					<Fragment key={ sectionKey }>
						<Divider variant="tertiary" />
						<div className="newspack-dashboard__section">
							<h3>{ dashSections[ sectionKey ].title }</h3>
							<p>{ dashSections[ sectionKey ].desc }</p>
							<Grid columns={ 3 } gutter={ 24 } key={ `${ sectionKey }-grid` }>
								{ dashSections[ sectionKey ].cards.map( ( sectionCard, i ) => {
									return (
										<a href={ sectionCard.href } key={ `${ sectionKey }-card-${ i }` }>
											<Card className="newspack-dashboard__card">
												<div className="newspack-dashboard__card-icon">
													<Icon size={ 32 } icon={ getIcon( sectionCard.icon ) } />
												</div>
												<div className="newspack-dashboard__card-text">
													<h4>{ sectionCard.title }</h4>
													<p>{ sectionCard.desc }</p>
												</div>
											</Card>
										</a>
									);
								} ) }
							</Grid>
						</div>
					</Fragment>
				);
			} );
		},
	},
];
