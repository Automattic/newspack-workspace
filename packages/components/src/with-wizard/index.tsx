/**
 * WordPress dependencies.
 */
import { Component, createRef, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import type { APIFetchOptions } from '@wordpress/api-fetch';
import { category } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Button, Card, Modal, NewspackIcon, Notice, PluginInstaller } from '../';
import Router from '../proxied-imports/router';
import Footer from '../footer';
import type { PluginInstallationStatus } from '../plugin-installer';
import './style.scss';

const { Redirect, Route } = Router;

/**
 * An error reported by a wizard: an API error response, or a plain message.
 */
export type WizardError = {
	message?: string;
	code?: string;
	data?: { level?: string };
} | null;

type ParsedWizardError = {
	message?: string;
	level?: string;
};

export interface WizardConfirmationOptions {
	/** The title for the modal component. */
	title?: React.ReactNode | null;
	/** The message for the modal component body. */
	message?: React.ReactNode;
	/** The text for the confirmation button. */
	confirmText?: React.ReactNode;
	/** The text for the cancel button. */
	cancelText?: React.ReactNode;
	/** A function to call if the user confirms the action. */
	callback?: ( () => void ) | null;
}

/**
 * Props injected into the wrapped component by the withWizard HOC.
 */
export interface WithWizardInjectedProps {
	/** Shows a confirmation modal, executing the options' callback if confirmed. */
	confirmAction( options: WizardConfirmationOptions ): void;
	/** Plugin-requirements route, rendered while required plugins are being installed. */
	pluginRequirements?: React.ReactNode;
	/** Renders the current error, if any. */
	getError(): React.ReactNode;
	/** The current error, if any. */
	errorData: WizardError;
	/** Sets the error. Resolves after the state update. */
	setError( error?: WizardError ): Promise< void >;
	/** Loading-operations counter; truthy while loading. */
	isLoading: number;
	/** Begins a (quiet or regular) loading operation. */
	startLoading( quiet?: boolean ): void;
	/** Ends a (quiet or regular) loading operation. */
	doneLoading( quiet?: boolean ): void;
	/** apiFetch wrapper that manages the wizard loading UI. */
	wizardApiFetch( args: APIFetchOptions & { quiet?: boolean } ): Promise< unknown >;
}

/**
 * Instance interface of components wrapped by withWizard: the HOC calls
 * `onWizardReady` on the instance once plugin requirements are satisfied.
 */
interface WithWizardWrappedInstance {
	onWizardReady?(): void;
}

type WithWizardState = {
	complete: boolean | null;
	error: WizardError;
	loading: number;
	quietLoading: number;
	confirmation: WizardConfirmationOptions | null;
};

/**
 * Higher-Order Component to provide plugin management and error handling to Newspack Wizards.
 */
export default function withWizard< P extends object >( WrappedComponent: React.ComponentType< P >, requiredPlugins?: string[] ) {
	// The wrapped component receives the injected wizard props along with the
	// pass-through props, and may expose `onWizardReady` on its instance
	// (class components only), which the HOC reaches through a ref.
	const WrappedComponentWithInjectedProps = WrappedComponent as React.ComponentClass<
		P & Partial< WithWizardInjectedProps > & { ref?: React.Ref< WithWizardWrappedInstance > }
	>;

	return class WrappedWithWizard extends Component< P & { simpleFooter?: boolean }, WithWizardState > {
		wrappedComponentRef: React.RefObject< WithWizardWrappedInstance >;

		constructor( props: P & { simpleFooter?: boolean } ) {
			super( props );
			this.state = {
				complete: null,
				error: null,
				loading: requiredPlugins && requiredPlugins.length > 0 ? 1 : 0,
				quietLoading: 0,
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
		 * @return Resolved after state update
		 */
		setError = ( error?: WizardError ) => {
			return new Promise< void >( resolve => {
				this.setState( { error: error || null }, () => resolve() );
			} );
		};

		/**
		 * Render any errors that need rendering.
		 *
		 * @return Error UI
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
		 * @param error object already parsed by parseError
		 * @return Error notice
		 */
		getErrorNotice = ( error: ParsedWizardError ) => {
			const { message } = error;
			return <Notice isError className="newspack-wizard__above-header" noticeText={ message } rawHTML />;
		};

		/**
		 * Get a fatal-level error.
		 *
		 * @param error object already parsed by parseError
		 * @return React object
		 */
		getFatalError = ( error: ParsedWizardError ) => {
			const fallbackURL = this.getFallbackURL();
			if ( ! fallbackURL ) {
				return null;
			}
			const { message } = error;
			return (
				<Modal title={ __( 'Unrecoverable error' ) } onRequestClose={ () => ( window.location.href = fallbackURL ) }>
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
		 * @param error error object
		 * @return Error object with relevant fields and defaults
		 */
		parseError = ( error: NonNullable< WizardError > ): ParsedWizardError => {
			const { data, message, code } = error;
			let level = 'fatal';
			if ( !! data && 'level' in data && typeof data.level === 'string' ) {
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
		pluginInstallationStatus = ( { complete }: PluginInstallationStatus ) => {
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
		startLoading = ( quiet?: boolean ) => {
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
		doneLoading = ( quiet?: boolean ) => {
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
		wizardApiFetch = ( args: APIFetchOptions & { quiet?: boolean } ) => {
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
													{ requiredPlugins && requiredPlugins.length > 1
														? __( 'Required plugins', 'newspack-plugin' )
														: __( 'Required plugin', 'newspack-plugin' ) }
												</h2>
											</div>
										</div>
									</div>
								</div>
							) }
							<div className="newspack-wizard newspack-wizard__content">
								<PluginInstaller plugins={ requiredPlugins || [] } onStatus={ status => this.pluginInstallationStatus( status ) } />
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
		 * @param options Options for the confirmation modal.
		 */
		confirmAction = ( options: WizardConfirmationOptions ) => {
			const modalOptions: WizardConfirmationOptions = {
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
		 * @return The confirmation modal.
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
						<WrappedComponentWithInjectedProps
							confirmAction={ this.confirmAction }
							pluginRequirements={ requiredPlugins && this.pluginRequirements() }
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
