import '../../../../shared/js/public-path';

/**
 * Campaigns Wizard
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Component } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * External dependencies.
 */
import { stringify } from 'qs';
import type { RouteComponentProps } from 'react-router-dom';

/**
 * Internal dependencies.
 */
import { WebPreview, withWizard } from '../../../../../packages/components/src';
import type { WithWizardInjectedProps } from '../../../../../packages/components/src/with-wizard';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import { ABTests, Campaigns, ContextualPrompts, Settings, Segments } from './views';
import AddCampaignAction from './campaigns/add-campaign-action';
import CampaignActions from './campaigns/campaign-actions';
import ABTestsContext from './ab-tests/context';
import { REST_BASE as AB_TESTS_REST_BASE, latestOnly } from './ab-tests/utils';
import { CampaignsContext } from '../../contexts';

const { HashRouter, Redirect, Route, Switch } = Router;

const headerText = __( 'Audience Management / Campaigns', 'newspack-plugin' );

const ROOT = [ { label: __( 'Audience Management', 'newspack-plugin' ) } ];

// The Campaigns tab as a linked ancestor crumb for its sub-tabs.
const CAMPAIGNS_CRUMB = { label: __( 'Campaigns', 'newspack-plugin' ), url: '#/campaigns' };

const contextualPromptsEnabled = Boolean( window.newspackAudienceCampaigns?.contextual_prompts_enabled );
const abTestingEnabled = Boolean( window.newspackAudienceCampaigns?.ab_testing_enabled );

const tabbedNavigation = [
	{
		label: __( 'Campaigns', 'newspack-plugin' ),
		path: '/campaigns',
		exact: false,
		breadcrumbs: [ ...ROOT, { label: __( 'Campaigns', 'newspack-plugin' ) } ],
	},
	{
		label: __( 'Segments', 'newspack-plugin' ),
		path: '/segments',
		exact: false,
		breadcrumbs: [ ...ROOT, CAMPAIGNS_CRUMB, { label: __( 'Segments', 'newspack-plugin' ) } ],
	},
	...( contextualPromptsEnabled
		? [
				{
					label: __( 'Contextual Prompts', 'newspack-plugin' ),
					path: '/contextual-prompts',
					exact: true,
					breadcrumbs: [ ...ROOT, CAMPAIGNS_CRUMB, { label: __( 'Contextual Prompts', 'newspack-plugin' ) } ],
				},
		  ]
		: [] ),
	...( abTestingEnabled
		? [
				{
					label: __( 'A/B Tests', 'newspack-plugin' ),
					path: '/ab-tests',
					exact: true,
					breadcrumbs: [ ...ROOT, CAMPAIGNS_CRUMB, { label: __( 'A/B Tests', 'newspack-plugin' ) } ],
				},
		  ]
		: [] ),
	{
		label: __( 'Settings', 'newspack-plugin' ),
		path: '/settings',
		exact: true,
		breadcrumbs: [ ...ROOT, CAMPAIGNS_CRUMB, { label: __( 'Settings', 'newspack-plugin' ) } ],
	},
];

type AudienceCampaignsProps = WithWizardInjectedProps;

type AudienceCampaignsState = {
	campaigns: CampaignGroup[];
	prompts: CampaignsPrompt[];
	segments: CampaignsSegment[];
	settings: Record< string, unknown >[];
	previewUrl: string | null;
	previewTitle: string | null;
	duplicated: number | null;
	inFlight: boolean;
	abTests: ABTestsData | null;
};

class AudienceCampaigns extends Component< AudienceCampaignsProps, AudienceCampaignsState > {
	constructor( props: AudienceCampaignsProps ) {
		super( props );
		this.state = {
			campaigns: [],
			prompts: [],
			segments: [],
			settings: [],
			previewUrl: null,
			previewTitle: null,
			duplicated: null,
			inFlight: false,
			abTests: null,
		};
	}
	onWizardReady = () => {
		this.refetch();
	};

	// Quiet keeps the page on screen while it reloads, and with it whatever has
	// focus; a plain refetch hides the page until the response arrives.
	refetch = ( { quiet = false }: { quiet?: boolean } = {} ) => {
		const { setError, wizardApiFetch } = this.props;
		wizardApiFetch( {
			path: window.newspackAudienceCampaigns.api,
			quiet,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	// Rejects on a refused save rather than reporting it here: the settings
	// drawer, its only caller, stays open and shows the error itself, since the
	// wizard's own notice sits behind the drawer where nobody can see it.
	updatePopup = ( { id, ...promptConfig }: CampaignsPrompt ) => {
		const { wizardApiFetch } = this.props;
		this.setState( { inFlight: true } );
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/${ id }`,
			method: 'POST',
			data: { config: promptConfig },
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => {
				this.setState( { inFlight: false } );
				throw error;
			} );
	};

	/**
	 * Delete a popup.
	 *
	 * @param {number} popupId ID of the Popup to alter.
	 */
	deletePopup = ( popupId: number ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/${ popupId }`,
			method: 'DELETE',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	/**
	 * Restore a deleted a popup.
	 *
	 * @param {number} popupId ID of the Popup to alter.
	 */
	restorePopup = ( popupId: number ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/${ popupId }/restore`,
			method: 'POST',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	/**
	 * Publish a popup.
	 *
	 * @param {number} popupId ID of the Popup to alter.
	 */
	publishPopup = ( popupId: number ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/${ popupId }/publish`,
			method: 'POST',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	/**
	 * Unpublish a popup.
	 *
	 * @param {number} popupId ID of the Popup to alter.
	 */
	unpublishPopup = ( popupId: number ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/${ popupId }/publish`,
			method: 'DELETE',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	/**
	 * Duplicate a popup.
	 *
	 * @param {number} popupId ID of the Popup to duplicate.
	 * @param {string} title   Title to give to the duplicated prompt.
	 */
	duplicatePopup = ( popupId: number, title: string | Promise< void > ) => {
		const { setError, wizardApiFetch } = this.props;
		this.setState( { inFlight: true } );
		return wizardApiFetch( {
			path: addQueryArgs( `${ window.newspackAudienceCampaigns.api }/${ popupId }/duplicate`, {
				title,
			} ),
			method: 'POST',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( () => {
				setError( {
					code: 'duplicate_prompt_error',
					message: __( 'Error duplicating prompt. Please try again later.', 'newspack-plugin' ),
				} );
			} );
	};

	previewUrlForPopup = ( { options, id }: CampaignsPrompt ) => {
		const { placement, trigger_type: triggerType } = options;
		const previewQueryKeys: Record< string, string > = window.newspackAudienceCampaigns?.preview_query_keys || {};
		const abbreviatedKeys: Record< string, unknown > = {};
		const optionValues = options as Record< string, unknown >;
		Object.keys( options ).forEach( key => {
			if ( previewQueryKeys.hasOwnProperty( key ) ) {
				abbreviatedKeys[ previewQueryKeys[ key ] ] = optionValues[ key ];
			}
		} );

		let previewURL = '/';
		if ( 'archives' === placement && window.newspackAudienceCampaigns?.preview_archive ) {
			previewURL = window.newspackAudienceCampaigns.preview_archive;
		} else if ( ( 'inline' === placement || 'scroll' === triggerType ) && window && window.newspackAudienceCampaigns?.preview_post ) {
			previewURL = window.newspackAudienceCampaigns?.preview_post;
		}

		return `${ previewURL }?${ stringify( { ...abbreviatedKeys, pid: id } ) }`;
	};

	// The Campaigns wizard API responds with the full wizard state; the fetch
	// layer is untyped, so the payload is narrowed at this single boundary.
	updateAfterAPI = ( response: unknown ) => {
		const { campaigns, prompts, segments, settings, duplicated = null } = response as CampaignsData;
		this.setState( { campaigns, prompts, segments, settings, duplicated, inFlight: false } );
		this.refetchABTests();
	};

	loadABTests = latestOnly(
		() => apiFetch< ABTestsData >( { path: AB_TESTS_REST_BASE } ),
		abTests => this.setState( { abTests } )
	);

	// Reloaded with the prompts, since a duplicate, a delete or a new test
	// changes which prompts a menu can start a test from.
	refetchABTests = () => {
		if ( abTestingEnabled ) {
			this.loadABTests();
		}
	};

	manageCampaignGroup = ( campaigns: CampaignsPrompt[], method: 'POST' | 'DELETE' = 'POST' ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ window.newspackAudienceCampaigns.api }/batch-publish/`,
			data: { ids: campaigns.map( campaign => campaign.id ) },
			method,
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	render() {
		const { pluginRequirements, setError, isLoading, wizardApiFetch, startLoading, doneLoading } = this.props;
		const { campaigns, inFlight, prompts, segments, settings, previewUrl, previewTitle, duplicated } = this.state;
		return (
			<WebPreview
				url={ previewUrl ?? undefined }
				title={
					previewTitle
						? /* translators: %s: prompt title */ sprintf( __( 'Prompt: %s', 'newspack-plugin' ), decodeEntities( previewTitle ) )
						: undefined
				}
				onClose={ () => this.setState( { previewUrl: null, previewTitle: null } ) }
				renderButton={ ( { showPreview }: { showPreview: () => void } ) => {
					const sharedProps = {
						headerText,
						tabbedNavigation,
						setError,
						isLoading,
						startLoading,
						doneLoading,
						wizardApiFetch,
						prompts,
						segments,
						settings,
						duplicated,
						inFlight,
					};
					const popupManagementSharedProps = {
						...sharedProps,
						manageCampaignGroup: this.manageCampaignGroup,
						updatePopup: this.updatePopup,
						deletePopup: this.deletePopup,
						restorePopup: this.restorePopup,
						duplicatePopup: this.duplicatePopup,
						previewPopup: ( popup: CampaignsPrompt ) =>
							this.setState( { previewUrl: this.previewUrlForPopup( popup ), previewTitle: popup.title }, () => showPreview() ),
						publishPopup: this.publishPopup,
						resetDuplicated: () => this.setState( { duplicated: null } ),
						unpublishPopup: this.unpublishPopup,
						refetch: this.refetch,
					};
					return (
						<ABTestsContext.Provider value={ this.state.abTests }>
							<HashRouter hashType="slash">
								<Switch>
									{ pluginRequirements }
									<Route
										path="/campaigns/:id?"
										render={ ( props: RouteComponentProps< { id?: string } > ) => {
											const campaignId = props.match.params.id;

											const archiveCampaignGroup = ( id: number | string, status: boolean ) => {
												return wizardApiFetch( {
													path: `${ window.newspackAudienceCampaigns.api }/archive-campaign/${ id }`,
													method: status ? 'POST' : 'DELETE',
													quiet: true,
												} )
													.then( this.updateAfterAPI )
													.catch( error => setError( error ) );
											};
											const createCampaignGroup = ( name: string ) => {
												return wizardApiFetch( {
													path: `${ window.newspackAudienceCampaigns.api }/create-campaign/`,
													method: 'POST',
													data: { name },
													quiet: true,
												} )
													.then( resultRaw => {
														const result = resultRaw as CampaignsData;
														this.setState( {
															campaigns: result.campaigns,
															prompts: result.prompts,
															segments: result.segments,
															settings: result.settings,
														} );
														props.history.push( `/campaigns/${ result.term_id }` );
													} )
													.catch( error => setError( error ) );
											};
											const deleteCampaignGroup = ( id: number | string ) => {
												return wizardApiFetch( {
													path: `${ window.newspackAudienceCampaigns.api }/delete-campaign/${ id }`,
													method: 'DELETE',
													quiet: true,
												} )
													.then( resultRaw => {
														const result = resultRaw as CampaignsData;
														this.setState( {
															campaigns: result.campaigns,
															prompts: result.prompts,
															segments: result.segments,
															settings: result.settings,
														} );
														props.history.push( '/campaigns/' );
													} )
													.catch( error => setError( error ) );
											};
											const duplicateCampaignGroup = ( id: number | string, name: string ) => {
												return wizardApiFetch( {
													path: `${ window.newspackAudienceCampaigns.api }/duplicate-campaign/${ id }`,
													method: 'POST',
													data: { name },
													quiet: true,
												} )
													.then( resultRaw => {
														const result = resultRaw as CampaignsData;
														this.setState( {
															campaigns: result.campaigns,
															prompts: result.prompts,
															segments: result.segments,
															settings: result.settings,
														} );
														props.history.push( `/campaigns/${ result.term_id }` );
													} )
													.catch( error => setError( error ) );
											};
											const renameCampaignGroup = ( id: number | string, name: string ) => {
												return wizardApiFetch( {
													path: `${ window.newspackAudienceCampaigns.api }/rename-campaign/${ id }`,
													method: 'POST',
													data: { name },
													quiet: true,
												} )
													.then( this.updateAfterAPI )
													.catch( error => setError( error ) );
											};

											return (
												<CampaignsContext.Provider value={ prompts }>
													<Campaigns
														{ ...popupManagementSharedProps }
														campaignId={ campaignId }
														campaigns={ campaigns }
														subHeaderText={ __(
															'Manage the prompts readers see, grouped by the segment each one targets.',
															'newspack-plugin'
														) }
														headerActions={ <AddCampaignAction createCampaignGroup={ createCampaignGroup } /> }
														campaignActions={
															<CampaignActions
																archiveCampaignGroup={ archiveCampaignGroup }
																campaignId={ campaignId }
																campaigns={ campaigns }
																deleteCampaignGroup={ deleteCampaignGroup }
																duplicateCampaignGroup={ duplicateCampaignGroup }
																manageCampaignGroup={ this.manageCampaignGroup }
																prompts={ prompts }
																renameCampaignGroup={ renameCampaignGroup }
															/>
														}
													/>
												</CampaignsContext.Provider>
											);
										} }
									/>
									<Route
										path="/segments/:id?"
										render={ ( props: RouteComponentProps< { id?: string } > ) => (
											<Segments
												{ ...props }
												{ ...sharedProps }
												setSegments={ ( segmentsList: CampaignsSegment[] ) => this.setState( { segments: segmentsList } ) }
												subHeaderText={
													props.match.params.id
														? undefined
														: __(
																'Group readers by what they do on the site, then target prompts at them.',
																'newspack-plugin'
														  )
												}
											/>
										) }
									/>
									{ contextualPromptsEnabled && (
										<Route
											path="/contextual-prompts"
											exact
											render={ () => (
												<ContextualPrompts
													{ ...sharedProps }
													subHeaderText={ __(
														'Let editors generate story-specific donation prompts with AI.',
														'newspack-plugin'
													) }
												/>
											) }
										/>
									) }
									{ abTestingEnabled && (
										<Route
											path="/ab-tests"
											exact
											render={ ( routeProps: RouteComponentProps ) => (
												<ABTests { ...sharedProps } refetch={ this.refetch } location={ routeProps.location } />
											) }
										/>
									) }
									<Route
										path="/settings"
										render={ () => (
											<Settings
												{ ...sharedProps }
												subHeaderText={ __( 'Choose how prompts behave across the site.', 'newspack-plugin' ) }
											/>
										) }
									/>
									<Redirect to="/campaigns" />
								</Switch>
							</HashRouter>
						</ABTestsContext.Provider>
					);
				} }
			/>
		);
	}
}

export default withWizard( AudienceCampaigns );
