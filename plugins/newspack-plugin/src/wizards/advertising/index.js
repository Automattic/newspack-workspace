import '../../shared/js/public-path';

/**
 * Advertising
 */

/**
 * WordPress dependencies.
 */
import { Component, createRoot, Fragment, createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { withWizard, utils, Button } from '../../../packages/components/src';
import Router from '../../../packages/components/src/proxied-imports/router';
import { AdUnit, AdUnits, Providers, Settings, Placements } from './views';
import { isAdUnitValid } from './views/ad-unit';
import { getSizes } from './components/ad-unit-size-control';
import './style.scss';

const { HashRouter, Redirect, Route, Switch } = Router;
const CREATE_AD_ID_PARAM = 'create';

class AdvertisingWizard extends Component {
	/**
	 * Constructor.
	 */
	constructor() {
		super( ...arguments );
		this.state = {
			advertisingData: {
				adUnits: {},
				services: {
					google_ad_manager: {
						status: {},
					},
				},
				suppression: false,
			},
		};
	}

	/**
	 * wizardReady will be called when all plugin requirements are met.
	 */
	onWizardReady = () => {
		this.fetchAdvertisingData();
	};

	updateWithAPI = requestConfig =>
		this.props
			.wizardApiFetch( requestConfig )
			.then(
				response =>
					new Promise( resolve => {
						this.setState(
							{
								advertisingData: {
									...response,
									adUnits: response.ad_units.reduce( ( result, value ) => {
										result[ value.id ] = value;
										return result;
									}, {} ),
									parentAdUnits: response.parent_ad_units,
								},
							},
							() => {
								this.props.setError();
								resolve( this.state );
							}
						);
					} )
			)
			.catch( err => {
				this.props.setError( err );
				throw err;
			} );

	fetchAdvertisingData = ( quiet = false ) => this.updateWithAPI( { path: '/newspack/v1/wizard/billboard', quiet } );

	toggleService = ( service, enabled ) =>
		this.updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/service/' + service,
			method: enabled ? 'POST' : 'DELETE',
			quiet: true,
		} );

	/**
	 * Update a single ad unit.
	 */
	onAdUnitChange = adUnit => {
		const { advertisingData } = this.state;
		advertisingData.adUnits[ adUnit.id ] = adUnit;
		this.setState( { advertisingData } );
	};

	saveAdUnit = id =>
		this.updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/ad_unit/' + ( id || 0 ),
			method: 'post',
			data: this.state.advertisingData.adUnits[ id ],
			quiet: true,
		} );

	/**
	 * On cancel save/update ad unit.
	 */
	onAdUnitCancel = () => {
		this.fetchAdvertisingData();
	};

	/**
	 * Delete an ad unit.
	 *
	 * @param {number} id Ad Unit ID.
	 */
	deleteAdUnit = id => {
		if ( utils.confirmAction( __( 'Are you sure you want to archive this ad unit?', 'newspack-plugin' ) ) ) {
			return this.updateWithAPI( {
				path: '/newspack/v1/wizard/billboard/ad_unit/' + id,
				method: 'delete',
				quiet: true,
			} );
		}
	};

	updateAdSuppression = suppressionConfig =>
		this.updateWithAPI( {
			path: '/newspack/v1/wizard/billboard/suppression',
			method: 'post',
			data: { config: suppressionConfig },
			quiet: true,
		} );

	/**
	 * Render
	 */
	render() {
		const { advertisingData } = this.state;
		const { pluginRequirements, wizardApiFetch } = this.props;
		const { services, adUnits, parentAdUnits } = advertisingData;
		const newAdUnit = adUnits[ 0 ] || { id: 0, name: '', code: '', sizes: [ getSizes()[ 0 ] ], fluid: false };
		const ROOT = [ { label: __( 'Advertising', 'newspack-plugin' ) }, { label: __( 'Display Ads', 'newspack-plugin' ) } ];
		const PROVIDERS_TRAIL = [ ...ROOT, { label: __( 'Providers', 'newspack-plugin' ), url: '#/' } ];
		const GAM_TRAIL = [ ...PROVIDERS_TRAIL, { label: __( 'Google Ad Manager', 'newspack-plugin' ), url: '#/google_ad_manager' } ];
		const tabs = [
			{
				label: __( 'Providers', 'newspack-plugin' ),
				path: '/',
				exact: true,
				breadcrumbs: [ ...ROOT, { label: __( 'Providers', 'newspack-plugin' ) } ],
			},
			{
				label: __( 'Placements', 'newspack-plugin' ),
				path: '/placements',
				breadcrumbs: [ ...ROOT, { label: __( 'Placements', 'newspack-plugin' ) } ],
			},
			{
				label: __( 'Settings', 'newspack-plugin' ),
				path: '/settings',
				breadcrumbs: [ ...ROOT, { label: __( 'Settings', 'newspack-plugin' ) } ],
			},
		];
		return (
			<Fragment>
				<HashRouter hashType="slash">
					<Switch>
						{ pluginRequirements }
						<Route
							path="/"
							exact
							render={ () => (
								<Providers
									headerText={ __( 'Advertising / Display Ads', 'newspack-plugin' ) }
									services={ services }
									toggleService={ this.toggleService }
									fetchAdvertisingData={ this.fetchAdvertisingData }
									tabbedNavigation={ tabs }
								/>
							) }
						/>
						<Route
							path="/placements"
							render={ () => (
								<Placements headerText={ __( 'Advertising / Display Ads', 'newspack-plugin' ) } tabbedNavigation={ tabs } />
							) }
						/>
						<Route
							path="/settings"
							render={ () => (
								<Settings headerText={ __( 'Advertising / Display Ads', 'newspack-plugin' ) } tabbedNavigation={ tabs } />
							) }
						/>
						<Route
							path="/google_ad_manager"
							exact
							render={ () => (
								<AdUnits
									headerText="Google Ad Manager"
									breadcrumbItems={ [ ...PROVIDERS_TRAIL, { label: __( 'Google Ad Manager', 'newspack-plugin' ) } ] }
									headerActions={
										<Button variant="primary" href={ `#/google_ad_manager/${ CREATE_AD_ID_PARAM }` }>
											{ __( 'Add Ad Unit', 'newspack-plugin' ) }
										</Button>
									}
									adUnits={ adUnits }
									parentAdUnits={ parentAdUnits }
									service={ 'google_ad_manager' }
									serviceData={ services.google_ad_manager }
									onDelete={ id => this.deleteAdUnit( id ) }
									wizardApiFetch={ wizardApiFetch }
									fetchAdvertisingData={ this.fetchAdvertisingData }
									updateWithAPI={ this.updateWithAPI }
									tabbedNavigation={ tabs }
								/>
							) }
						/>
						<Route
							path={ `/google_ad_manager/${ CREATE_AD_ID_PARAM }` }
							render={ routeProps => (
								<AdUnit
									headerText={ __( 'Add Ad Unit', 'newspack-plugin' ) }
									breadcrumbItems={ [ ...GAM_TRAIL, { label: __( 'Add Ad Unit', 'newspack-plugin' ) } ] }
									headerActions={
										<>
											<Button
												variant="primary"
												disabled={ ! isAdUnitValid( newAdUnit ) }
												onClick={ () =>
													this.saveAdUnit( newAdUnit.id )
														.then( () => routeProps.history.push( '/google_ad_manager' ) )
														.catch( () => {} )
												}
											>
												{ __( 'Save', 'newspack-plugin' ) }
											</Button>
											<Button variant="secondary" href="#/google_ad_manager" onClick={ this.onAdUnitCancel }>
												{ __( 'Cancel', 'newspack-plugin' ) }
											</Button>
										</>
									}
									adUnit={ newAdUnit }
									service={ 'google_ad_manager' }
									serviceData={ services.google_ad_manager }
									wizardApiFetch={ wizardApiFetch }
									onChange={ this.onAdUnitChange }
									tabbedNavigation={ tabs }
								/>
							) }
						/>
						<Route
							path="/google_ad_manager/:id"
							render={ routeProps => {
								const adId = routeProps.match.params.id;
								const adUnit = adUnits[ adId ] || {};
								return (
									<AdUnit
										headerText={ __( 'Edit Ad Unit', 'newspack-plugin' ) }
										breadcrumbItems={ [ ...GAM_TRAIL, { label: __( 'Edit Ad Unit', 'newspack-plugin' ) } ] }
										headerActions={
											<>
												<Button
													variant="primary"
													disabled={ ! isAdUnitValid( adUnit ) }
													onClick={ () =>
														this.saveAdUnit( adUnit.id )
															.then( () => routeProps.history.push( '/google_ad_manager' ) )
															.catch( () => {} )
													}
												>
													{ __( 'Save', 'newspack-plugin' ) }
												</Button>
												<Button variant="secondary" href="#/google_ad_manager" onClick={ this.onAdUnitCancel }>
													{ __( 'Cancel', 'newspack-plugin' ) }
												</Button>
											</>
										}
										adUnit={ adUnit }
										service={ 'google_ad_manager' }
										onChange={ this.onAdUnitChange }
										tabbedNavigation={ tabs }
									/>
								);
							} }
						/>
						<Redirect to="/" />
					</Switch>
				</HashRouter>
			</Fragment>
		);
	}
}

createRoot( document.getElementById( 'newspack-ads-display-ads' ) ).render( createElement( withWizard( AdvertisingWizard, [ 'newspack-ads' ] ) ) );
