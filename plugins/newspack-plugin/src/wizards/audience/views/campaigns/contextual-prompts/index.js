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
import { withWizardScreen, Button, Notice, Waiting, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';

const STATUS_PATH = '/newspack-popups/v1/contextual-prompt/status';
const ENABLE_PATH = '/newspack-popups/v1/contextual-prompt/enable';
const PROFILE_PATH = '/newspack-popups/v1/contextual-prompt/profile';

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

	const loadStatus = () => {
		setError( null );
		return apiFetch( { path: STATUS_PATH } )
			.then( next => {
				setStatus( next );
				const nextValues = fieldsToValues( next.fields );
				setValues( nextValues );
				savedValuesRef.current = nextValues;
			} )
			.catch( setError )
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
	const saveProfile = () =>
		request( PROFILE_PATH, { fields: values } )
			.then( () => setSnackbar( __( 'Settings saved.', 'newspack-plugin' ) ) )
			.catch( () => {} );
	const setValue = ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) );

	const isDirty = ! valuesEqual( values, savedValuesRef.current );
	const { confirmDialog, requestConfirm } = useUnsavedChangesDialog( { when: isDirty && ! inFlight } );

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
			<Button variant="primary" onClick={ saveProfile } disabled={ inFlight || ! isDirty }>
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
