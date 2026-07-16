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
	const [ pendingChanges, setPendingChanges ] = useState( {} );
	const [ saving, setSaving ] = useState( {} );
	const [ toggling, setToggling ] = useState( {} );
	const [ activating, setActivating ] = useState( {} );
	const [ loading, setLoading ] = useState( true );

	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

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
				setPendingChanges( {} );
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

	const handleFieldChange = useCallback( ( integrationId, fieldKey, value ) => {
		setPendingChanges( prev => ( {
			...prev,
			[ integrationId ]: {
				...( prev[ integrationId ] || {} ),
				[ fieldKey ]: value,
			},
		} ) );
	}, [] );

	const handleDiscardChanges = useCallback( integrationId => {
		setPendingChanges( prev => {
			if ( ! prev[ integrationId ] ) {
				return prev;
			}
			const next = { ...prev };
			delete next[ integrationId ];
			return next;
		} );
	}, [] );

	const handleSave = useCallback(
		integrationId => {
			setPendingChanges( currentPendingChanges => {
				const changes = currentPendingChanges[ integrationId ];
				if ( ! changes || Object.keys( changes ).length === 0 ) {
					return currentPendingChanges;
				}
				setSaving( prev => ( { ...prev, [ integrationId ]: true } ) );
				apiFetch( {
					path: `${ API_PATH }/${ integrationId }`,
					method: 'POST',
					data: { settings: changes },
				} )
					.then( data => {
						setIntegrations( data );
						setPendingChanges( prev => {
							const next = { ...prev };
							delete next[ integrationId ];
							return next;
						} );
					} )
					.catch( () => {
						// Leave pendingChanges untouched; the server never received the
						// edit, so it's the user's only copy. apiFetch already logs the
						// underlying error to the console and the user can retry.
						addNotice( {
							id: `integration-saved-${ integrationId }`,
							type: 'error',
							message: __( 'Something went wrong. Please try again.', 'newspack-plugin' ),
						} );
					} )
					.finally( () => {
						setSaving( prev => ( { ...prev, [ integrationId ]: false } ) );
					} );
				return currentPendingChanges;
			} );
		},
		[ addNotice ]
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
			} ).then( savedData =>
				apiFetch( {
					path: `${ API_PATH }/${ integrationId }/enabled`,
					method: 'POST',
					data: { enabled: true },
				} )
					// The settings save succeeded even if enabling failed — reflect
					// the saved values so the UI doesn't offer the modal again for
					// settings that are already stored.
					.catch( error => {
						setIntegrations( savedData );
						throw error;
					} )
					.then( data => {
						setIntegrations( data );
						addEnabledNotice( integrationId, true, data );
						return data;
					} )
					.finally( () => {
						setPendingChanges( prev => {
							const next = { ...prev };
							delete next[ integrationId ];
							return next;
						} );
					} )
			),
		[ addEnabledNotice ]
	);

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
		pendingChanges,
		saving,
		toggling,
		activating,
		loading,
		onFieldChange: handleFieldChange,
		onDiscardChanges: handleDiscardChanges,
		onSave: handleSave,
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
