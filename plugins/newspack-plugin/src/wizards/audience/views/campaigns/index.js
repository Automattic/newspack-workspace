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

/**
 * Internal dependencies.
 */
import { WebPreview, withWizard } from '../../../../../packages/components/src';
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

class AudienceCampaigns extends Component {
	constructor( props ) {
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

	refetch = () => {
		const { setError, wizardApiFetch } = this.props;
		wizardApiFetch( {
			path: newspackAudienceCampaigns.api,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	updatePopup = ( { id, ...promptConfig } ) => {
		const { setError, wizardApiFetch } = this.props;
		this.setState( { inFlight: true } );
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/${ id }`,
			method: 'POST',
			data: { config: promptConfig },
			quiet: true,
		} )
			.then( this.updateAfterAPI )
			.catch( error => setError( error ) );
	};

	/**
	 * Delete a popup.
	 *
	 * @param {number} popupId ID of the Popup to alter.
	 */
	deletePopup = popupId => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }`,
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
	restorePopup = popupId => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/restore`,
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
	publishPopup = popupId => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/publish`,
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
	unpublishPopup = popupId => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/${ popupId }/publish`,
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
	duplicatePopup = ( popupId, title ) => {
		const { setError, wizardApiFetch } = this.props;
		this.setState( { inFlight: true } );
		return wizardApiFetch( {
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

	previewUrlForPopup = ( { options, id } ) => {
		const { placement, trigger_type: triggerType } = options;
		const previewQueryKeys = window.newspackAudienceCampaigns?.preview_query_keys || {};
		const abbreviatedKeys = {};
		Object.keys( options ).forEach( key => {
			if ( previewQueryKeys.hasOwnProperty( key ) ) {
				abbreviatedKeys[ previewQueryKeys[ key ] ] = options[ key ];
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

	updateAfterAPI = ( { campaigns, prompts, segments, settings, duplicated = null } ) =>
		this.setState( { campaigns, prompts, segments, settings, duplicated, inFlight: false } );

	manageCampaignGroup = ( campaigns, method = 'POST' ) => {
		const { setError, wizardApiFetch } = this.props;
		return wizardApiFetch( {
			path: `${ newspackAudienceCampaigns.api }/batch-publish/`,
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
				url={ previewUrl }
				title={
					previewTitle
						? /* translators: %s: prompt title */ sprintf( __( 'Prompt: %s', 'newspack-plugin' ), decodeEntities( previewTitle ) )
						: null
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
						previewPopup: popup =>
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
									render={ props => {
										const campaignId = props.match.params.id;

										const archiveCampaignGroup = ( id, status ) => {
											return wizardApiFetch( {
												path: `${ newspackAudienceCampaigns.api }/archive-campaign/${ id }`,
												method: status ? 'POST' : 'DELETE',
												quiet: true,
											} )
												.then( this.updateAfterAPI )
												.catch( error => setError( error ) );
										};
										const createCampaignGroup = name => {
											return wizardApiFetch( {
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
												.catch( error => setError( error ) );
										};
										const deleteCampaignGroup = id => {
											return wizardApiFetch( {
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
												.catch( error => setError( error ) );
										};
										const duplicateCampaignGroup = ( id, name ) => {
											return wizardApiFetch( {
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
												.catch( error => setError( error ) );
										};
										const renameCampaignGroup = ( id, name ) => {
											return wizardApiFetch( {
												path: `${ newspackAudienceCampaigns.api }/rename-campaign/${ id }`,
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
									render={ props => (
										<Segments
											{ ...props }
											{ ...sharedProps }
											setSegments={ segmentsList => this.setState( { segments: segmentsList } ) }
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
