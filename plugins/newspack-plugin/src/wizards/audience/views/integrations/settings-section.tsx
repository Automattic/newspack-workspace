/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, envelope } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Card, CardFeature, Grid, IntegrationIcon, espProviderOrder } from '../../../../../packages/components/src';
import colors from '../../../../../packages/colors/colors.module.scss';
import WizardsTab from '../../../wizards-tab';
import WizardSection from '../../../wizards-section';
import { EnableModal, getMissingRequiredFields } from './enable-modal';
import { IntegrationGuide } from './guide';
import { hasSettingsToShow } from './settings-field';
import type { IntegrationConfig, IntegrationsSettings, IntegrationRequiredPlugin, IntegrationFieldValue } from './constants';

type RouterHistory = { push: ( path: string ) => void };

type SettingsSectionProps = {
	integrations: IntegrationsSettings;
	loading: boolean;
	activating?: Record< string, boolean >;
	toggling?: Record< string, boolean >;
	onToggleEnabled: ( id: string, enabled: boolean ) => void;
	onActivatePlugin: ( slugs: string | string[] ) => void;
	onSetupAndEnable: ( id: string, settings: Record< string, IntegrationFieldValue > ) => Promise< unknown >;
	history?: RouterHistory;
};

/**
 * Fallback for integrations with no brand mark or icon of their own.
 */
const DEFAULT_ICON = {
	node: <Icon icon={ envelope } />,
	fill: colors[ 'neutral-600' ],
	backgroundColor: colors[ 'neutral-100' ],
};

const BRANDED_INTEGRATION_IDS = [ 'beehiiv', 'fundraiseup', 'salesforce' ];

/**
 * Integration IDs ordered by display name, so the grid reads alphabetically
 * whatever order the integrations were registered in.
 *
 * @param integrations Integrations keyed by ID.
 * @return Sorted integration IDs.
 */
export const sortIntegrationIds = ( integrations: IntegrationsSettings ): string[] =>
	Object.keys( integrations ).sort( ( a, b ) =>
		( integrations[ a ].name || a ).localeCompare( integrations[ b ].name || b, undefined, { sensitivity: 'base' } )
	);

const getMissingPlugins = ( integration: IntegrationConfig ): IntegrationRequiredPlugin[] =>
	( integration.required_plugins || [] ).filter( plugin => ! plugin.is_active );

export const SettingsSection = ( {
	integrations,
	loading,
	activating = {},
	toggling = {},
	onToggleEnabled,
	onActivatePlugin,
	onSetupAndEnable,
	history,
}: SettingsSectionProps ) => {
	const integrationIds = sortIntegrationIds( integrations );
	const [ enablingId, setEnablingId ] = useState< string | null >( null );
	const [ guideId, setGuideId ] = useState< string | null >( null );

	return (
		<WizardsTab className="newspack-audience-integrations">
			<WizardSection>
				{ loading && <p>{ __( 'Loading…', 'newspack-plugin' ) }</p> }
				{ ! loading && integrationIds.length === 0 && (
					<Card>
						<p>{ __( 'No integrations with configurable settings are registered.', 'newspack-plugin' ) }</p>
					</Card>
				) }
				{ ! loading && integrationIds.length > 0 && (
					<>
						<Grid columns={ 2 } noMargin>
							{ integrationIds.map( id => {
								const integration = integrations[ id ];
								const {
									enabled,
									is_connected: isConnected = true,
									unsupported_reason: unsupportedReason,
									unsupported_action_label: unsupportedActionLabel,
									provider,
									setup_url,
									name,
									description,
								} = integration;
								// The esp integration is Mailchimp-only, so its card always
								// carries the Mailchimp mark — a stray provider value must not
								// rebrand it.
								let cardIcon: React.ComponentProps< typeof CardFeature >[ 'icon' ] = DEFAULT_ICON;
								if ( id === 'esp' ) {
									cardIcon = <IntegrationIcon provider="mailchimp" />;
								} else if ( BRANDED_INTEGRATION_IDS.includes( id ) ) {
									cardIcon = <IntegrationIcon provider={ id } />;
								} else if ( id === 'gravity-forms' ) {
									cardIcon = <IntegrationIcon provider="gravity_forms" />;
								} else if ( provider && espProviderOrder.includes( provider ) ) {
									cardIcon = <IntegrationIcon provider={ provider } />;
								}
								const missingPlugins = getMissingPlugins( integration );
								const requiresInstallPlugins = missingPlugins.filter( plugin => ! plugin.is_installed );
								// Only offer Activate when every missing plugin is at least installed;
								// otherwise the card stays in the disabled "Requires …" state until the
								// uninstalled plugin is installed first.
								const activatablePlugins = requiresInstallPlugins.length === 0 ? missingPlugins : [];
								const canActivate = activatablePlugins.length > 0;
								const isActivating = canActivate && activatablePlugins.some( plugin => activating[ plugin.slug ] );
								// eslint-disable-next-line no-nested-ternary
								const requirements = unsupportedReason
									? unsupportedReason
									: missingPlugins.length
									? sprintf(
											/* translators: %s: comma-separated list of required plugin names. */
											__( 'Requires %s', 'newspack-plugin' ),
											missingPlugins.map( plugin => plugin.name ).join( ', ' )
									  )
									: undefined;
								const isEnabled = enabled;
								// "Connect" only while the external service itself is not connected. Once it
								// is, Enable either opens a modal collecting the missing required settings
								// (e.g. the ESP master list) or toggles the integration on directly.
								const needsConnection = ! isConnected && !! setup_url;
								// An unsupported integration is connected — to something that cannot do the
								// job — so it never gets "Connect". This only gates whether the remedy is
								// actionable (somewhere to send the user); the label itself always follows
								// unsupportedReason, see below.
								const canFixUnsupported = !! unsupportedReason && !! setup_url;
								const goToSetup = () => {
									apiFetch< { HandoffLink: string } >( {
										path: '/newspack/v1/handoff',
										method: 'POST',
										data: {
											destinationUrl: setup_url,
											handoffReturnUrl: window.location.href,
											bannerText: __( 'Return to Integrations after completing configuration', 'newspack-plugin' ),
											bannerButtonText: __( 'Back to Integrations', 'newspack-plugin' ),
										},
									} )
										.then( response => {
											window.location.href = response.HandoffLink;
										} )
										// The handoff banner is an enhancement; if registering it fails,
										// still take the user to the setup screen.
										.catch( () => {
											window.location.href = setup_url as string;
										} );
								};
								let onConfigure: ( () => void ) | undefined;
								if ( needsConnection ) {
									onConfigure = goToSetup;
								} else if ( hasSettingsToShow( integration.settings ) ) {
									onConfigure = () => history?.push( `/settings/${ id }` );
								}
								const hasGuide = Array.isArray( integration.guide ) && integration.guide.length > 0;
								let enableLabel: string = __( 'Enable', 'newspack-plugin' );
								let onEnable = () => {
									if ( getMissingRequiredFields( integration ).length ) {
										setEnablingId( id );
									} else {
										onToggleEnabled( id, true );
									}
								};
								if ( unsupportedReason ) {
									enableLabel = unsupportedActionLabel || __( 'Open settings', 'newspack-plugin' );
									if ( canFixUnsupported ) {
										onEnable = goToSetup;
									}
								} else if ( needsConnection ) {
									enableLabel = __( 'Connect', 'newspack-plugin' );
									onEnable = goToSetup;
								}
								if ( canActivate ) {
									enableLabel = isActivating ? __( 'Activating…', 'newspack-plugin' ) : __( 'Activate', 'newspack-plugin' );
									onEnable = () => onActivatePlugin( activatablePlugins.map( plugin => plugin.slug ) );
								}
								return (
									<CardFeature
										headingLevel={ 2 }
										key={ id }
										title={ name as string }
										description={ description }
										icon={ cardIcon }
										enabled={ isEnabled }
										requirements={ requirements }
										requirementsActionable={ canActivate || canFixUnsupported }
										enableLabel={ enableLabel }
										busy={ isActivating || !! toggling[ id ] }
										onEnable={ onEnable }
										onConfigure={ onConfigure }
										moreControls={
											isEnabled
												? [
														...( hasGuide
															? [
																	{
																		title: __( 'How it works', 'newspack-plugin' ),
																		onClick: () => setGuideId( id ),
																	},
															  ]
															: [] ),
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
						{ enablingId && integrations[ enablingId ] && (
							<EnableModal
								integration={ integrations[ enablingId ] }
								onClose={ () => setEnablingId( null ) }
								onEnable={ settings => onSetupAndEnable( enablingId, settings ).then( () => setEnablingId( null ) ) }
								onGoToSettings={ () => {
									setEnablingId( null );
									history?.push( `/settings/${ enablingId }` );
								} }
							/>
						) }
						{ guideId && integrations[ guideId ] && (
							<IntegrationGuide integration={ integrations[ guideId ] } onClose={ () => setGuideId( null ) } />
						) }
					</>
				) }
			</WizardSection>
		</WizardsTab>
	);
};
