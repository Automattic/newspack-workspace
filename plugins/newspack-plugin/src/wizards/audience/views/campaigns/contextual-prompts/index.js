/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DropdownMenu } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { withWizardScreen, Button, Notice, Waiting, useUnsavedChangesDialog } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';

const STATUS_PATH = '/newspack-popups/v1/contextual-prompt/status';
const ENABLE_PATH = '/newspack-popups/v1/contextual-prompt/enable';
const PROFILE_PATH = '/newspack-popups/v1/contextual-prompt/profile';

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

	const setEnabled = enabled => request( ENABLE_PATH, { enabled } );
	const saveProfile = () => request( PROFILE_PATH, { fields: values } ).catch( () => {} );
	const setValue = ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) );

	const isDirty = ! valuesEqual( values, savedValuesRef.current );
	const { confirmDialog } = useUnsavedChangesDialog( { when: isDirty && ! inFlight } );

	const headerActions = status?.enabled ? (
		<>
			<DropdownMenu
				icon={ moreVertical }
				label={ __( 'More options', 'newspack-plugin' ) }
				controls={ [
					{
						title: __( 'Disable', 'newspack-plugin' ),
						onClick: () => setEnabled( false ).catch( () => {} ),
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
	if ( status ) {
		content = (
			<ContextualPromptsSettings
				status={ status }
				values={ values }
				error={ error }
				inFlight={ inFlight }
				onSetValue={ setValue }
				onEnable={ () => setEnabled( true ) }
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
		</ContextualPromptsScreen>
	);
};

export default ContextualPrompts;
