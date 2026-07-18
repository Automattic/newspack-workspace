/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { forwardRef, useState, useEffect, useCallback } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { SettingsSection } from './settings-section';
import { ConfigureView } from './configure-view';
import { LogsView } from './logs-view';

const API_PATH = '/newspack/v1/wizard/newspack-audience-integrations/settings';

// Minimum time the Activate action stays busy, even when the request is faster.
const MIN_ACTIVATION_BUSY_MS = 2000;

const INTEGRATIONS_BREADCRUMBS = [
	{ label: __( 'Audience Management', 'newspack-plugin' ) },
	{ label: __( 'Integrations', 'newspack-plugin' ), url: '#/settings' },
];

const AudienceIntegrations = ( props, ref ) => {
	const [ integrations, setIntegrations ] = useState( {} );
	const [ inFlightChanges, setInFlightChanges ] = useState( {} );
	const [ saving, setSaving ] = useState( {} );
	const [ toggling, setToggling ] = useState( {} );
	const [ activating, setActivating ] = useState( {} );
	const [ loading, setLoading ] = useState( true );

	const { addNotice, removeNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	const addEnabledNotice = useCallback(
		( integrationId, enabled, data ) => {
			const name = data?.[ integrationId ]?.name || integrationId;
			addNotice( {
				id: `integration-enabled-${ integrationId }`,
				type: 'success',
				message: enabled
					? sprintf( /* translators: %s: integration name. */ __( '%s enabled.', 'newspack-plugin' ), name )
					: sprintf( /* translators: %s: integration name. */ __( '%s disabled.', 'newspack-plugin' ), name ),
			} );
		},
		[ addNotice ]
	);

	// showLoading swaps the whole card grid for a "Loading…" line, which is right
	// on first mount and wrong on a refetch — the cards are already on screen and
	// the card carries its own busy state.
	const fetchSettings = useCallback( ( { showLoading = true } = {} ) => {
		if ( showLoading ) {
			setLoading( true );
		}
		return apiFetch( { path: API_PATH } )
			.then( data => {
				setIntegrations( data );
				setInFlightChanges( {} );
			} )
			.finally( () => {
				if ( showLoading ) {
					setLoading( false );
				}
			} );
	}, [] );

	useEffect( () => {
		fetchSettings();
	}, [ fetchSettings ] );

	const handleSave = useCallback(
		( integrationId, changes ) => {
			if ( ! changes || Object.keys( changes ).length === 0 ) {
				return Promise.resolve();
			}
			setInFlightChanges( prev => ( { ...prev, [ integrationId ]: changes } ) );
			setSaving( prev => ( { ...prev, [ integrationId ]: true } ) );
			// Drop the previous attempt's snackbar so a retry doesn't stack a second
			// notice under the same id.
			removeNotice( `integration-saved-${ integrationId }` );
			return apiFetch( {
				path: `${ API_PATH }/${ integrationId }`,
				method: 'POST',
				data: { settings: changes },
			} )
				.then( data => {
					setIntegrations( data );
					setInFlightChanges( prev => {
						const next = { ...prev };
						delete next[ integrationId ];
						return next;
					} );
					addNotice( {
						id: `integration-saved-${ integrationId }`,
						type: 'success',
						message: __( 'Settings saved.', 'newspack-plugin' ),
					} );
				} )
				.catch( error => {
					// Retain inFlightChanges[ integrationId ] as the recovery copy —
					// the server never received the edit. apiFetch already logged the
					// error. Rethrow so ConfigureView keeps its local draft.
					addNotice( {
						id: `integration-saved-${ integrationId }`,
						type: 'error',
						message: __( 'Something went wrong. Please try again.', 'newspack-plugin' ),
					} );
					throw error;
				} )
				.finally( () => {
					setSaving( prev => ( { ...prev, [ integrationId ]: false } ) );
				} );
		},
		[ addNotice, removeNotice ]
	);

	const handleToggleEnabled = useCallback(
		( integrationId, enabled ) => {
			setToggling( prev => ( { ...prev, [ integrationId ]: true } ) );
			apiFetch( {
				path: `${ API_PATH }/${ integrationId }/enabled`,
				method: 'POST',
				data: { enabled },
			} )
				.then( data => {
					setIntegrations( data );
					addEnabledNotice( integrationId, enabled, data );
				} )
				.catch( () => {
					// Leave the integration in its previous state; apiFetch already
					// logs the underlying error to the console and the user can retry.
					addNotice( {
						id: `integration-enabled-${ integrationId }`,
						type: 'error',
						message: __( 'Something went wrong. Please try again.', 'newspack-plugin' ),
					} );
				} )
				.finally( () => {
					setToggling( prev => ( { ...prev, [ integrationId ]: false } ) );
				} );
		},
		[ addEnabledNotice, addNotice ]
	);

	const handleSetupAndEnable = useCallback(
		( integrationId, settings ) =>
			apiFetch( {
				path: `${ API_PATH }/${ integrationId }`,
				method: 'POST',
				data: { settings },
			} ).then( () => {
				// Drop only the just-saved keys from the retry buffer, so an unrelated
				// pending edit survives but a stale one can't later overwrite the server.
				setInFlightChanges( prev => {
					const buffered = prev[ integrationId ];
					if ( ! buffered ) {
						return prev;
					}
					const remaining = { ...buffered };
					Object.keys( settings ).forEach( key => delete remaining[ key ] );
					const next = { ...prev };
					if ( Object.keys( remaining ).length ) {
						next[ integrationId ] = remaining;
					} else {
						delete next[ integrationId ];
					}
					return next;
				} );
				return apiFetch( {
					path: `${ API_PATH }/${ integrationId }/enabled`,
					method: 'POST',
					data: { enabled: true },
				} ).then( data => {
					// Swap in state only once both steps succeed, so an enable failure
					// keeps the modal's filled fields for a retry.
					setIntegrations( data );
					addEnabledNotice( integrationId, true, data );
					return data;
				} );
			} ),
		[ addEnabledNotice ]
	);

	const handleDiscardChanges = useCallback( integrationId => {
		setInFlightChanges( prev => {
			if ( ! prev[ integrationId ] ) {
				return prev;
			}
			const next = { ...prev };
			delete next[ integrationId ];
			return next;
		} );
	}, [] );

	const handleActivatePlugin = useCallback(
		pluginSlugs => {
			const slugs = ( Array.isArray( pluginSlugs ) ? pluginSlugs : [ pluginSlugs ] ).filter( Boolean );
			if ( ! slugs.length ) {
				return;
			}
			// Set-as-guard: filter out slugs already in flight, then dispatch the
			// activation only for the newly-claimed ones. Using the state setter
			// callback gives us an atomic check-and-claim against concurrent clicks.
			setActivating( prev => {
				const claimed = slugs.filter( slug => ! prev[ slug ] );
				if ( ! claimed.length ) {
					return prev;
				}
				Promise.all( [
					Promise.all(
						claimed.map( slug =>
							apiFetch( {
								path: `/newspack/v1/plugins/${ slug }/activate`,
								method: 'POST',
							} )
						)
					),
					// Hold the busy state for a beat even when activation is
					// near-instant, so the user sees that something happened.
					new Promise( resolve => setTimeout( resolve, MIN_ACTIVATION_BUSY_MS ) ),
				] )
					.then( () => fetchSettings( { showLoading: false } ) )
					.catch( () => {
						// Surface nothing here; failures leave the integration in its
						// previous state and the user can retry. apiFetch already logs
						// the underlying error to the console.
					} )
					.finally( () => {
						setActivating( current => {
							const next = { ...current };
							claimed.forEach( slug => {
								delete next[ slug ];
							} );
							return next;
						} );
					} );
				const next = { ...prev };
				claimed.forEach( slug => {
					next[ slug ] = true;
				} );
				return next;
			} );
		},
		[ fetchSettings ]
	);

	const sharedProps = {
		integrations,
		inFlightChanges,
		saving,
		toggling,
		activating,
		loading,
		onSave: handleSave,
		onDiscardChanges: handleDiscardChanges,
		onToggleEnabled: handleToggleEnabled,
		onActivatePlugin: handleActivatePlugin,
		onSetupAndEnable: handleSetupAndEnable,
	};

	return (
		<Wizard
			headerText={ __( 'Audience Management / Integrations', 'newspack-plugin' ) }
			sections={ [
				{
					path: '/settings',
					exact: true,
					render: SettingsSection,
					props: sharedProps,
					breadcrumbs: INTEGRATIONS_BREADCRUMBS,
				},
				{
					path: '/settings/:integrationId/logs',
					render: LogsView,
					props: sharedProps,
					isHidden: true,
					fullWidth: true,
					breadcrumbs: INTEGRATIONS_BREADCRUMBS,
				},
				{
					path: '/settings/:integrationId',
					render: ConfigureView,
					props: sharedProps,
					backNav: '#/settings',
					isHidden: true,
					breadcrumbs: INTEGRATIONS_BREADCRUMBS,
				},
			] }
			ref={ ref }
		/>
	);
};

export default withWizard( forwardRef( AudienceIntegrations ) );
