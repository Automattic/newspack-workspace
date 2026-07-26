/**
 * External dependencies
 */
import isEqual from 'lodash/isEqual';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createPortal, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	DropdownMenu,
	Snackbar,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { withWizardScreen, Button, Handoff, Notice, Waiting, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';
import StyleDrawer from './style-drawer';

const STATUS_PATH = '/newspack-popups/v1/contextual-prompt/status';
const ENABLE_PATH = '/newspack-popups/v1/contextual-prompt/enable';
const PROFILE_PATH = '/newspack-popups/v1/contextual-prompt/profile';

// Minimum time the enable/disable busy state is shown, so it doesn't flash.
const MIN_TOGGLE_MS = 2000;

const fieldsToValues = fields => ( fields || [] ).reduce( ( acc, field ) => ( { ...acc, [ field.key ]: field.value ?? '' } ), {} );

// No overrides can arrive as an empty JSON array, and the controls only ever
// produce plain objects: an array snapshot would never compare equal to one, so
// edits that net back to nothing would stay dirty forever.
const normalizeStyles = styles => ( styles && ! Array.isArray( styles ) ? styles : {} );

// Values are scalars, so key/value equality is a full compare.
const valuesEqual = ( a, b ) => {
	const keys = Object.keys( a );
	return keys.length === Object.keys( b ).length && keys.every( key => a[ key ] === b[ key ] );
};

// The wizard header/breadcrumbs come from withWizardScreen; this wrapper just
// lets us inject header actions while rendering our own content.
const ContextualPromptsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const ContextualPrompts = props => {
	const [ status, setStatus ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ blockStyles, setBlockStyles ] = useState( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ loaded, setLoaded ] = useState( false );
	const [ disabling, setDisabling ] = useState( false );
	// Whether the Edit Styles drawer is open (classic themes only).
	const [ editingStyles, setEditingStyles ] = useState( false );
	// The legacy campaigns wizard has no store snackbar outlet, so success feedback
	// is a local Snackbar (as the advertising placements screen does).
	const [ snackbar, setSnackbar ] = useState( null );
	// Values as of the last successful status fetch / profile save, to detect dirt.
	const savedValuesRef = useRef( {} );
	// Same, for the block style overrides: the save only sends them when they differ.
	const savedStylesRef = useRef( {} );
	// Monotonic id so a slow status response can't clobber state written by a
	// newer request or mutation.
	const statusRequestRef = useRef( 0 );

	const loadStatus = () => {
		setError( null );
		setLoaded( false );
		const requestId = ++statusRequestRef.current;
		return apiFetch( { path: STATUS_PATH } )
			.then( next => {
				if ( requestId !== statusRequestRef.current ) {
					return;
				}
				setStatus( next );
				const nextValues = fieldsToValues( next.fields );
				setValues( nextValues );
				savedValuesRef.current = nextValues;
				const nextStyles = normalizeStyles( next.styles );
				setBlockStyles( nextStyles );
				savedStylesRef.current = nextStyles;
			} )
			.catch( err => {
				if ( requestId === statusRequestRef.current ) {
					setError( err );
				}
			} )
			.finally( () => setLoaded( true ) );
	};

	useEffect( () => {
		loadStatus();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const request = ( path, data ) => {
		setInFlight( true );
		setError( null );
		return apiFetch( { path, method: 'POST', data } )
			.then( next => {
				// A mutation supersedes any status request still in flight.
				statusRequestRef.current++;
				setStatus( next );
				const nextValues = fieldsToValues( next.fields );
				setValues( nextValues );
				savedValuesRef.current = nextValues;
				const nextStyles = normalizeStyles( next.styles );
				setBlockStyles( nextStyles );
				savedStylesRef.current = nextStyles;
				return next;
			} )
			.catch( err => {
				setError( err );
				throw err;
			} )
			.finally( () => setInFlight( false ) );
	};

	// Toggling the feature is padded to a minimum so the busy state doesn't flash;
	// the status flip is held until it resolves so the modal/spinner stays up.
	const setEnabled = enabled => {
		setInFlight( true );
		setError( null );
		return Promise.all( [
			apiFetch( { path: ENABLE_PATH, method: 'POST', data: { enabled } } ),
			new Promise( resolve => setTimeout( resolve, MIN_TOGGLE_MS ) ),
		] )
			.then( ( [ next ] ) => {
				setStatus( next );
				const nextValues = fieldsToValues( next.fields );
				setValues( nextValues );
				savedValuesRef.current = nextValues;
				const nextStyles = normalizeStyles( next.styles );
				setBlockStyles( nextStyles );
				savedStylesRef.current = nextStyles;
				return next;
			} )
			.catch( err => {
				setError( err );
				throw err;
			} )
			.finally( () => setInFlight( false ) );
	};
	// The endpoint replaces the whole styles option when the key is present and
	// leaves it alone when it is absent, so only an actual edit sends it.
	const stylesDirty = ! isEqual( blockStyles, savedStylesRef.current );
	const saveProfile = () => {
		const data = { fields: values };
		if ( stylesDirty ) {
			data.styles = blockStyles;
		}
		return request( PROFILE_PATH, data ).then( () => setSnackbar( __( 'Settings saved.', 'newspack-plugin' ) ) );
	};
	// The endpoint requires fields but only writes the keys it is given, so the
	// drawer sends an empty object: no profile write at all, and local field
	// edits stay unsaved and intact, since this path never refreshes values.
	const saveStyles = () => {
		setInFlight( true );
		setError( null );
		return apiFetch( { path: PROFILE_PATH, method: 'POST', data: { fields: {}, styles: blockStyles } } )
			.then( next => {
				statusRequestRef.current++;
				setStatus( next );
				const nextStyles = normalizeStyles( next.styles );
				setBlockStyles( nextStyles );
				savedStylesRef.current = nextStyles;
				setSnackbar( __( 'Styles saved.', 'newspack-plugin' ) );
				return next;
			} )
			.catch( err => {
				setError( err );
				throw err;
			} )
			.finally( () => setInFlight( false ) );
	};
	const onSave = () => saveProfile().catch( () => {} );
	const setValue = ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) );

	const isDirty = ! valuesEqual( values, savedValuesRef.current ) || stylesDirty;
	// Guard stays active during an in-flight save: the edits are only safe once
	// a successful response has refreshed the saved snapshot.
	const { confirmDialog, requestConfirm } = useUnsavedChangesDialog( { when: isDirty } );

	// Closing the drawer with style edits standing goes through the same dialog
	// as Disable and navigation. A second dialog instance would fight this one:
	// dismissing it replaces the history location, which the guard's own active
	// block would catch and answer with a prompt of its own.
	const discardStyles = () => {
		setBlockStyles( savedStylesRef.current );
		setError( null );
		setEditingStyles( false );
	};
	const onStyleDrawerClose = () => {
		if ( inFlight ) {
			return;
		}
		if ( stylesDirty ) {
			requestConfirm( discardStyles );
		} else {
			setError( null );
			setEditingStyles( false );
		}
	};
	const onStyleDrawerSave = () =>
		saveStyles()
			.then( () => setEditingStyles( false ) )
			.catch( () => {} );

	// Disabling refreshes state from the response, discarding local edits, so route
	// it through the same unsaved-changes guard: it confirms only when dirty, and a
	// separate confirm dialog would fight this one's navigation block.
	const disable = () => {
		setDisabling( true );
		// A drawer left open would come back with the feature on re-enable.
		setEditingStyles( false );
		return setEnabled( false )
			.then( () => setSnackbar( __( 'Contextual Prompts disabled.', 'newspack-plugin' ) ) )
			.catch( () => {} )
			.finally( () => setDisabling( false ) );
	};
	const onDisable = () => requestConfirm( disable );
	const onEnable = () => setEnabled( true ).then( () => setSnackbar( __( 'Contextual Prompts enabled.', 'newspack-plugin' ) ) );

	// Styles are edited from the header on both theme kinds: a block theme hands
	// off to the Site Editor, where its block styles live, and a classic theme
	// opens the drawer hosting the wizard's own controls. The style payload
	// newspack-popups only started sending carries the theme flag, so an older
	// one gets neither button.
	const hasStylePayload = status?.enabled && 'is_block_theme' in status;
	const showStyleHandoff = hasStylePayload && status.is_block_theme;
	const showStyleDrawer = hasStylePayload && ! status.is_block_theme;

	const headerActions = status?.enabled ? (
		<>
			<DropdownMenu
				icon={ moreVertical }
				label={ __( 'More options', 'newspack-plugin' ) }
				controls={ [
					{
						title: __( 'Disable', 'newspack-plugin' ),
						onClick: onDisable,
						isDisabled: inFlight,
					},
				] }
			/>
			{ showStyleHandoff && (
				<Handoff
					url={ status.site_editor_styles_url }
					bannerText={ __( 'Return to Contextual Prompts after editing the block styles', 'newspack-plugin' ) }
					bannerButtonText={ __( 'Back to Contextual Prompts', 'newspack-plugin' ) }
				>
					{ __( 'Edit Styles', 'newspack-plugin' ) }
				</Handoff>
			) }
			{ showStyleDrawer && (
				<Button
					variant="secondary"
					onClick={ () => {
						// The page and the drawer share the error state, so a notice
						// left over from the page must not follow the drawer in.
						setError( null );
						setEditingStyles( true );
					} }
					disabled={ inFlight }
				>
					{ __( 'Edit Styles', 'newspack-plugin' ) }
				</Button>
			) }
			<Button variant="primary" onClick={ onSave } disabled={ inFlight || ! isDirty }>
				{ __( 'Save', 'newspack-plugin' ) }
			</Button>
		</>
	) : undefined;

	let content = <Waiting />;
	if ( disabling ) {
		content = (
			<VStack alignment="center" spacing={ 4 } style={ { padding: '64px 0' } }>
				<Waiting />
				<p style={ { margin: 0, fontWeight: 600 } }>{ __( 'Disabling Contextual Prompts…', 'newspack-plugin' ) }</p>
			</VStack>
		);
	} else if ( status ) {
		content = (
			<ContextualPromptsSettings
				status={ status }
				values={ values }
				error={ error }
				inFlight={ inFlight }
				onSetValue={ setValue }
				onEnable={ onEnable }
			/>
		);
	} else if ( loaded ) {
		content = (
			<>
				<Notice isError noticeText={ error?.message || __( 'Could not load Contextual Prompts.', 'newspack-plugin' ) } />
				<Button variant="primary" onClick={ loadStatus }>
					{ __( 'Retry', 'newspack-plugin' ) }
				</Button>
			</>
		);
	}

	return (
		<ContextualPromptsScreen { ...props } headerActions={ headerActions }>
			{ confirmDialog }
			{ content }
			{ editingStyles && showStyleDrawer && (
				<StyleDrawer
					status={ status }
					styles={ blockStyles }
					error={ error }
					inFlight={ inFlight }
					isDirty={ stylesDirty }
					onChangeStyles={ setBlockStyles }
					onRequestClose={ onStyleDrawerClose }
					onSave={ onStyleDrawerSave }
				/>
			) }
			{ snackbar &&
				createPortal(
					<div className="newspack-wizard__snackbar-list">
						<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar }</Snackbar>
					</div>,
					document.body
				) }
		</ContextualPromptsScreen>
	);
};

export default ContextualPrompts;
