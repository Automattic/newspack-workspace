/**
 * Settings Wizard: Plugin Card component.
 */

/**
 * WordPress dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, hooks, Waiting } from '../../packages/components/src';
import WizardsActionCard from './wizards-action-card';
import { useWizardApiFetch } from './hooks/use-wizard-api-fetch';

/**
 * Helper for managing plugins API requests.
 *
 * @param slug      Plugin slug to fetch
 * @param action    Endpoint action to request
 * @param apiFetch  Wizard API Fetch instance
 * @param callbacks Wizard API Fetch callbacks
 * @return Wizard API Fetch response
 */
function fetchHandler( slug: string, action = '', apiFetch: WizardApiFetch< PluginResponse >, callbacks?: ApiFetchCallbacks< PluginResponse > ) {
	const path = action ? `/newspack/v1/plugins/${ slug }/${ action }` : `/newspack/v1/plugins/${ slug }`;
	const method = action ? 'POST' : 'GET';
	return apiFetch( { path, method }, callbacks );
}

/**
 * Wizard Plugin Action Card component.
 *
 * @param props                Component props.
 * @param props.isLoading      Whether the plugin is performing an API request.
 * @param props.isSetup        Whether the plugin is install, active and configured.
 * @param props.isActive       Whether the plugin is active.
 * @param props.isInstalled    Whether the plugin is installed.
 * @param props.isConfigurable Whether the plugin is configurable.
 * @param props.onActivate     Callback to activate the plugin.
 * @param props.onInstall      Callback to install the plugin.
 * @param props.onConfigure    Callback to configure the plugin.
 * @param props.status         Plugin status.
 * @param props.title          Plugin title.
 * @param props.editLink       Plugin edit link.
 * @param props.actionText     Plugin action texts.
 */
function WizardsPluginCardButton( {
	isLoading,
	isSetup,
	isActive,
	isInstalled,
	isConfigurable,
	onActivate,
	onInstall,
	onConfigure,
	actionText = {},
	...plugin
}: {
	status: string;
	title: string;
	editLink?: string;
	isLoading: boolean;
	isSetup: boolean;
	isActive: boolean;
	isConfigurable?: boolean;
	isInstalled: boolean;
	onActivate: () => void;
	onInstall: () => void;
	onConfigure: () => void;
	actionText?: PluginCardActionText;
} ) {
	if ( plugin.status === 'page-reload' ) {
		return <span className="gray">{ __( 'Page reloading…', 'newspack-plugin' ) }</span>;
	}
	if ( plugin.status === 'page-redirect' ) {
		return <span className="gray">{ __( 'Page redirecting…', 'newspack-plugin' ) }</span>;
	}
	if ( isLoading ) {
		return <Waiting />;
	}
	if ( ! isInstalled ) {
		return (
			<Button isLink onClick={ onInstall }>
				{ actionText.install ??
					sprintf(
						/* translators: %s: Plugin name */
						__( 'Install %s', 'newspack-plugin' ),
						plugin.title
					) }
			</Button>
		);
	}
	if ( ! isActive ) {
		return (
			<Button isLink onClick={ onActivate }>
				{ actionText.activate ??
					sprintf(
						/* translators: %s: Plugin name */
						__( 'Activate %s', 'newspack-plugin' ),
						plugin.title
					) }
			</Button>
		);
	}
	if ( ! isSetup ) {
		if ( isConfigurable ) {
			return (
				<Button isLink onClick={ onConfigure }>
					{ actionText.configure ??
						sprintf(
							/* translators: %s: Plugin name */
							__( 'Configure %s', 'newspack-plugin' ),
							plugin.title
						) }
				</Button>
			);
		}
		if ( plugin.editLink ) {
			return <a href={ plugin.editLink }>{ actionText.complete ?? __( 'Complete Setup', 'newspack-plugin' ) }</a>;
		}
	}
	if ( plugin.editLink ) {
		return <a href={ plugin.editLink }>{ actionText.configure ?? __( 'Configure', 'newspack-plugin' ) }</a>;
	}
	return null;
}

/**
 * Wizard Plugin Card component.
 *
 * @param props                    Component props.
 * @param props.slug               Plugin slug.
 * @param props.title              Plugin title.
 * @param props.subTitle           Plugin subtitle. String appended to title.
 * @param props.editLink           Plugin edit link.
 * @param props.description        Plugin description.
 * @param props.onStatusChange     Callback invoked when the plugin status changes.
 * @param props.reloadOnActivation Should the page reload on activation status change?
 * @param props.isStatusPrepended  Should status be prepended to description.
 * @param props.isConfigurable     Whether the plugin is configurable.
 * @param props.isTogglable        Whether the plugin is togglable.
 * @param props.actionText         Action card action text.
 * @param props.statusDescription  Plugin status description.
 */
function WizardsPluginCard( {
	slug,
	title,
	subTitle,
	editLink,
	description,
	statusDescription,
	onStatusChange = () => {},
	reloadOnActivation = true,
	isStatusPrepended = true,
	isConfigurable,
	isTogglable,
	actionText = {},
	...props
}: PluginCard ) {
	const { wizardApiFetch, errorMessage, isFetching } = useWizardApiFetch( `/newspack/wizards/plugins/${ slug }` );
	const [ pluginState, setPluginState ] = hooks.useObjectState( {
		slug,
		status: '',
		statusDescription,
		configured: false,
	} );

	const statuses = {
		isSetup: pluginState.status === 'active' && pluginState.configured,
		isActive: pluginState.status === 'active',
		isLoading: ! pluginState.status,
		isInstalled: pluginState.status !== 'uninstalled',
		isConfigured: pluginState.configured,
		isError: Boolean( errorMessage ),
	};

	const on: PluginCallbacks = {
		init: fetchCallbacks => fetchHandler( pluginState.slug, undefined, wizardApiFetch, fetchCallbacks ),
		activate: fetchCallbacks => fetchHandler( pluginState.slug, 'activate', wizardApiFetch, fetchCallbacks ),
		deactivate: fetchCallbacks => fetchHandler( pluginState.slug, 'deactivate', wizardApiFetch, fetchCallbacks ),
		install: fetchCallbacks => fetchHandler( pluginState.slug, 'install', wizardApiFetch, fetchCallbacks ),
		configure: fetchCallbacks => fetchHandler( pluginState.slug, 'configure', wizardApiFetch, fetchCallbacks ),
	};

	/**
	 * Set plugin state.
	 *
	 * @param callbacksKey Callback key to dictate action to perform.
	 */
	function setPluginAction( callbacksKey: keyof PluginCallbacks ) {
		// If action is activating or deactivating.
		const actions = reloadOnActivation ? [ 'activate', 'deactivate' ] : [ 'deactivate' ];
		const isPluginStateUpdate = actions.includes( callbacksKey );
		setPluginState( { status: '' } );
		on[ callbacksKey ]( {
			onSuccess( update ) {
				let statusUpdate = update.Status;
				if ( isPluginStateUpdate ) {
					statusUpdate = 'page-reload';
				}
				setPluginState( {
					status: statusUpdate,
					configured: update.Configured,
				} );
			},
			onFinally() {
				if ( isPluginStateUpdate && ! errorMessage ) {
					window.location.reload();
				}
				if ( editLink && callbacksKey === 'configure' ) {
					window.location.href = editLink;
					setPluginState( { status: 'page-redirect' } );
				}
			},
		} );
	}

	useEffect( () => {
		setPluginAction( 'init' );
	}, [] );

	useEffect( () => {
		onStatusChange( statuses );
	}, [ statuses ] );

	function onActivate() {
		setPluginAction( 'activate' );
	}

	function onDeactivate() {
		setPluginAction( 'deactivate' );
	}

	function getDescription() {
		if ( statuses.isError ) {
			return __( 'Status: Error!', 'newspack-plugin' );
		}
		if ( statuses.isLoading ) {
			return __( 'Loading…', 'newspack-plugin' );
		}
		const descriptionAppend = description ?? '';
		let newDescription = '';
		if ( ! statuses.isInstalled ) {
			newDescription = pluginState.statusDescription?.uninstalled ?? __( 'Uninstalled.', 'newspack-plugin' );
		} else if ( ! statuses.isActive ) {
			newDescription = pluginState.statusDescription?.inactive ?? __( 'Inactive.', 'newspack-plugin' );
		} else if ( ! statuses.isConfigured ) {
			newDescription = pluginState.statusDescription?.notConfigured ?? __( 'Not connected.', 'newspack-plugin' );
		} else {
			newDescription = pluginState.statusDescription?.connected ?? __( 'Connected.', 'newspack-plugin' );
		}
		return (
			<>
				{ isStatusPrepended &&
					sprintf(
						// Translators: %s: Plugin status
						__( 'Status: %s', 'newspack-plugin' ),
						newDescription
					) }{ ' ' }
				{ descriptionAppend }
			</>
		);
	}

	const conditionalProps: Partial< PluginCard > = {};

	// Add toggle specific props if the card is togglable.
	if ( isTogglable ) {
		conditionalProps.toggleChecked = statuses.isActive;
		conditionalProps.toggleOnChange = () => ( ! statuses.isActive ? onActivate() : onDeactivate() );
		conditionalProps.disabled = isFetching;
	}

	return (
		<WizardsActionCard
			title={ `${ title }${ subTitle ? `: ${ subTitle }` : '' }` }
			description={ getDescription }
			className={ `wizards-plugin-card ${ slug }` }
			actionText={
				! statuses.isError ? (
					<WizardsPluginCardButton
						{ ...{
							title,
							editLink,
							onActivate,
							actionText,
							isConfigurable,
							onInstall: () => setPluginAction( 'activate' ),
							onConfigure: () => setPluginAction( 'configure' ),
							...statuses,
							...pluginState,
						} }
					/>
				) : null
			}
			isChecked={ statuses.isSetup }
			error={ props.error ?? errorMessage }
			{ ...props }
			{ ...conditionalProps }
		/>
	);
}

export default WizardsPluginCard;
