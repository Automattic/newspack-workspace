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
	Notice,
	Snackbar,
} from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { withWizardScreen, Button, Handoff, Waiting, useConfirmDialog, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';
import './style.scss';

const STATUS_PATH = '/newspack-popups/v1/contextual-prompt/status';
const ENABLE_PATH = '/newspack-popups/v1/contextual-prompt/enable';
const PROFILE_PATH = '/newspack-popups/v1/contextual-prompt/profile';
const RESET_DESIGN_PATH = '/newspack-popups/v1/contextual-prompt/reset-design';

// Minimum time the enable/disable busy state is shown, so it doesn't flash.
const MIN_TOGGLE_MS = 2000;

const fieldsToValues = fields => ( fields || [] ).reduce( ( acc, field ) => ( { ...acc, [ field.key ]: field.value ?? '' } ), {} );

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
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ loaded, setLoaded ] = useState( false );
	const [ disabling, setDisabling ] = useState( false );
	// The legacy campaigns wizard has no store snackbar outlet, so success feedback
	// is a local Snackbar (as the advertising placements screen does).
	const [ snackbar, setSnackbar ] = useState( null );
	// Values as of the last successful status fetch / profile save, to detect dirt.
	const savedValuesRef = useRef( {} );
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
				return next;
			} )
			.catch( err => {
				setError( err );
				throw err;
			} )
			.finally( () => setInFlight( false ) );
	};
	const saveProfile = () => request( PROFILE_PATH, { fields: values } ).then( () => setSnackbar( __( 'Settings saved.', 'newspack-plugin' ) ) );
	const onSave = () => saveProfile().catch( () => {} );
	const setValue = ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) );

	const isDirty = ! valuesEqual( values, savedValuesRef.current );
	// Guard stays active during an in-flight save: the edits are only safe once
	// a successful response has refreshed the saved snapshot.
	const { confirmDialog, requestConfirm, allowNextUnload } = useUnsavedChangesDialog( { when: isDirty } );

	// Disabling refreshes state from the response, discarding local edits, so route
	// it through the same unsaved-changes guard: it confirms only when dirty, and a
	// separate confirm dialog would fight this one's navigation block.
	const disable = () => {
		setDisabling( true );
		return setEnabled( false )
			.then( () => setSnackbar( __( 'Contextual Prompts disabled.', 'newspack-plugin' ) ) )
			.catch( () => {} )
			.finally( () => setDisabling( false ) );
	};
	const onDisable = () => requestConfirm( disable );
	const onEnable = () => setEnabled( true ).then( () => setSnackbar( __( 'Contextual Prompts enabled.', 'newspack-plugin' ) ) );

	// The pattern cannot be deleted and re-created, so this is the only way back
	// from a design edit that went wrong.
	const { confirmDialog: resetDialog, requestConfirm: requestReset } = useConfirmDialog( {
		title: __( 'Reset the prompt design?', 'newspack-plugin' ),
		message: __(
			'The design goes back to the one Newspack ships, and any changes made to it are lost. The copy written for each story is kept.',
			'newspack-plugin'
		),
		confirmButtonText: __( 'Reset Design', 'newspack-plugin' ),
		isDestructive: true,
	} );
	const onResetDesign = () =>
		requestReset( () =>
			request( RESET_DESIGN_PATH )
				.then( () => setSnackbar( __( 'Prompt design reset.', 'newspack-plugin' ) ) )
				.catch( () => {} )
		);

	const bannerText = __( 'Return to Contextual Prompts after editing the design', 'newspack-plugin' );
	const bannerButtonText = __( 'Back to Contextual Prompts', 'newspack-plugin' );
	// What Handoff does on click: register the return banner, then leave for the
	// editor. Repeated here because the dirty branch has to run it after the
	// discard dialog, and Handoff owns its own onClick.
	const goToPattern = () =>
		apiFetch( {
			path: '/newspack/v1/handoff',
			method: 'POST',
			data: {
				destinationUrl: status.pattern_edit_url,
				handoffReturnUrl: window.location.href,
				bannerText,
				bannerButtonText,
			},
		} ).then( response => {
			// The edits are still pending, so the guard is still armed: stand it
			// down for this navigation, which the discard dialog already approved.
			allowNextUnload();
			window.location.href = response.HandoffLink;
		} );

	const headerActions = status?.enabled ? (
		<>
			<DropdownMenu
				icon={ moreVertical }
				label={ __( 'More options', 'newspack-plugin' ) }
				controls={ [
					// Offered only where there is an edit to undo: a design still as
					// shipped has nothing to go back to.
					...( status.design_modified
						? [
								{
									title: __( 'Reset Design', 'newspack-plugin' ),
									onClick: onResetDesign,
									isDisabled: inFlight,
								},
						  ]
						: [] ),
					{
						title: __( 'Disable', 'newspack-plugin' ),
						onClick: onDisable,
						isDisabled: inFlight,
					},
				] }
			/>
			{ /* A handoff rather than a link: the pattern opens in the block editor,
			     which has no way back to the wizard without the return banner. It
			     leaves the wizard without a navigation the unsaved-changes guard can
			     intercept, so with edits pending the confirmation is asked here. */ }
			{ status.pattern_edit_url &&
				( isDirty ? (
					<Button variant="secondary" onClick={ () => requestConfirm( goToPattern ) }>
						{ __( 'Edit Design', 'newspack-plugin' ) }
					</Button>
				) : (
					<Handoff variant="secondary" url={ status.pattern_edit_url } bannerText={ bannerText } bannerButtonText={ bannerButtonText }>
						{ __( 'Edit Design', 'newspack-plugin' ) }
					</Handoff>
				) ) }
			<Button variant="primary" onClick={ onSave } disabled={ inFlight || ! isDirty }>
				{ __( 'Save', 'newspack-plugin' ) }
			</Button>
		</>
	) : undefined;

	let content = <Waiting />;
	if ( disabling ) {
		content = (
			<VStack alignment="center" spacing={ 4 } className="newspack-contextual-prompts__disabling">
				<Waiting />
				<p>{ __( 'Disabling Contextual Prompts…', 'newspack-plugin' ) }</p>
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
				<Notice status="error" isDismissible={ false }>
					{ error?.message || __( 'Could not load Contextual Prompts.', 'newspack-plugin' ) }
				</Notice>
				<Button variant="primary" onClick={ loadStatus }>
					{ __( 'Retry', 'newspack-plugin' ) }
				</Button>
			</>
		);
	}

	return (
		<ContextualPromptsScreen { ...props } headerActions={ headerActions }>
			{ confirmDialog }
			{ resetDialog }
			{ content }
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
