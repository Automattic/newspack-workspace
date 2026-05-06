/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Icon, envelope } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Card, CardFeature, Grid } from '../../../../../packages/components/src';
import colors from '../../../../../packages/colors/colors.module.scss';
import WizardsTab from '../../../wizards-tab';
import WizardSection from '../../../wizards-section';

/**
 * Icon configuration per integration ID.
 * Only ESP exists today. When new integrations are added (DSGNEWS-157),
 * this moves to the PHP API response.
 */
const INTEGRATION_ICONS = {
	esp: {
		node: <Icon icon={ envelope } />,
		fill: colors[ 'primary-600' ],
		backgroundColor: colors[ 'primary-000' ],
		radius: 'full',
	},
};

const DEFAULT_ICON = {
	node: <Icon icon={ envelope } />,
	fill: colors[ 'neutral-600' ],
	backgroundColor: colors[ 'neutral-100' ],
};

export const SettingsSection = ( { integrations, loading, onToggleEnabled, history } ) => {
	const integrationIds = Object.keys( integrations );

	return (
		<WizardsTab
			className="newspack-audience-integrations"
			title={ __( 'Integrations', 'newspack-plugin' ) }
			description={ __(
				'Manage how Newspack syncs reader data with your tools. Connect an integration to start syncing reader activity across your stack.',
				'newspack-plugin'
			) }
		>
			<WizardSection>
				{ loading && <p>{ __( 'Loading…', 'newspack-plugin' ) }</p> }
				{ ! loading && integrationIds.length === 0 && (
					<Card>
						<p>{ __( 'No integrations with configurable settings are registered.', 'newspack-plugin' ) }</p>
					</Card>
				) }
				{ ! loading && integrationIds.length > 0 && (
					<Grid columns={ 2 } gutter={ 16 }>
						{ integrationIds.map( id => {
							const { enabled, is_set_up: isSetUp, setup_url, name, description } = integrations[ id ];
							const isEnabled = enabled;
							const needsSetup = ! isSetUp && !! setup_url;
							const goToSetup = () => {
								window.location.href = setup_url;
							};
							return (
								<CardFeature
									key={ id }
									title={ name }
									description={ description }
									icon={ INTEGRATION_ICONS[ id ] || DEFAULT_ICON }
									enabled={ isEnabled }
									enableLabel={ isSetUp ? __( 'Enable', 'newspack-plugin' ) : __( 'Connect', 'newspack-plugin' ) }
									configureLabel={ needsSetup ? __( 'Configure', 'newspack-plugin' ) : undefined }
									onEnable={ needsSetup ? goToSetup : () => onToggleEnabled( id, true ) }
									onConfigure={ needsSetup ? goToSetup : () => history?.push( `/settings/${ id }` ) }
									moreControls={
										isEnabled
											? [
													{
														title: __( 'Logs', 'newspack-plugin' ),
														onClick: () => history?.push( `/settings/${ id }/logs` ),
													},
													{
														title: __( 'Disable', 'newspack-plugin' ),
														onClick: () => onToggleEnabled( id, false ),
													},
											  ]
											: undefined
									}
								/>
							);
						} ) }
					</Grid>
				) }
			</WizardSection>
		</WizardsTab>
	);
};
