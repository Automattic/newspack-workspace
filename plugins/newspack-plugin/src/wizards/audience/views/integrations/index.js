/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { forwardRef, useState, useEffect, useCallback } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import { SettingsSection } from './settings-section';
import { ConfigureView } from './configure-view';
import { LogsView } from './logs-view';

const API_PATH = '/newspack/v1/wizard/newspack-audience-integrations/settings';

const AudienceIntegrations = ( props, ref ) => {
	const [ integrations, setIntegrations ] = useState( {} );
	const [ pendingChanges, setPendingChanges ] = useState( {} );
	const [ saving, setSaving ] = useState( {} );
	const [ toggling, setToggling ] = useState( {} );
	const [ activating, setActivating ] = useState( {} );
	const [ loading, setLoading ] = useState( true );

	const fetchSettings = useCallback( () => {
		setLoading( true );
		apiFetch( { path: API_PATH } )
			.then( data => {
				setIntegrations( data );
				setPendingChanges( {} );
			} )
			.finally( () => setLoading( false ) );
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

	const handleSave = useCallback( integrationId => {
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
				.finally( () => {
					setSaving( prev => ( { ...prev, [ integrationId ]: false } ) );
				} );
			return currentPendingChanges;
		} );
	}, [] );

	const handleToggleEnabled = useCallback( ( integrationId, enabled ) => {
		setToggling( prev => ( { ...prev, [ integrationId ]: true } ) );
		apiFetch( {
			path: `${ API_PATH }/${ integrationId }/enabled`,
			method: 'POST',
			data: { enabled },
		} )
			.then( data => {
				setIntegrations( data );
			} )
			.finally( () => {
				setToggling( prev => ( { ...prev, [ integrationId ]: false } ) );
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
				Promise.all(
					claimed.map( slug =>
						apiFetch( {
							path: `/newspack/v1/plugins/${ slug }/activate`,
							method: 'POST',
						} )
					)
				)
					.then( () => fetchSettings() )
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
		onSave: handleSave,
		onToggleEnabled: handleToggleEnabled,
		onActivatePlugin: handleActivatePlugin,
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
				},
				{
					path: '/settings/:integrationId/logs',
					render: LogsView,
					props: sharedProps,
					isHidden: true,
					fullWidth: true,
				},
				{
					path: '/settings/:integrationId',
					render: ConfigureView,
					props: sharedProps,
					backNav: '#/settings',
					isHidden: true,
				},
			] }
			ref={ ref }
		/>
	);
};

export default withWizard( forwardRef( AudienceIntegrations ) );
