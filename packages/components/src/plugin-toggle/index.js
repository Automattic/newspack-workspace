/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Component, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { ActionCard, Waiting } from '../';
import './style.scss';

/**
 * Plugin toggle group component.
 */
class PluginToggle extends Component {
	state = {
		pluginInfo: {},
	};

	componentDidMount = () => {
		this.retrievePluginInfo();
	};

	/**
	 * Retrieve complete data about Newspack plugins.
	 */
	retrievePluginInfo = () => {
		return new Promise( () => {
			apiFetch( { path: '/newspack/v1/plugins/' } ).then( pluginInfo => this.setState( { pluginInfo } ) );
		} );
	};

	/**
	 * Install/activate or remove a plugin.
	 */
	managePlugin = ( plugin, value ) => {
		const { plugins } = this.props;
		const { pluginInfo } = this.state;
		const action = value ? 'configure' : 'deactivate';
		const params = {
			path: `/newspack/v1/plugins/${ plugin }/${ action }/`,
			method: 'post',
		};

		this.setState(
			{
				pluginInfo: { ...pluginInfo, [ plugin ]: { ...pluginInfo[ plugin ], inFlight: action } },
			},
			() => {
				apiFetch( params )
					.then( response => {
						const { shouldRefreshAfterUpdate } = plugins[ plugin ];
						this.setState(
							( { pluginInfo: currentPluginInfo } ) => ( {
								pluginInfo: { ...currentPluginInfo, [ plugin ]: response },
							} ),
							() => shouldRefreshAfterUpdate && location.reload()
						);
					} )
					.catch( e => {
						this.setState( {
							pluginInfo: {
								...pluginInfo,
								[ plugin ]: {
									...pluginInfo[ plugin ],
									error: e.message || __( 'There was an error managing this plugin.', 'newspack-plugin' ),
								},
							},
						} );
					} );
			}
		);
	};

	/**
	 * Render.
	 */
	render() {
		const { plugins } = this.props;
		const { pluginInfo } = this.state;
		return this.prepareDataForRender( plugins, pluginInfo ).map( ( plugin, index ) => {
			const { name, description, href, slug, editPath } = plugin;
			const pluginStatus = this.isPluginInstalledAndActive( plugin );
			const handoff = ! href && pluginStatus && editPath ? slug : null;
			const error = this.errorForPlugin( plugin );
			return (
				<ActionCard
					key={ index }
					className={ this.classNameForPlugin( plugin ) }
					title={ name }
					description={ description }
					actionText={ this.actionTextForPlugin( plugin ) }
					handoff={ handoff }
					onClick={ plugin.onClick ?? null }
					href={ href }
					toggle
					toggleChecked={ this.isPluginInstalledAndActive( plugin ) }
					toggleOnChange={ value => this.managePlugin( slug, value ) }
					notification={ error }
					notificationHTML={ error }
					notificationLevel="error"
				/>
			);
		} );
	}

	/**
	 * Prepare plugins data for render. Change all keys to camelCase, merge API-fetched data with prop.
	 */
	prepareDataForRender = ( pluginsFromProps, pluginsFromAPI ) =>
		Object.keys( pluginsFromProps )
			.map( pluginSlug =>
				pluginsFromAPI[ pluginSlug ]
					? Object.keys( pluginsFromAPI[ pluginSlug ] ).reduce(
							( accumulator, key ) => ( {
								...accumulator,
								[ key.charAt( 0 ).toLowerCase() + key.slice( 1 ) ]: pluginsFromAPI[ pluginSlug ][ key ],
							} ),
							{}
					  )
					: {}
			)
			.map( plugin => Object.assign( plugin, pluginsFromProps[ plugin.slug ] ) );

	/**
	 * Generate a classname for the ActionCard based on plugin state. Applies 'in-flight' class if an API is underway and 'loading' if the plugin data is not yet available.
	 */
	classNameForPlugin = plugin => {
		const { status, inFlight } = plugin;
		if ( inFlight ) {
			return 'in-flight';
		}
		if ( ! status ) {
			return 'loading';
		}
	};

	/**
	 * Generate the ActionCard action text for a plugin.
	 */
	actionTextForPlugin = plugin => {
		const { actionText, editPath, href, inFlight, name } = plugin;
		// Show spinner when plugin data is unavailable, or when an API call is in flight.
		if ( 'configure' === inFlight ) {
			return (
				<Fragment>
					{ __( 'Installing…', 'newspack-plugin' ) } <Waiting isRight />
				</Fragment>
			);
		}
		if ( 'deactivate' === inFlight ) {
			return (
				<Fragment>
					{ __( 'Deactivating…', 'newspack-plugin' ) } <Waiting isRight />
				</Fragment>
			);
		}
		if ( ! name ) {
			return (
				<Fragment>
					{ __( 'Loading…', 'newspack-plugin' ) } <Waiting isRight />
				</Fragment>
			);
		}
		// No action button at all if the plugin isn't installed and active.
		if ( ! this.isPluginInstalledAndActive( plugin ) ) {
			return null;
		}
		if ( href || editPath ) {
			return actionText ? actionText : __( 'Configure', 'newspack-plugin' );
		}
	};

	/**
	 * Get error message for this plugin.
	 */
	errorForPlugin = plugin => {
		const { slug } = plugin;
		const { pluginInfo } = this.state;

		return pluginInfo[ slug ]?.error || null;
	};

	/**
	 * Get installation/activation status for a plugin.
	 */
	isPluginInstalledAndActive = plugin => {
		const { status } = plugin;
		return status === 'active';
	};
}

export default PluginToggle;
