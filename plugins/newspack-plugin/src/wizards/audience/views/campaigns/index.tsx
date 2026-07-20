/* globals newspackAudienceCampaigns */
import '../../../../shared/js/public-path';

/**
 * Campaigns Wizard
 */

/**
 * WordPress dependencies.
 */
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
import type { WithWizardInjectedProps, WizardError } from '../../../../../packages/components/src/with-wizard';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import { Campaigns, Settings, Segments } from './views';
import { CampaignsContext } from '../../contexts';

const { HashRouter, Redirect, Route, Switch } = Router;

const headerText = __( 'Audience Management / Campaigns', 'newspack-plugin' );

const tabbedNavigation = [
	{
		label: __( 'Campaigns', 'newpack-plugin' ),
		path: '/campaigns',
		exact: true,
	},
	{
		label: __( 'Segments', 'newpack-plugin' ),
		path: '/segments',
		exact: false,
	},
	{
		label: __( 'Settings', 'newpack-plugin' ),
		path: '/settings',
		exact: true,
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
		};
	}
	onWizardReady = () => {
		this.refetch();
	};

	/**
	 * The Campaigns wizard API responds with the full wizard state; the fetch
	 * layer is untyped, so the payload is asserted at this single boundary.
	 */
	fetchCampaignsData = ( args: Parameters< AudienceCampaignsProps[ 'wizardApiFetch' ] >[ 0 ] ) =>
		this.props.wizardApiFetch( args ) as Promise< CampaignsData >;

	refetch = () => {
		const { setError } = this.props;
		this.fetchCampaignsData( {
			path: newspackAudienceCampaigns.api,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	updatePopup = ( { id, ...promptConfig }: CampaignsPrompt ) => {
		const { setError } = this.props;
		this.setState( { inFlight: true } );
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/${ id }`,
			method: 'POST',
			data: { config: promptConfig },
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	/**
	 * Delete a popup.
	 *
	 * @param popupId ID of the Popup to alter.
	 */
	deletePopup = ( popupId: number ) => {
		const { setError } = this.props;
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }`,
			method: 'DELETE',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	/**
	 * Restore a deleted a popup.
	 *
	 * @param popupId ID of the Popup to alter.
	 */
	restorePopup = ( popupId: number ) => {
		const { setError } = this.props;
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/restore`,
			method: 'POST',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	/**
	 * Publish a popup.
	 *
	 * @param popupId ID of the Popup to alter.
	 */
	publishPopup = ( popupId: number ) => {
		const { setError } = this.props;
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/publish`,
			method: 'POST',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	/**
	 * Unpublish a popup.
	 *
	 * @param popupId ID of the Popup to alter.
	 */
	unpublishPopup = ( popupId: number ) => {
		const { setError } = this.props;
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/publish`,
			method: 'DELETE',
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
	};

	/**
	 * Duplicate a popup.
	 *
	 * @param popupId ID of the Popup to duplicate.
	 * @param title   Title to give to the duplicated prompt.
	 */
	duplicatePopup = ( popupId: number, title: string | Promise< void > ) => {
		const { setError } = this.props;
		this.setState( { inFlight: true } );
		return this.fetchCampaignsData( {
			path: addQueryArgs( `${ newspackAudienceCampaigns.api }/${ popupId }/duplicate`, {
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
		const previewQueryKeys: Partial< Record< string, string > > = window.newspackAudienceCampaigns?.preview_query_keys || {};
		const abbreviatedKeys: Record< string, unknown > = {};
		const optionsRecord: Record< string, unknown > = options;
		Object.keys( optionsRecord ).forEach( key => {
			const abbreviatedKey = previewQueryKeys[ key ];
			if ( abbreviatedKey !== undefined ) {
				abbreviatedKeys[ abbreviatedKey ] = optionsRecord[ key ];
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

	updateAfterAPI = ( { campaigns, prompts, segments, settings, duplicated = null }: CampaignsData ) =>
		this.setState( { campaigns, prompts, segments, settings, duplicated, inFlight: false } );

	manageCampaignGroup = ( campaigns: CampaignsPrompt[], method: 'POST' | 'DELETE' = 'POST' ) => {
		const { setError } = this.props;
		return this.fetchCampaignsData( {
			path: `${ newspackAudienceCampaigns.api }/batch-publish/`,
			data: { ids: campaigns.map( campaign => campaign.id ) },
			method,
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( ( error: WizardError ) => setError( error ) );
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
				renderButton={ ( { showPreview } ) => {
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
						<HashRouter hashType="slash">
							<Switch>
								{ pluginRequirements }
								<Route
									path="/campaigns/:id?"
									render={ ( props: RouteComponentProps< { id?: string } > ) => {
										const campaignId = props.match.params.id;

										const archiveCampaignGroup = ( id: number | string, status: boolean ) => {
											return this.fetchCampaignsData( {
												path: `${ newspackAudienceCampaigns.api }/archive-campaign/${ id }`,
												method: status ? 'POST' : 'DELETE',
												quiet: true,
											} )
												.then( this.updateAfterAPI )
												.catch( ( error: WizardError ) => setError( error ) );
										};
										const createCampaignGroup = ( name: string ) => {
											return this.fetchCampaignsData( {
												path: `${ newspackAudienceCampaigns.api }/create-campaign/`,
												method: 'POST',
												data: { name },
												quiet: true,
											} )
												.then( result => {
													this.setState( {
														campaigns: result.campaigns,
														prompts: result.prompts,
														segments: result.segments,
														settings: result.settings,
													} );
													props.history.push( `/campaigns/${ result.term_id }` );
												} )
												.catch( ( error: WizardError ) => setError( error ) );
										};
										const deleteCampaignGroup = ( id: number | string ) => {
											return this.fetchCampaignsData( {
												path: `${ newspackAudienceCampaigns.api }/delete-campaign/${ id }`,
												method: 'DELETE',
												quiet: true,
											} )
												.then( result => {
													this.setState( {
														campaigns: result.campaigns,
														prompts: result.prompts,
														segments: result.segments,
														settings: result.settings,
													} );
													props.history.push( '/campaigns/' );
												} )
												.catch( ( error: WizardError ) => setError( error ) );
										};
										const duplicateCampaignGroup = ( id: number | string, name: string ) => {
											return this.fetchCampaignsData( {
												path: `${ newspackAudienceCampaigns.api }/duplicate-campaign/${ id }`,
												method: 'POST',
												data: { name },
												quiet: true,
											} )
												.then( result => {
													this.setState( {
														campaigns: result.campaigns,
														prompts: result.prompts,
														segments: result.segments,
														settings: result.settings,
													} );
													props.history.push( `/campaigns/${ result.term_id }` );
												} )
												.catch( ( error: WizardError ) => setError( error ) );
										};
										const renameCampaignGroup = ( id: number | string, name: string ) => {
											return this.fetchCampaignsData( {
												path: `${ newspackAudienceCampaigns.api }/rename-campaign/${ id }`,
												method: 'POST',
												data: { name },
												quiet: true,
											} )
												.then( this.updateAfterAPI )
												.catch( ( error: WizardError ) => setError( error ) );
										};

										return (
											<CampaignsContext.Provider value={ prompts }>
												<Campaigns
													{ ...popupManagementSharedProps }
													archiveCampaignGroup={ archiveCampaignGroup }
													campaignId={ campaignId }
													createCampaignGroup={ createCampaignGroup }
													deleteCampaignGroup={ deleteCampaignGroup }
													duplicateCampaignGroup={ duplicateCampaignGroup }
													renameCampaignGroup={ renameCampaignGroup }
													campaigns={ campaigns }
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
										/>
									) }
								/>
								<Route path="/settings" render={ () => <Settings { ...sharedProps } /> } />
								<Redirect to="/campaigns" />
							</Switch>
						</HashRouter>
					);
				} }
			/>
		);
	}
}

export default withWizard( AudienceCampaigns );
