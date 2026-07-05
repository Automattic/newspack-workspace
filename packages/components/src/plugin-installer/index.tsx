/**
 * Plugin Installer
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, check } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { ActionCard, Button, Waiting } from '../';
import './style.scss';

const PLUGIN_STATE_NONE = 0;
const PLUGIN_STATE_ACTIVE = 1;
const PLUGIN_STATE_INSTALLING = 2;
const PLUGIN_STATE_ERROR = 3;

/**
 * External dependencies.
 */
import classnames from 'classnames';

type PluginInstallationState =
	| typeof PLUGIN_STATE_NONE
	| typeof PLUGIN_STATE_ACTIVE
	| typeof PLUGIN_STATE_INSTALLING
	| typeof PLUGIN_STATE_ERROR;

/**
 * Plugin data fetched from the Newspack plugins API.
 */
interface PluginApiData {
	Name?: string;
	Description?: string;
	Download?: string;
	Status: string;
}

type PluginInfo = PluginApiData & {
	installationStatus: PluginInstallationState;
	notification?: string | null;
	checked?: boolean;
};

export interface PluginInstallationStatus {
	/** Whether all plugins are installed and active. */
	complete: boolean;
	/** The plugins' data, keyed by plugin slug. */
	pluginInfo: Record< string, PluginInfo >;
}

type PluginInstallerProps = {
	/** Slugs of the plugins to install. */
	plugins: string[];
	/** Whether to install the plugins as soon as their data is retrieved. */
	autoInstall?: boolean;
	/** Whether to render small ActionCards. */
	isSmall?: boolean;
	/** Whether to omit the install-all footer button. */
	withoutFooterButton?: boolean;
	/** Called with the installation status after every status change. */
	onStatus( status: PluginInstallationStatus ): void;
	/** Called with the plugin slug after each successful installation. */
	onInstalled( slug: string ): void;
};

type PluginInstallerState = {
	pluginInfo: Record< string, PluginInfo >;
	installationInitialized: boolean;
};

type PluginInfoUpdater = ( prev: Record< string, PluginInfo > ) => Record< string, PluginInfo >;

/**
 * Plugin installer.
 */
class PluginInstaller extends Component< PluginInstallerProps, PluginInstallerState > {
	static defaultProps = {
		onStatus: () => {},
		onInstalled: () => {},
	};

	constructor( props: PluginInstallerProps ) {
		super( props );
		this.state = {
			pluginInfo: {},
			installationInitialized: false,
		};
	}

	componentDidMount = () => {
		const { plugins } = this.props;
		this.retrievePluginInfo( plugins ).then( () => {
			if ( this.props.autoInstall ) {
				this.installAllPlugins();
			}
		} );
	};

	componentDidUpdate = ( prevProps: PluginInstallerProps ) => {
		const { autoInstall, plugins } = this.props;
		const { installationInitialized } = this.state;
		if ( plugins !== prevProps.plugins ) {
			this.retrievePluginInfo( plugins );
		}
		if ( autoInstall && ! installationInitialized ) {
			this.installAllPlugins();
		}
	};

	retrievePluginInfo = ( plugins: string[] ) => {
		return new Promise< void >( resolve => {
			apiFetch< Record< string, PluginApiData > >( { path: '/newspack/v1/plugins/' } ).then( response => {
				const pluginInfo = Object.keys( response ).reduce( ( result: Record< string, PluginInfo >, slug ) => {
					if ( plugins.indexOf( slug ) === -1 ) {
						return result;
					}
					result[ slug ] = {
						...response[ slug ],
						installationStatus: response[ slug ].Status === 'active' ? PLUGIN_STATE_ACTIVE : PLUGIN_STATE_NONE,
					};
					return result;
				}, {} );
				this.updatePluginInfo( pluginInfo ).then( () => resolve() );
			} );
		} );
	};

	installAllPlugins = () => {
		const { pluginInfo } = this.state;
		this.setState( { installationInitialized: true } );
		const promises = Object.keys( pluginInfo )
			.filter( slug => 'active' !== pluginInfo[ slug ].Status )
			.map( slug => () => this.installPlugin( slug ) );
		promises.reduce(
			( promise: Promise< unknown >, action ) => promise.then( result => action().then( Array.prototype.concat.bind( result ) ) ),
			Promise.resolve( [] )
		);
	};

	installPlugin = ( slug: string ) => {
		this.setInstallationStatus( slug, PLUGIN_STATE_INSTALLING );
		const params = {
			path: `/newspack/v1/plugins/${ slug }/configure/`,
			method: 'post',
		};
		return apiFetch< PluginApiData >( params )
			.then( response => {
				this.props.onInstalled( slug );
				return this.updatePluginInfo( prev => ( {
					...prev,
					[ slug ]: { ...response, installationStatus: PLUGIN_STATE_ACTIVE },
				} ) );
			} )
			.catch( error => {
				this.setInstallationStatus( slug, PLUGIN_STATE_ERROR, error.message );
			} );
	};

	setChecked = ( slug: string, checked: boolean ) => {
		this.updatePluginInfo( prev => ( {
			...prev,
			[ slug ]: { ...prev[ slug ], checked },
		} ) );
	};

	setInstallationStatus = ( slug: string, installationStatus: PluginInstallationState, notification: string | null = null ) => {
		this.updatePluginInfo( prev => ( {
			...prev,
			[ slug ]: { ...prev[ slug ], installationStatus, notification },
		} ) );
	};

	// Accepts either the next pluginInfo object or an updater `prev => next`.
	// The updater form is required for serial install chains: setState batches
	// pending updates inside the same microtask, so reading `this.state.pluginInfo`
	// at call time can return a snapshot that predates an earlier-in-the-chain
	// pending write, and the second update would clobber the first.
	updatePluginInfo = ( pluginInfoOrUpdater: Record< string, PluginInfo > | PluginInfoUpdater ) => {
		return new Promise< void >( resolve => {
			const { onStatus } = this.props;
			this.setState(
				prevState => ( {
					pluginInfo: typeof pluginInfoOrUpdater === 'function' ? pluginInfoOrUpdater( prevState.pluginInfo ) : pluginInfoOrUpdater,
				} ),
				() => {
					const { pluginInfo } = this.state;
					const complete = Object.values( pluginInfo ).every( plugin => {
						return 'active' === plugin.Status;
					} );
					onStatus( { complete, pluginInfo } );
					resolve();
				}
			);
		} );
	};

	classForInstallationStatus = ( status: PluginInstallationState ) => {
		switch ( status ) {
			case PLUGIN_STATE_ACTIVE:
				return 'newspack-plugin-installer__status-active';
			case PLUGIN_STATE_INSTALLING:
				return 'newspack-plugin-installer__status-installing';
			case PLUGIN_STATE_ERROR:
				return 'newspack-plugin-installer__status-error';
			default:
				return 'newspack-plugin-installer__status-none';
		}
	};

	/**
	 * Render.
	 */
	render() {
		const { autoInstall, isSmall, withoutFooterButton } = this.props;
		const { pluginInfo } = this.state;
		const isManaged = typeof newspack_aux_data !== 'undefined' && newspack_aux_data.is_managed;
		const slugs = Object.keys( pluginInfo );

		// Store all plugin status info for installer button text value based on current status.
		const currentPluginStatuses: string[] = [];
		slugs.forEach( slug => {
			const plugin = pluginInfo[ slug ];
			currentPluginStatuses.push( plugin.Status );
		} );

		// Make sure plugin status falls in either one of these, to handle button text.
		const pluginInstalled = ( currentStatus?: string ) => currentStatus === 'active' || currentStatus === 'inactive';

		const buttonText = currentPluginStatuses.every( pluginInstalled ) ? __( 'Activate', 'newspack-plugin' ) : __( 'Install', 'newspack-plugin' );

		const needsInstall = slugs.some( slug => {
			const plugin = pluginInfo[ slug ];
			return plugin.Status !== 'active' && plugin.installationStatus === PLUGIN_STATE_NONE;
		} );

		return (
			<>
				{ ( ! pluginInfo || ! Object.keys( pluginInfo ).length ) && (
					<div className="newspack-plugin-installer_is-waiting">
						<Waiting isLeft />
						{ __( 'Retrieving plugin information…', 'newspack-plugin' ) }
					</div>
				) }
				{ pluginInfo &&
					slugs.length > 0 &&
					slugs.map( slug => {
						const plugin = pluginInfo[ slug ];
						const { Name, Description, Download, Status, installationStatus, notification } = plugin;
						const isWaiting = installationStatus === PLUGIN_STATE_INSTALLING;
						const isButton = ! isWaiting && Status !== 'active';
						const installable = Download || pluginInstalled( Status );
						let actionText;
						if ( installationStatus === PLUGIN_STATE_INSTALLING ) {
							actionText = 'inactive' === Status ? __( 'Activating…' ) : __( 'Installing…' );
						} else if ( ! installable ) {
							actionText = (
								<span className="newspack-plugin-installer__status">
									{ isManaged
										? __( 'Contact Newspack support to install', 'newspack-plugin' )
										: __( 'Plugin must be installed manually', 'newspack-plugin' ) }
									<span className="newspack-checkbox-icon" />
								</span>
							);
						} else if ( Status === 'uninstalled' ) {
							actionText = (
								<span className="newspack-plugin-installer__status">
									{ __( 'Install', 'newspack' ) }
									<span className="newspack-checkbox-icon" />
								</span>
							);
						} else if ( Status === 'inactive' ) {
							actionText = (
								<span className="newspack-plugin-installer__status">
									{ __( 'Activate', 'newspack-plugin' ) }
									<span className="newspack-checkbox-icon" />
								</span>
							);
						} else if ( Status === 'active' ) {
							actionText = (
								<span className="newspack-plugin-installer__status">
									{ __( 'Installed', 'newspack-plugin' ) }
									<span className="newspack-checkbox-icon newspack-checkbox-icon--checked">
										<Icon icon={ check } />
									</span>
								</span>
							);
						}

						const classes = classnames( 'newspack-action-card__plugin-installer', this.classForInstallationStatus( installationStatus ) );
						const onClick = isButton ? () => this.installPlugin( slug ) : null;
						return (
							<ActionCard
								key={ slug }
								title={ Name }
								description={ Description }
								disabled={ ! installable }
								actionText={ actionText }
								isSmall={ isSmall }
								isWaiting={ isWaiting }
								onClick={ onClick }
								notification={ notification }
								notificationLevel="error"
								notificationHTML
								className={ classes }
							/>
						);
					} ) }
				{ ! withoutFooterButton && ! autoInstall && pluginInfo && slugs.length > 0 && (
					<div className="newspack-buttons-card">
						<Button disabled={ ! needsInstall } isPrimary onClick={ this.installAllPlugins }>
							{ buttonText }
						</Button>
					</div>
				) }
			</>
		);
	}
}

export default PluginInstaller;
