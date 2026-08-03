/* global newspack_newsletters_wizard */
/**
 * External dependencies
 */
import values from 'lodash/values';
import mapValues from 'lodash/mapValues';
import property from 'lodash/property';
import isEmpty from 'lodash/isEmpty';
import once from 'lodash/once';

/**
 * WordPress dependencies
 */
import { Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __ } from '@wordpress/i18n';
import {
	ExternalLink,
	Notice as WpNotice,
	ToggleControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { atSymbol } from '@wordpress/icons';

// Wizard-bridge event contract. The newsletters bridge bundle exposes its
// event names on `window.newspackNewslettersEvents`; we fall back to the
// hand-rolled mirror so this file works in isolation (tests, partial loads)
// and so the two repos stay in sync without coordinated edits once the
// bridge ships the global.
const NN_EVENT_NAMESPACE = 'newspack-newsletters';
const NN_FALLBACK_EVENTS = {
	BRIDGE_MOUNTED: `${ NN_EVENT_NAMESPACE }:bridge-mounted`,
	OPEN_MODAL: `${ NN_EVENT_NAMESPACE }:open-local-list-modal`,
	OPEN_CONFIRM_DELETE: `${ NN_EVENT_NAMESPACE }:open-local-list-confirm-delete`,
	LOCAL_LIST_SAVED: `${ NN_EVENT_NAMESPACE }:local-list-saved`,
	LOCAL_LIST_DELETED: `${ NN_EVENT_NAMESPACE }:local-list-deleted`,
};
const getNNEvents = () => ( typeof window !== 'undefined' && window.newspackNewslettersEvents ) || NN_FALLBACK_EVENTS;
const NN_FALLBACK_TIMEOUT_MS = 500;

// Read the bridge-readiness flag synchronously rather than relying on a
// one-shot `BRIDGE_MOUNTED` event. The bridge sets the flag before
// dispatching, so listeners that register late still observe a ready
// bridge — avoiding a spurious fallback redirect.
const isBridgeReady = () => typeof window !== 'undefined' && window.newspackNewslettersBridgeReady === true;

/**
 * Internal dependencies
 */
import {
	Badge,
	Button,
	Card,
	CardSettingsGroup,
	Divider,
	Grid,
	PluginInstaller,
	SectionHeader,
	SelectControl,
	TextControl,
	Waiting,
	hooks,
	integrationIcons,
	useUnsavedChangesDialog,
} from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import Tracking from './tracking';

import './style.scss';

const LETTERHEAD_KEY = 'newspack_newsletters_letterhead_api_key';

// Signature over a provider's settings (keys sourced from the settings
// metadata), so any credential change — key, URL, secret — is detected.
const providerSettingsSignature = ( config, settings, provider ) =>
	Object.values( settings || {} )
		.filter( setting => setting?.provider && setting.provider === provider )
		.map( setting => setting.key )
		.sort()
		.map( key => config?.[ key ] ?? '' )
		.join( '|' );

export const Settings = ( {
	onUpdate,
	onConfigured,
	onLabels,
	onLetterheadSetting,
	newslettersConfig,
	savedProvider = '',
	espConnected = false,
	onEspConnected = () => {},
	isOnboarding = true,
	authUrl = false,
	isSaving = false,
	provider,
	setProvider = () => {},
	setAuthUrl = () => {},
	setLockedLists = () => {},
} ) => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( false );
	const [ config, updateConfig ] = hooks.useObjectState( {} );
	// Combine the local fetch/verify in-flight state with the parent's save
	// state so all editing controls are disabled while the outer Save is
	// in flight — prevents a race where a user changes the ESP between
	// "Save click" and "POST resolve".
	const isDisabled = inFlight || isSaving;
	// Handle provider updates.
	useEffect( () => {
		const newProvider = newslettersConfig?.newspack_newsletters_service_provider || '';
		if ( provider !== newProvider ) {
			setError( false );
			setProvider( newProvider );
		}
		// Unlock only the saved, connected provider (initial load or switch-back).
		// Kept outside the provider-change guard so a late `espConnected` still applies.
		const isSavedProvider = ! provider || newProvider === savedProvider;
		setLockedLists( ! ( newProvider && isSavedProvider && espConnected ) );
	}, [ newslettersConfig?.newspack_newsletters_service_provider, provider, savedProvider, espConnected ] );
	// Verify token for OAuth providers.
	useEffect( () => {
		verifyToken( newslettersConfig?.newspack_newsletters_service_provider );
	}, [ newslettersConfig?.newspack_newsletters_service_provider ] );

	const verifyToken = serviceProvider => {
		setAuthUrl( false );
		if ( ! serviceProvider ) {
			return;
		}
		// Constant Contact is the only provider using an OAuth strategy.
		if ( 'constant_contact' !== serviceProvider ) {
			return;
		}
		setInFlight( true );
		apiFetch( { path: `/newspack-newsletters/v1/${ serviceProvider }/verify_token` } )
			.then( response => {
				if ( ! response.valid && response.auth_url ) {
					setAuthUrl( response.auth_url );
				} else {
					setAuthUrl( false );
				}
			} )
			.catch( () => {
				setAuthUrl( false );
			} )
			.finally( () => {
				setInFlight( false );
			} );
	};

	const performConfigUpdate = update => {
		updateConfig( update );
		if ( onUpdate ) {
			onUpdate( mapValues( update.settings, property( 'value' ) ) );
		}
	};
	const fetchConfiguration = () => {
		setError( false );
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-newsletters/settings',
		} )
			.then( response => {
				performConfigUpdate( response );
				if ( onConfigured ) {
					onConfigured( response?.configured === true );
				}
				onEspConnected( response?.esp_connected === true );
				if ( onLabels && response?.labels ) {
					onLabels( response.labels );
				}
				if ( onLetterheadSetting && response?.settings?.[ LETTERHEAD_KEY ] ) {
					onLetterheadSetting( response.settings[ LETTERHEAD_KEY ] );
				}
			} )
			.catch( setError );
	};
	const getSelectedProviderName = () => {
		const configItem = config.settings.newspack_newsletters_service_provider;
		const value = configItem?.value;
		return configItem?.options?.find( option => option.value === value )?.name;
	};
	const handleAuth = () => {
		if ( authUrl ) {
			const authWindow = window.open( authUrl, 'esp_oauth', 'width=500,height=600' );
			authWindow.opener = {
				verify: once( () => {
					window.location.reload();
				} ),
			};
		}
	};
	const saveNewslettersData = async () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-newsletters/settings',
			method: 'POST',
			data: newslettersConfig,
		} ).finally( () => {
			setProvider( newslettersConfig?.newspack_newsletters_service_provider );
			verifyToken( newslettersConfig?.newspack_newsletters_service_provider );
			setLockedLists( false );
			setInFlight( false );
		} );
	};
	useEffect( fetchConfiguration, [] );
	const getSettingProps = key => ( {
		disabled: isDisabled,
		value: config.settings[ key ]?.value || '',
		checked: Boolean( config.settings[ key ]?.value ),
		label: config.settings[ key ]?.description,
		placeholder: config.settings[ key ]?.placeholder,
		options:
			config.settings[ key ]?.options?.map( option => ( {
				value: option.value,
				label: option.name,
			} ) ) || null,
		onChange: value => performConfigUpdate( { settings: { [ key ]: { value } } } ),
	} );

	const providerSelectProps = config.settings ? getSettingProps( 'newspack_newsletters_service_provider' ) : null;

	const ESP_PROVIDER_KEY = 'newspack_newsletters_service_provider';

	const isESPSetting = setting => setting.key === ESP_PROVIDER_KEY || !! setting.provider;
	const isPostSetting = setting => ! setting.provider && setting.key !== ESP_PROVIDER_KEY && setting.key !== LETTERHEAD_KEY;

	const renderSettingControl = setting => {
		if ( isOnboarding && ! setting.onboarding ) {
			return null;
		}
		switch ( setting.type ) {
			case 'select':
				return <SelectControl key={ setting.key } { ...getSettingProps( setting.key ) } />;
			case 'checkbox': {
				const props = getSettingProps( setting.key );
				return (
					<ToggleControl
						key={ setting.key }
						label={ props.label }
						checked={ props.checked }
						onChange={ props.onChange }
						disabled={ props.disabled }
						__nextHasNoMarginBottom
					/>
				);
			}
			default:
				return (
					<VStack key={ setting.key } spacing={ 2 }>
						<TextControl { ...getSettingProps( setting.key ) } withMargin={ false } />
						{ setting.help && setting.helpURL && (
							<p style={ { margin: 0 } }>
								<ExternalLink href={ setting.helpURL }>{ setting.help }</ExternalLink>
							</p>
						) }
					</VStack>
				);
		}
	};

	const espSettings = values( config.settings ).filter(
		setting => isESPSetting( setting ) && ( ! setting.provider || setting.provider === providerSelectProps?.value )
	);
	const postSettings = values( config.settings ).filter( isPostSetting );

	const PROVIDER_ORDER = [ 'active_campaign', 'mailchimp', 'constant_contact', 'manual' ];
	const PROVIDER_ICONS = {
		active_campaign: integrationIcons.activeCampaign,
		mailchimp: integrationIcons.mailchimp,
		constant_contact: integrationIcons.constantContact,
		manual: atSymbol,
	};
	const providerOptions = ( config.settings?.newspack_newsletters_service_provider?.options || [] )
		.filter( opt => opt.value !== '' )
		.sort( ( a, b ) => {
			const aIdx = PROVIDER_ORDER.indexOf( a.value );
			const bIdx = PROVIDER_ORDER.indexOf( b.value );
			if ( aIdx === -1 && bIdx === -1 ) {
				return 0;
			}
			if ( aIdx === -1 ) {
				return 1;
			}
			if ( bIdx === -1 ) {
				return -1;
			}
			return aIdx - bIdx;
		} );
	const selectedProviderValue = providerSelectProps?.value;

	const renderAuthorizeBlock = () =>
		false !== authUrl && (
			<Card isSmall>
				<h3>{ __( 'Authorize Application', 'newspack-plugin' ) }</h3>
				<p>
					{ sprintf(
						// translators: %s is the name of the ESP.
						__( 'Authorize %s to connect to Newspack.', 'newspack-plugin' ),
						getSelectedProviderName()
					) }
				</p>
				<Button isSecondary onClick={ handleAuth }>
					{ __( 'Authorize', 'newspack-plugin' ) }
				</Button>
			</Card>
		);

	const renderProviderControls = () => (
		<VStack spacing={ 6 } className="newspack-newsletters-settings-stack">
			{ error && (
				<WpNotice status="error" isDismissible={ false }>
					{ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) }
				</WpNotice>
			) }
			{ 'campaign_monitor' === selectedProviderValue && (
				<WpNotice status="warning" isDismissible={ false }>
					<h2>{ __( 'Campaign Monitor support will be deprecated', 'newspack-plugin' ) }</h2>
					<p>{ __( 'Please connect a different service provider to ensure continued support.', 'newspack-plugin' ) }</p>
				</WpNotice>
			) }
			{ isOnboarding ? (
				values( config.settings )
					.filter( setting => ! setting.provider || setting.provider === selectedProviderValue )
					.map( renderSettingControl )
			) : (
				<>
					<Grid columns={ 2 } gutter={ 16 } noMargin>
						{ providerOptions.map( option => (
							<CardSettingsGroup
								key={ option.value }
								className={ `newspack-newsletters-esp-card newspack-newsletters-esp-card--${ option.value.replace( /_/g, '-' ) }` }
								disabled={ isDisabled }
								icon={ PROVIDER_ICONS[ option.value ] }
								title={ option.name }
								isActive={ option.value === selectedProviderValue }
								onEnable={ () => providerSelectProps.onChange( option.value ) }
								onHeaderClick={ () => providerSelectProps.onChange( option.value ) }
							/>
						) ) }
					</Grid>
					{ selectedProviderValue && (
						<VStack spacing={ 4 } className="newspack-newsletters-settings-stack">
							{ selectedProviderValue === 'constant_contact' && renderAuthorizeBlock() }
							{ espSettings.filter( s => s.provider === selectedProviderValue ).map( renderSettingControl ) }
						</VStack>
					) }
				</>
			) }
			{ isOnboarding && (
				<HStack justify="flex-start" expanded={ false }>
					<Button disabled={ isDisabled } variant="primary" onClick={ saveNewslettersData }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			) }
		</VStack>
	);

	if ( ! error && isEmpty( config ) ) {
		return (
			<div className="flex justify-around mt4">
				<Waiting />
			</div>
		);
	}

	return (
		<>
			{ config.configured === false && (
				<PluginInstaller
					plugins={ [ 'newspack-newsletters' ] }
					withoutFooterButton
					onStatus={ ( { complete } ) => complete && fetchConfiguration() }
				/>
			) }
			{ config.configured === true &&
				( isOnboarding ? (
					renderProviderControls()
				) : (
					<>
						<Grid columns={ 2 } gutter={ 32 } noMargin>
							<SectionHeader
								heading={ 2 }
								title={ __( 'Email service provider', 'newspack-plugin' ) }
								description={ __( 'Connect an email service provider (ESP) to author and send newsletters.', 'newspack-plugin' ) }
								noMargin
							/>
							{ renderProviderControls() }
						</Grid>
						{ postSettings.length > 0 && (
							<>
								<Divider alignment="full-width" variant="tertiary" />
								<Grid columns={ 2 } gutter={ 32 } noMargin>
									<SectionHeader
										heading={ 2 }
										title={ __( 'Newsletter posts', 'newspack-plugin' ) }
										description={ __(
											'Settings for how published newsletters appear as posts on your site.',
											'newspack-plugin'
										) }
										noMargin
									/>
									<VStack spacing={ 4 } className="newspack-newsletters-settings-stack">
										{ postSettings.map( renderSettingControl ) }
									</VStack>
								</Grid>
							</>
						) }
					</>
				) ) }
		</>
	);
};

export const SubscriptionLists = ( { lockedLists, onUpdate, provider, labels = {}, reloadToken = 0 } ) => {
	const [ error, setError ] = useState( false );
	const [ inFlight, setInFlight ] = useState( false );
	const [ togglingIds, setTogglingIds ] = useState( () => new Set() );
	const [ lists, setLists ] = useState( [] );
	const fallbackTimerRef = useRef( null );
	// When the bridge isn't ready at click time, we queue the dispatch here
	// instead of firing it into the void. The bridge-mounted handler
	// (registered below) flushes this; the fallback timer navigates to the
	// legacy URL if the bridge never mounts.
	const pendingActionRef = useRef( null );
	// Exposed by the reload-listener effect so other readiness paths (the
	// fallback timer, the dispatchOrQueue happy-path) can force a re-resolve
	// of listener names when the bridge becomes ready but its mounted-event
	// rename means our `BRIDGE_MOUNTED` listener never fired.
	const reattachReloadListenersRef = useRef( null );

	const updateLists = updater => {
		setLists( prev => {
			const nextLists = typeof updater === 'function' ? updater( prev ) : updater;
			if ( typeof onUpdate === 'function' ) {
				onUpdate( nextLists );
			}
			return nextLists;
		} );
	};
	const fetchLists = () => {
		setError( false );
		setInFlight( true );
		apiFetch( {
			path: '/newspack-newsletters/v1/lists',
		} )
			.then( updateLists )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	const handleToggleActive = async ( list, next ) => {
		if ( ! list?.db_id ) {
			return;
		}
		const dbId = list.db_id;
		const previousActive = list.active;
		updateLists( prev => prev.map( row => ( row.db_id === dbId ? { ...row, active: next } : row ) ) );
		setTogglingIds( prev => {
			const updated = new Set( prev );
			updated.add( dbId );
			return updated;
		} );
		setError( false );
		try {
			const response = await apiFetch( {
				path: `/newspack-newsletters/v1/lists/${ dbId }`,
				method: 'PATCH',
				data: { active: next },
			} );
			updateLists( prev => prev.map( row => ( row.db_id === dbId ? { ...row, ...response } : row ) ) );
		} catch ( err ) {
			updateLists( prev => prev.map( row => ( row.db_id === dbId ? { ...row, active: previousActive } : row ) ) );
			// `rest_no_route` means the PATCH /lists/{id} endpoint isn't
			// registered yet — the newsletters plugin needs the NEWS-2168
			// changes. Surface a friendlier message than WordPress's
			// generic "No route was found...".
			if ( err?.code === 'rest_no_route' ) {
				setError( {
					message: __(
						'This action requires a newer version of Newspack Newsletters. Update the newsletters plugin and try again.',
						'newspack-plugin'
					),
				} );
			} else {
				setError( err );
			}
		} finally {
			setTogglingIds( prev => {
				const updated = new Set( prev );
				updated.delete( dbId );
				return updated;
			} );
		}
	};

	useEffect( () => {
		setError( false );
		if ( provider && ! lockedLists ) {
			setLists( [] );
			fetchLists();
		}
	}, [ provider, lockedLists, reloadToken ] );

	useEffect( () => {
		const reload = () => fetchLists();
		// Listen on both the fallback names and any names exposed on
		// `window.newspackNewslettersEvents` so this still fires if the
		// bridge ships a renamed event in a future version. Set
		// deduplicates when the two are identical (the case today).
		const collectNames = () => {
			const live = getNNEvents();
			return {
				saved: new Set( [ NN_FALLBACK_EVENTS.LOCAL_LIST_SAVED, live.LOCAL_LIST_SAVED ] ),
				deleted: new Set( [ NN_FALLBACK_EVENTS.LOCAL_LIST_DELETED, live.LOCAL_LIST_DELETED ] ),
			};
		};

		let { saved, deleted } = collectNames();
		const attach = () => {
			saved.forEach( name => document.addEventListener( name, reload ) );
			deleted.forEach( name => document.addEventListener( name, reload ) );
		};
		const detach = () => {
			saved.forEach( name => document.removeEventListener( name, reload ) );
			deleted.forEach( name => document.removeEventListener( name, reload ) );
		};

		attach();

		const reattachIfChanged = () => {
			const next = collectNames();
			const changed = [ ...next.saved ].some( n => ! saved.has( n ) ) || [ ...next.deleted ].some( n => ! deleted.has( n ) );
			if ( ! changed ) {
				return;
			}
			detach();
			saved = next.saved;
			deleted = next.deleted;
			attach();
		};
		// Expose to other readiness paths (fallback timer / immediate
		// dispatch) so they can also force a re-resolve when the bridge
		// becomes ready by a path other than our BRIDGE_MOUNTED listener.
		reattachReloadListenersRef.current = reattachIfChanged;

		// If the bridge wasn't ready at mount, re-resolve event names when
		// it announces itself. The bridge dispatches BRIDGE_MOUNTED using
		// whatever names it exposes, so listen on both the fallback name
		// and the live name.
		const bridgeMountedNames = new Set( [ NN_FALLBACK_EVENTS.BRIDGE_MOUNTED, getNNEvents().BRIDGE_MOUNTED ] );
		const onBridgeMounted = () => {
			// Flush any action queued while the bridge wasn't ready — the
			// original dispatch fired into the void, so replay it now that
			// listeners are guaranteed to be installed.
			const pending = pendingActionRef.current;
			if ( pending ) {
				pendingActionRef.current = null;
				clearTimeout( fallbackTimerRef.current );
				pending.dispatch();
			}
			reattachIfChanged();
		};
		bridgeMountedNames.forEach( name => document.addEventListener( name, onBridgeMounted ) );

		return () => {
			detach();
			bridgeMountedNames.forEach( name => document.removeEventListener( name, onBridgeMounted ) );
			reattachReloadListenersRef.current = null;
		};
	}, [] );

	// Clear the fallback timer on unmount — otherwise a scheduled redirect
	// would fire after the component is gone, navigating the user away
	// unexpectedly.
	useEffect( () => () => clearTimeout( fallbackTimerRef.current ), [] );

	// Dispatch a bridge event, or queue it for replay if the bridge isn't
	// ready yet. Dispatching while the bridge has no listeners installed
	// would silently drop the event — the queue + BRIDGE_MOUNTED flush
	// guarantees delivery, with a 500ms fallback to the legacy URL if the
	// bridge never shows up. The event KEY is stored (not the resolved
	// name) so a late-mounting bridge that exposes renamed events still
	// receives the correctly-named replay.
	const dispatchOrQueue = ( eventKey, detail, { fallbackUrl, onUnavailable } = {} ) => {
		const dispatch = () => document.dispatchEvent( new CustomEvent( getNNEvents()[ eventKey ], { detail } ) );
		if ( isBridgeReady() ) {
			// Any prior queued action from before the bridge was ready is
			// stale now — clear it so the armed fallback timer can't
			// double-dispatch it after this call.
			pendingActionRef.current = null;
			clearTimeout( fallbackTimerRef.current );
			// Bridge may have become ready via a renamed BRIDGE_MOUNTED we
			// didn't observe — make sure our reload listeners are on the
			// live names before the dispatch produces save/delete events.
			reattachReloadListenersRef.current?.();
			dispatch();
			return;
		}
		pendingActionRef.current = { dispatch, fallbackUrl, onUnavailable };
		clearTimeout( fallbackTimerRef.current );
		if ( ! fallbackUrl && ! onUnavailable ) {
			return;
		}
		fallbackTimerRef.current = setTimeout( () => {
			const pending = pendingActionRef.current;
			pendingActionRef.current = null;
			if ( ! pending ) {
				return;
			}
			// Belt-and-braces: if the bridge IS ready but its BRIDGE_MOUNTED
			// event was renamed (so our listener missed it), flush the queue
			// instead of navigating — the dispatch will land on the live
			// listeners that the readiness flag implies. Also re-resolve
			// reload-listener names so subsequent save/delete events from
			// the bridge still trigger a list refresh.
			if ( isBridgeReady() ) {
				reattachReloadListenersRef.current?.();
				pending.dispatch();
				return;
			}
			if ( pending.fallbackUrl ) {
				window.location.href = pending.fallbackUrl;
				return;
			}
			pending.onUnavailable?.();
		}, NN_FALLBACK_TIMEOUT_MS );
	};

	const bridgeUnavailableError = () =>
		setError( {
			message: __(
				'This action requires a newer version of Newspack Newsletters. Update the newsletters plugin and try again.',
				'newspack-plugin'
			),
		} );

	const dispatchOpenAdd = () => {
		dispatchOrQueue( 'OPEN_MODAL', { mode: 'add' }, { fallbackUrl: newspack_newsletters_wizard.new_subscription_lists_url } );
	};
	const dispatchOpenEdit = ( list, kind ) => {
		dispatchOrQueue( 'OPEN_MODAL', { mode: 'edit', kind, list }, { fallbackUrl: list?.edit_link } );
	};
	const dispatchConfirmDelete = list => {
		// No safe legacy delete URL, so surface a notice instead of navigating.
		dispatchOrQueue( 'OPEN_CONFIRM_DELETE', { list }, { onUnavailable: bridgeUnavailableError } );
	};

	if ( ! inFlight && ! lists?.length && ! error && ! lockedLists ) {
		return null;
	}

	const showAddNew = !! newspack_newsletters_wizard.new_subscription_lists_url;

	return (
		<>
			<Divider alignment="full-width" variant="tertiary" />
			<Grid columns={ 2 } gutter={ 32 } noMargin>
				<SectionHeader
					heading={ 2 }
					title={ __( 'Subscription lists', 'newspack-plugin' ) }
					description={ __( 'Manage the lists available to readers for subscription.', 'newspack-plugin' ) }
					noMargin
				/>
				<VStack spacing={ 4 } className="newspack-newsletters-settings-stack">
					{ lockedLists && (
						<WpNotice status="warning" isDismissible={ false }>
							{ __( 'Please save your ESP settings before changing your subscription lists.', 'newspack-plugin' ) }
						</WpNotice>
					) }
					{ ! lockedLists && error && (
						<WpNotice status="error" isDismissible={ false }>
							{ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) }
						</WpNotice>
					) }
					{ inFlight && ! lists?.length && ! error && (
						<div className="flex justify-around mt4">
							<Waiting />
						</div>
					) }
					{ ! lockedLists &&
						! error &&
						lists.map( ( list, index ) => {
							const isLocal = 'local' === list?.type;
							const rowDisabled = inFlight || togglingIds.has( list?.db_id );
							const isSubList = list?.id && ( list.id.startsWith( 'group' ) || list.id.startsWith( 'tag' ) );
							return (
								<Fragment key={ list.db_id || index }>
									{ index > 0 && <Divider alignment="none" variant="default" marginTop={ 0 } marginBottom={ 0 } /> }
									<HStack
										alignment="top"
										justify="space-between"
										className={ isSubList ? 'newspack-newsletters-sub-list-item' : undefined }
									>
										<VStack spacing={ 2 } className="newspack-newsletters-list-item__content">
											<ToggleControl
												label={ list.name }
												help={ list.description || undefined }
												checked={ !! list.active }
												onChange={ next => handleToggleActive( list, next ) }
												disabled={ rowDisabled }
												__nextHasNoMarginBottom
											/>
											{ ( isLocal || list?.type_label ) && (
												<HStack expanded={ false } justify="flex-start" className="newspack-newsletters-list-item__badge">
													<Badge text={ isLocal ? __( 'Local', 'newspack-plugin' ) : list.type_label } />
												</HStack>
											) }
										</VStack>
										<HStack expanded={ false } spacing={ 2 } justify="flex-end">
											<Button
												variant="link"
												onClick={ () => dispatchOpenEdit( list, isLocal ? 'local' : 'esp' ) }
												disabled={ rowDisabled }
												aria-label={ sprintf(
													// translators: %s is the list name.
													__( 'Edit %s', 'newspack-plugin' ),
													list.name
												) }
											>
												{ __( 'Edit', 'newspack-plugin' ) }
											</Button>
											{ isLocal && (
												<Button
													variant="link"
													isDestructive
													onClick={ () => dispatchConfirmDelete( list ) }
													disabled={ rowDisabled }
													aria-label={ sprintf(
														// translators: %s is the list name.
														__( 'Delete %s', 'newspack-plugin' ),
														list.name
													) }
												>
													{ __( 'Delete', 'newspack-plugin' ) }
												</Button>
											) }
										</HStack>
									</HStack>
								</Fragment>
							);
						} ) }
					{ ! lockedLists && ! error && showAddNew && (
						<>
							<Divider alignment="none" variant="default" marginTop={ 0 } marginBottom={ 0 } />
							<VStack spacing={ 3 }>
								<p style={ { margin: 0 } }>
									{ labels?.local_list_explanation
										? sprintf(
												// translators: %s is the provider-specific local list label, e.g. "Mailchimp Group" or "Active Campaign Tag".
												__( 'Local lists are managed in WordPress and synced to your ESP as: %s.', 'newspack-plugin' ),
												labels.local_list_explanation
										  )
										: __( 'Local lists are managed in WordPress and synced to an entity in your ESP.', 'newspack-plugin' ) }
								</p>
								<HStack expanded={ false } justify="flex-start">
									<Button variant="secondary" onClick={ dispatchOpenAdd }>
										{ __( 'Add new local list', 'newspack-plugin' ) }
									</Button>
								</HStack>
							</VStack>
						</>
					) }
				</VStack>
			</Grid>
		</>
	);
};

const NewslettersSettings = () => {
	const [ { newslettersConfig }, updateConfiguration ] = hooks.useObjectState( {} );
	const [ provider, setProvider ] = useState( '' );
	const [ lockedLists, setLockedLists ] = useState( false );
	const [ authUrl, setAuthUrl ] = useState( false );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( false );
	const [ savedConfig, setSavedConfig ] = useState( null );
	const [ labels, setLabels ] = useState( {} );
	const [ letterheadSetting, setLetterheadSetting ] = useState( null );
	const [ isConfigured, setIsConfigured ] = useState( false );
	const [ espConnected, setEspConnected ] = useState( false );
	const [ listsReloadToken, setListsReloadToken ] = useState( 0 );
	const { setHeaderData, addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		if ( savedConfig === null && newslettersConfig && Object.keys( newslettersConfig ).length > 0 ) {
			setSavedConfig( newslettersConfig );
		}
	}, [ newslettersConfig, savedConfig ] );

	const isDirty = savedConfig !== null && JSON.stringify( newslettersConfig ) !== JSON.stringify( savedConfig );

	// Only seed `letterheadSetting` once. The setting metadata is stable —
	// subsequent fetches would re-set the same value and churn renders.
	const handleLetterheadSetting = useCallback( setting => {
		setLetterheadSetting( prev => ( prev ? prev : setting ) );
	}, [] );

	const saveSettings = useCallback( async () => {
		// Snapshot the payload before the await so `savedConfig` reflects the
		// exact data sent to the server, even if the user edits the form
		// while the request is in flight.
		const payload = newslettersConfig;
		setError( false );
		setInFlight( true );
		try {
			const response = await apiFetch( {
				path: '/newspack/v1/wizard/newspack-newsletters/settings',
				method: 'POST',
				data: payload,
			} );
			const savedProviderValue = savedConfig?.newspack_newsletters_service_provider;
			const nextProviderValue = payload?.newspack_newsletters_service_provider;
			const connected = response?.esp_connected === true;
			setProvider( nextProviderValue );
			setEspConnected( connected );
			setLockedLists( ! connected );
			// Same provider still connected but credentials changed (key rotation):
			// nudge the lists to refetch, since provider and lock state didn't change.
			if (
				connected &&
				nextProviderValue === savedProviderValue &&
				providerSettingsSignature( payload, response?.settings, nextProviderValue ) !==
					providerSettingsSignature( savedConfig || {}, response?.settings, nextProviderValue )
			) {
				setListsReloadToken( token => token + 1 );
			}
			setSavedConfig( payload );
			if ( response?.labels ) {
				setLabels( response.labels );
			}
			addNotice( {
				id: 'newsletters-settings-saved',
				type: 'success',
				message: __( 'Settings saved.', 'newspack-plugin' ),
			} );
		} catch ( err ) {
			setError( err );
		} finally {
			setInFlight( false );
		}
	}, [ newslettersConfig, savedConfig, addNotice ] );

	useEffect( () => {
		setHeaderData( {
			sectionName: __( 'Settings', 'newspack-plugin' ),
			sectionTitle: __( 'Settings', 'newspack-plugin' ),
			actions: [
				{
					type: 'primary',
					label: __( 'Save', 'newspack-plugin' ),
					action: saveSettings,
					disabled: inFlight || ! isDirty,
				},
			],
		} );
	}, [ inFlight, isDirty, saveSettings, setHeaderData ] );

	const { confirmDialog: navBlockDialog } = useUnsavedChangesDialog( {
		when: isDirty && ! inFlight,
	} );

	return (
		<>
			{ navBlockDialog }
			{ error && (
				<WpNotice status="error" isDismissible={ false }>
					{ error?.message || __( 'Something went wrong.', 'newspack-plugin' ) }
				</WpNotice>
			) }
			<Settings
				isOnboarding={ false }
				isSaving={ inFlight }
				onUpdate={ config => updateConfiguration( { newslettersConfig: config } ) }
				onConfigured={ setIsConfigured }
				onLabels={ setLabels }
				onLetterheadSetting={ handleLetterheadSetting }
				authUrl={ authUrl }
				newslettersConfig={ newslettersConfig }
				savedProvider={ savedConfig?.newspack_newsletters_service_provider || '' }
				espConnected={ espConnected }
				onEspConnected={ setEspConnected }
				provider={ provider }
				setProvider={ setProvider }
				setAuthUrl={ setAuthUrl }
				setLockedLists={ setLockedLists }
			/>
			{ provider !== 'manual' && (
				<SubscriptionLists lockedLists={ lockedLists } provider={ provider } labels={ labels } reloadToken={ listsReloadToken } />
			) }
			{ isConfigured && <Tracking /> }
			{ letterheadSetting && (
				<>
					<Divider alignment="full-width" variant="tertiary" />
					<Grid columns={ 2 } gutter={ 32 } noMargin>
						<SectionHeader
							heading={ 2 }
							title={ __( 'Letterhead', 'newspack-plugin' ) }
							description={ __( 'Connect Letterhead to insert promotions into your newsletters.', 'newspack-plugin' ) }
							noMargin
						/>
						<VStack spacing={ 4 } className="newspack-newsletters-settings-stack">
							<VStack spacing={ 2 }>
								<TextControl
									label={ letterheadSetting.description }
									value={ newslettersConfig?.[ letterheadSetting.key ] || '' }
									onChange={ value => updateConfiguration( { newslettersConfig: { [ letterheadSetting.key ]: value } } ) }
									disabled={ inFlight }
									withMargin={ false }
								/>
								{ letterheadSetting.help && letterheadSetting.helpURL && (
									<p style={ { margin: 0 } }>
										<ExternalLink href={ letterheadSetting.helpURL }>{ letterheadSetting.help }</ExternalLink>
									</p>
								) }
							</VStack>
						</VStack>
					</Grid>
				</>
			) }
		</>
	);
};

export default NewslettersSettings;
