/**
 * WordPress dependencies.
 */
import { Component, createRef, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { category } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, Card, Modal, NewspackIcon, Notice, PluginInstaller } from '../';
import Router from '../proxied-imports/router';
import Footer from '../footer';
import './style.scss';

const { Redirect, Route } = Router;

/**
 * Higher-Order Component to provide plugin management and error handling to Newspack Wizards.
 */
export default function withWizard( WrappedComponent, requiredPlugins ) {
	return class WrappedWithWizard extends Component {
		constructor( props ) {
			super( props );
			this.state = {
				complete: null,
				error: null,
				loading: requiredPlugins && requiredPlugins.length > 0 ? 1 : 0,
				quietLoading: false,
				confirmation: null,
			};
			this.wrappedComponentRef = createRef();
		}

		componentDidMount = () => {
			// If there are no requiredPlugins, fire onWizardReady as soon as component mounts.
			if ( ! requiredPlugins ) {
				const instance = this.wrappedComponentRef.current;
				// eslint-disable-next-line no-unused-expressions
				instance && instance.onWizardReady && instance.onWizardReady();
			}
		};

		/**
		 * Set the error. Called by Wizards when an error occurs.
		 *
		 * @return {Promise} Resolved after state update
		 */
		setError = error => {
			return new Promise( resolve => {
				this.setState( { error: error || null }, () => resolve() );
			} );
		};

		/**
		 * Render any errors that need rendering.
		 *
		 * @return {Component} Error UI
		 */
		getError = () => {
			const { error } = this.state;
			if ( ! error ) {
				return null;
			}

			const parsedError = this.parseError( error );
			const { level } = parsedError;
			if ( 'fatal' === level ) {
				return this.getFatalError( parsedError );
			}

			return this.getErrorNotice( parsedError );
		};

		/**
		 * Get a notice-level error.
		 *
		 * @param {Error} error object already parsed by parseError
		 * @return {Component} Error notice
		 */
		getErrorNotice = error => {
			const { message } = error;
			return <Notice isError className="newspack-wizard__above-header" noticeText={ message } rawHTML />;
		};

		/**
		 * Get a fatal-level error.
		 *
		 * @param {Error} error object already parsed by parseError
		 * @return {Component} React object
		 */
		getFatalError = error => {
			const fallbackURL = this.getFallbackURL();
			if ( ! fallbackURL ) {
				return null;
			}
			const { message } = error;
			return (
				<Modal title={ __( 'Unrecoverable error' ) } onRequestClose={ () => ( window.location = fallbackURL ) }>
					<Notice noticeText={ message } isError rawHTML />
					<Card buttonsCard noBorder className="justify-end">
						<Button isPrimary href={ fallbackURL }>
							{ __( 'Return to Dashboard', 'newspack-plugin' ) }
						</Button>
					</Card>
				</Modal>
			);
		};

		/**
		 * Get all the relevant info out of a raw API error response.
		 *
		 * @param {Object} error error object
		 * @return {Object} Error object with relevant fields and defaults
		 */
		parseError = error => {
			const { data, message, code } = error;
			let level = 'fatal';
			if ( !! data && 'level' in data ) {
				level = data.level;
			} else if ( 'rest_invalid_param' === code ) {
				level = 'notice';
			}

			return {
				message,
				level,
			};
		};

		/**
		 * Called when plugin installation is complete. Updates state and calls onWizardReady on the wrapped component.
		 */
		pluginInstallationStatus = ( { complete } ) => {
			if ( this.state.loading ) {
				this.doneLoading();
			}
			const instance = this.wrappedComponentRef.current;
			this.setState( { complete }, () => {
				// eslint-disable-next-line no-unused-expressions
				complete && instance && instance.onWizardReady && instance.onWizardReady();
			} );
		};

		/**
		 * Begin loading.
		 */
		startLoading = quiet => {
			if ( quiet ) {
				this.setState( state => ( {
					quietLoading: state.quietLoading + 1,
				} ) );
			} else {
				this.setState( state => ( {
					loading: state.loading + 1,
				} ) );
			}
		};

		/**
		 * End loading.
		 */
		doneLoading = quiet => {
			if ( quiet ) {
				this.setState( state => ( {
					quietLoading: state.quietLoading - 1,
				} ) );
			} else {
				this.setState( state => ( {
					loading: state.loading - 1,
				} ) );
			}
		};

		/**
		 * Replacement for core apiFetch that automatically manages wizard loading UI.
		 */
		wizardApiFetch = args => {
			const { quiet } = args;
			this.startLoading( quiet );
			return new Promise( ( resolve, reject ) => {
				apiFetch( args )
					.then( response => {
						this.doneLoading( quiet );
						resolve( response );
					} )
					.catch( error => {
						this.doneLoading( quiet );
						reject( error );
					} );
			} );
		};

		/**
		 * Render a Route that checks for plugin installation requirements, and redirects to '/' when all are done.
		 *
		 * @return {void}
		 */
		pluginRequirements = () => {
			const { complete } = this.state;
			/* After all plugins are loaded, redirect to / (this could be configurable) */
			if ( complete ) {
				return <Redirect from="/plugin-requirements" to="/" />;
			}
			return (
				<Route
					path="/"
					render={ () => (
						<Fragment>
							{ complete !== null && (
								<div className="newspack-wizard__header">
									<div className="newspack-wizard__header__inner">
										<div className="newspack-wizard__title">
											<Button
												isLink
												href={ newspack_urls.dashboard }
												label={ __( 'Return to Dashboard', 'newspack-plugin' ) }
												showTooltip={ true }
												icon={ category }
												iconSize={ 36 }
											>
												<NewspackIcon size={ 36 } />
											</Button>
											<div>
												<h2>
													{ requiredPlugins.length > 1
														? __( 'Required plugins', 'newspack-plugin' )
														: __( 'Required plugin', 'newspack-plugin' ) }
												</h2>
											</div>
										</div>
									</div>
								</div>
							) }
							<div className="newspack-wizard newspack-wizard__content">
								<PluginInstaller plugins={ requiredPlugins } onStatus={ status => this.pluginInstallationStatus( status ) } />
							</div>
						</Fragment>
					) }
				/>
			);
		};

		/**
		 * Build a confirmation modal with the given title & message.
		 * Execute {callback} if confirmed.
		 *
		 * @property {Object}   options             Options for the confirmation modal.
		 * @property {string}   options.title       The title for the modal component.
		 * @property {string}   options.message     The message for the modal component body.
		 * @property {string}   options.confirmText The text for the confirmation button.
		 * @property {string}   options.cancelText  The text for the cancel button.
		 * @property {Function} options.callback    A function to call if the user confirms the action.
		 */
		confirmAction = options => {
			const modalOptions = {
				title: null,
				message: __( 'Are you sure?', 'newpack-plugin' ),
				confirmText: __( 'OK', 'newspack-plugin' ),
				cancelText: __( 'Cancel', 'newspack-plugin' ),
				callback: null,
				...options,
			};
			this.setState( { confirmation: modalOptions } );
		};

		/**
		 * Show a confirmation modal with the given title & message.
		 * Execute {callback} if confirmed.
		 *
		 * @return {Component} <Modal>
		 */
		getModal = () => {
			if ( ! this.state.confirmation ) {
				return null;
			}
			const { title, message, confirmText, cancelText, callback } = this.state.confirmation;
			return (
				message &&
				callback && (
					<Modal size="small" hideTitle={ ! title } title={ title } onRequestClose={ () => this.setState( { confirmation: null } ) }>
						<p>{ message }</p>
						<Card buttonsCard noBorder className="justify-end">
							<Button variant="secondary" onClick={ () => this.setState( { confirmation: null } ) }>
								{ cancelText }
							</Button>
							<Button
								variant="primary"
								onClick={ () => {
									this.setState( { confirmation: null } );
									callback();
								} }
							>
								{ confirmText }
							</Button>
						</Card>
					</Modal>
				)
			);
		};

		getFallbackURL = () => {
			if ( typeof newspack_urls !== 'undefined' ) {
				return newspack_urls.dashboard;
			}
		};

		/**
		 * Render.
		 */
		render() {
			const { simpleFooter } = this.props;
			const { loading, quietLoading, error } = this.state;
			const loadingClasses = [ loading ? 'newspack-wizard__is-loading' : 'newspack-wizard__is-loaded' ];
			if ( quietLoading ) {
				loadingClasses.push( 'newspack-wizard__is-loading-quiet' );
			}
			return (
				<Fragment>
					{ this.getError() }
					{ this.getModal() }
					<div className={ loadingClasses.join( ' ' ) }>
						<WrappedComponent
							confirmAction={ this.confirmAction }
							pluginRequirements={ requiredPlugins && this.pluginRequirements() }
							clearError={ this.clearError }
							getError={ this.getError }
							errorData={ error }
							setError={ this.setError }
							isLoading={ loading }
							startLoading={ this.startLoading }
							doneLoading={ this.doneLoading }
							wizardApiFetch={ this.wizardApiFetch }
							ref={ this.wrappedComponentRef }
							{ ...this.props }
						/>
					</div>
					{ ! loading && <Footer simple={ simpleFooter } /> }
				</Fragment>
			);
		}
	};
}
