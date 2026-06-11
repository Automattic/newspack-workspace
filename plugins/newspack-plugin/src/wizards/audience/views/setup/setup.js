/* global newspackAudience */
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, ExternalLink, RangeControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	Grid,
	Notice,
	Divider,
	PluginInstaller,
	SectionHeader,
	TextControl,
	Waiting,
	withWizardScreen,
} from '../../../../../packages/components/src';
import WizardsTab from '../../../wizards-tab';
import Prerequisite from '../../components/prerequisite';
import Settings from '../../components/settings';
import MetadataFields from '../../components/metadata-fields';
import { HANDOFF_KEY } from '../../../../../packages/components/src/consts';
import SortableNewsletterListControl from '../../../../../packages/components/src/sortable-newsletter-list-control';
import Salesforce from '../../components/salesforce';

export default withWizardScreen(
	( {
		config,
		fetchConfig,
		updateConfig,
		getSharedProps,
		saveConfig,
		prerequisites,
		requiredPlugins,
		espSyncErrors,
		error,
		inFlight,
		platform,
		onChangePlatform,
	} ) => {
		const [ allReady, setAllReady ] = useState( false );
		const [ missingPlugins, setMissingPlugins ] = useState( [] );
		const [ esp, setEsp ] = useState( '' );

		useEffect( () => {
			window.scrollTo( 0, 0 );
			// Clear the handoff when the component mounts.
			window.localStorage.removeItem( HANDOFF_KEY );
		}, [] );

		useEffect( () => {
			apiFetch( {
				path: '/newspack/v1/wizard/newspack-newsletters/settings',
			} )
				.then( data => {
					setEsp( data?.settings?.newspack_newsletters_service_provider?.value ?? '' );
				} )
				// Newspack Newsletters may not be installed, in which case the endpoint 404s.
				.catch( () => setEsp( '' ) );
		}, [] );

		useEffect( () => {
			const missing = prerequisites
				? Object.keys( prerequisites ).reduce( ( acc, slug ) => {
						const prerequisite = prerequisites[ slug ];
						if ( prerequisite.plugins ) {
							for ( const pluginSlug in prerequisite.plugins ) {
								if ( ! prerequisite.plugins[ pluginSlug ] ) {
									acc.push( pluginSlug );
								}
							}
						}
						return acc;
				  }, [] )
				: [];

			// Surface the selected platform's required plugins that aren't installed yet,
			// so missing ones are presented here even if the chooser's install didn't finish.
			for ( const pluginSlug in requiredPlugins ) {
				if ( ! requiredPlugins[ pluginSlug ] && ! missing.includes( pluginSlug ) ) {
					missing.push( pluginSlug );
				}
			}

			setMissingPlugins( missing );
			// Derive readiness from the freshly-computed list, not the (stale) missingPlugins state.
			setAllReady( ! missing.length && prerequisites && Object.keys( prerequisites ).every( key => prerequisites[ key ]?.active ) );
		}, [ prerequisites, requiredPlugins ] );

		const hasNewsletters = Boolean( prerequisites?.esp?.plugins?.[ 'newspack-newsletters' ] );

		return (
			<WizardsTab
				title={ __( 'Audience Management', 'newspack-plugin' ) }
				description={
					<>
						{ __(
							"Newspack's Audience Management system is a set of features that aim to increase reader loyalty, promote engagement, and drive revenue. ",
							'newspack-plugin'
						) }
						<ExternalLink href={ 'https://help.newspack.com/engagement/audience-management-system' }>
							{ __( 'Learn more', 'newspack-plugin' ) }
						</ExternalLink>
					</>
				}
			>
				{ error && <Notice noticeText={ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) } isError /> }
				{ onChangePlatform && (
					<ActionCard
						isMedium
						title={ __( 'Reader Revenue Platform', 'newspack-plugin' ) }
						description={ ( () => {
							const labels = {
								wc: __( 'Newspack', 'newspack-plugin' ),
								nrh: __( 'RevEngine', 'newspack-plugin' ),
								other: __( 'Other', 'newspack-plugin' ),
							};
							return labels[ platform ] || __( 'Not set', 'newspack-plugin' );
						} )() }
						actionText={ __( 'Change', 'newspack-plugin' ) }
						onClick={ onChangePlatform }
					/>
				) }
				{ 0 < missingPlugins.length && (
					<Notice
						noticeText={ __( 'The following plugins are recommended for full Audience Management functionality.', 'newspack-plugin' ) }
						isWarning
					/>
				) }
				{ 0 === missingPlugins.length && prerequisites && ! allReady && (
					<Notice noticeText={ __( 'Some recommended settings are not yet configured.', 'newspack-plugin' ) } isWarning />
				) }
				{ ! prerequisites && (
					<>
						<Waiting isLeft />
						{ __( 'Fetching status…', 'newspack-plugin' ) }
					</>
				) }
				{ 0 < missingPlugins.length && prerequisites && (
					<PluginInstaller plugins={ missingPlugins } withoutFooterButton onStatus={ ( { complete } ) => complete && fetchConfig() } />
				) }
				{ prerequisites &&
					Object.keys( prerequisites ).map( key => (
						<Prerequisite
							key={ key }
							config={ config }
							getSharedProps={ getSharedProps }
							inFlight={ inFlight }
							prerequisite={ prerequisites[ key ] }
							fetchConfig={ fetchConfig }
							saveConfig={ saveConfig }
						/>
					) ) }
				{ config.enabled && (
					<Card noBorder>
						<Divider alignment="full-width" variant="tertiary" />
						{ hasNewsletters && (
							<ActionCard
								title={ __( 'Present newsletter signup after checkout and registration', 'newspack-plugin' ) }
								description={ __(
									'Ask readers to sign up for newsletters after creating an account or completing a purchase.',
									'newspack-plugin'
								) }
								hasGreyHeader={ config.use_custom_lists }
								isMedium
								toggleChecked={ config.use_custom_lists }
								toggleOnChange={ value => updateConfig( 'use_custom_lists', value ) }
							>
								{ config.use_custom_lists && (
									<Grid columns={ 4 }>
										<SortableNewsletterListControl
											lists={ newspackAudience.available_newsletter_lists }
											selected={ config.newsletter_lists }
											onChange={ selected => updateConfig( 'newsletter_lists', selected ) }
										/>
										<RangeControl
											min={ 1 }
											max={ 10 }
											initialPosition={ 2 }
											label={ __( 'Initial list size', 'newspack-plugin' ) }
											help={ __(
												'Number of newsletters initially visible during signup. Additional newsletters will be hidden behind a "See all" button.',
												'newspack-plugin'
											) }
											value={ config.newsletter_list_initial_size || '' }
											onChange={ value => updateConfig( 'newsletter_list_initial_size', parseInt( value ) ) }
										/>
									</Grid>
								) }
							</ActionCard>
						) }

						<ActionCard
							title={ __( 'Use My Account login screen for OAuth clients', 'newspack-plugin' ) }
							description={ __(
								'Allow OAuth clients to redirect to the My Account login screen instead of the default WordPress login screen. Might require additional configuration.',
								'newspack-plugin'
							) }
							isMedium
							toggleChecked={ config.oauth_redirect_to_ras }
							toggleOnChange={ value => updateConfig( 'oauth_redirect_to_ras', value ) }
						/>

						{ hasNewsletters && (
							<>
								<Divider alignment="full-width" variant="tertiary" />

								<SectionHeader
									title={ __( 'Email Service Provider (ESP) Advanced Settings', 'newspack-plugin' ) }
									description={ __( 'Settings for Newspack Newsletters integration.', 'newspack-plugin' ) }
								/>
								<TextControl
									label={ __( 'Newsletter subscription text on registration', 'newspack-plugin' ) }
									help={ __( 'The text to display while subscribing to newsletters from the sign-in modal.', 'newspack-plugin' ) }
									{ ...getSharedProps( 'newsletters_label', 'text' ) }
								/>
								{ ! newspackAudience.integrations_settings_enabled && (
									<ActionCard
										description={ __( 'Configure options for syncing reader data to the connected ESP.', 'newspack-plugin' ) }
										hasGreyHeader={ config.sync_esp }
										isMedium
										title={ __( 'Sync contacts to ESP', 'newspack-plugin' ) }
										toggleChecked={ config.sync_esp }
										toggleOnChange={ value => updateConfig( 'sync_esp', value ) }
									>
										{ config.sync_esp && (
											<>
												{ 0 < Object.keys( espSyncErrors ).length && (
													<Notice noticeText={ Object.values( espSyncErrors ).join( ' ' ) } isError />
												) }
												{ esp === 'mailchimp' && (
													<Settings
														title={ 'Mailchimp' }
														value={ {
															audienceId: config.mailchimp_audience_id,
															readerDefaultStatus: config.mailchimp_reader_default_status,
														} }
														onChange={ ( key, value ) => {
															if ( key === 'audienceId' ) {
																updateConfig( 'mailchimp_audience_id', value );
															}
															if ( key === 'readerDefaultStatus' ) {
																updateConfig( 'mailchimp_reader_default_status', value );
															}
														} }
													/>
												) }
												{ esp === 'active_campaign' && (
													<Settings
														title={ 'ActiveCampaign' }
														value={ {
															masterList: config.active_campaign_master_list,
														} }
														onChange={ ( key, value ) => {
															if ( key === 'masterList' ) {
																updateConfig( 'active_campaign_master_list', value );
															}
														} }
													/>
												) }
												{ esp === 'constant_contact' && (
													<Settings
														title={ 'Constant Contact' }
														value={ { masterList: config.constant_contact_list_id } }
														onChange={ ( key, value ) => {
															if ( key === 'masterList' ) {
																updateConfig( 'constant_contact_list_id', value );
															}
														} }
													/>
												) }

												<SectionHeader
													title={ __( 'Sync user account deletion', 'newspack-plugin' ) }
													description={ __(
														'If enabled, the contact will be deleted from the ESP when a user account is deleted. If disabled, the contact will be unsubscribed from all lists, but not deleted.',
														'newspack-plugin'
													) }
												/>
												<CheckboxControl
													label={ __( 'Sync user account deletion', 'newspack-plugin' ) }
													checked={ config.sync_esp_delete }
													onChange={ value => updateConfig( 'sync_esp_delete', value ) }
												/>
												<MetadataFields
													availableFields={ newspackAudience.esp_metadata_fields || [] }
													selectedFields={ config.metadata_fields }
													updateConfig={ updateConfig }
													getSharedProps={ getSharedProps }
												/>
											</>
										) }
									</ActionCard>
								) }
							</>
						) }
						<div className="newspack-buttons-card">
							<Button
								isPrimary
								onClick={ () => {
									// When the Integrations settings area is enabled, ESP sync configuration
									// lives there — skip the validation here so users can't get stuck on an
									// alert for a section they can no longer see.
									if ( ! newspackAudience.integrations_settings_enabled && config.sync_esp ) {
										if ( esp === 'mailchimp' && config.mailchimp_audience_id === '' ) {
											// eslint-disable-next-line no-alert
											alert( __( 'Please select a Mailchimp Audience ID.', 'newspack-plugin' ) );
											return;
										}
										if ( esp === 'active_campaign' && config.active_campaign_master_list === '' ) {
											// eslint-disable-next-line no-alert
											alert( __( 'Please select an ActiveCampaign Master List.', 'newspack-plugin' ) );
											return;
										}
										if ( esp === 'constant_contact' && config.constant_contact_list_id === '' ) {
											// eslint-disable-next-line no-alert
											alert( __( 'Please select a Constant Contact Master List.', 'newspack-plugin' ) );
											return;
										}
									}
									saveConfig( {
										newsletters_label: config.newsletters_label, // TODO: Deprecate this in favor of user input via the prompt copy wizard.
										mailchimp_audience_id: config.mailchimp_audience_id,
										mailchimp_reader_default_status: config.mailchimp_reader_default_status,
										active_campaign_master_list: config.active_campaign_master_list,
										constant_contact_list_id: config.constant_contact_list_id,
										use_custom_lists: config.use_custom_lists,
										newsletter_lists: config.newsletter_lists,
										newsletter_list_initial_size: config.newsletter_list_initial_size,
										oauth_redirect_to_ras: config.oauth_redirect_to_ras,
										sync_esp: config.sync_esp,
										sync_esp_delete: config.sync_esp_delete,
										metadata_fields: config.metadata_fields,
										metadata_prefix: config.metadata_prefix,
										woocommerce_registration_required: config.woocommerce_registration_required,
										woocommerce_checkout_privacy_policy_text: config.woocommerce_checkout_privacy_policy_text,
										woocommerce_enable_subscription_confirmation: config.woocommerce_enable_subscription_confirmation,
										woocommerce_subscription_confirmation_text: config.woocommerce_subscription_confirmation_text,
										woocommerce_enable_terms_confirmation: config.woocommerce_enable_terms_confirmation,
										woocommerce_terms_confirmation_text: config.woocommerce_terms_confirmation_text,
										woocommerce_terms_confirmation_url: config.woocommerce_terms_confirmation_url,
										woocommerce_post_checkout_success_text: config.woocommerce_post_checkout_success_text,
										woocommerce_post_checkout_registration_success_text:
											config.woocommerce_post_checkout_registration_success_text,
									} );
								} }
								disabled={ inFlight }
							>
								{ __( 'Save Settings', 'newspack-plugin' ) }
							</Button>
						</div>
					</Card>
				) }
				{ newspackAudience.can_use_salesforce && (
					<Card noBorder>
						<Divider alignment="full-width" variant="tertiary" />
						<Salesforce />
					</Card>
				) }
			</WizardsTab>
		);
	}
);
