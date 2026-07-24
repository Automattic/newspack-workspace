/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { createPortal, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	Snackbar,
	ToggleControl,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import {
	withWizardScreen,
	Button,
	Divider,
	Grid,
	Notice,
	SectionHeader,
	SelectControl,
	TextControl,
	useUnsavedChangesDialog,
} from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';

const PLUGIN_SLUG = 'newspack-audience-campaigns';
const SETTINGS_PATH = `/newspack/v1/wizard/${ PLUGIN_SLUG }/settings`;

const isSectionInfo = setting => ! setting.key || setting.key === 'active';

// A section's editable key -> value map (the shape POSTed to the endpoint).
const sectionValues = section =>
	( section || [] ).reduce( ( map, setting ) => {
		if ( setting.key && 'active' !== setting.key ) {
			map[ setting.key ] = setting.value;
		}
		return map;
	}, {} );

// Values are scalars, so key/value equality is a full compare.
const mapsEqual = ( a, b ) => {
	const keys = Object.keys( a );
	return keys.length === Object.keys( b ).length && keys.every( key => a[ key ] === b[ key ] );
};

const snapshot = settings =>
	Object.keys( settings ).reduce( ( acc, key ) => {
		acc[ key ] = sectionValues( settings[ key ] );
		return acc;
	}, {} );

// Sequential per-section saves can partially succeed; name the failed section and
// note which earlier ones did save, since a retry only re-posts what's still dirty.
const saveErrorMessage = ( failedTitle, savedTitles ) => {
	if ( savedTitles.length ) {
		return sprintf(
			// translators: 1: the section that failed to save. 2: comma-separated list of sections that did save.
			__( '“%1$s” could not be saved. Your changes to %2$s were saved. Try saving again.', 'newspack-plugin' ),
			failedTitle,
			savedTitles.join( ', ' )
		);
	}
	return sprintf(
		// translators: %s: the section that failed to save.
		__( '“%s” could not be saved. Try saving again.', 'newspack-plugin' ),
		failedTitle
	);
};

// The wizard header/breadcrumbs come from withWizardScreen; this wrapper just
// lets us inject a single header Save action while rendering our own content.
const SettingsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const SettingField = ( { setting, onChange, disabled } ) => {
	if ( Array.isArray( setting.options ) && setting.options.length ) {
		return (
			<SelectControl
				label={ setting.description }
				help={ setting.help || undefined }
				value={ setting.value }
				options={ setting.options.map( option => ( { value: option.value, label: option.name || option.label } ) ) }
				onChange={ onChange }
				disabled={ disabled }
				__next40pxDefaultSize
			/>
		);
	}
	if ( 'boolean' === setting.type ) {
		return (
			<ToggleControl
				label={ setting.description }
				help={ setting.help || undefined }
				checked={ !! setting.value }
				onChange={ onChange }
				disabled={ disabled }
			/>
		);
	}
	return (
		<TextControl
			label={ setting.description }
			help={ setting.help || undefined }
			value={ setting.value }
			onChange={ onChange }
			disabled={ disabled }
			withMargin={ false }
		/>
	);
};

const Settings = props => {
	const [ settings, setSettings ] = useState( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );
	// The legacy campaigns wizard has no store snackbar outlet, so success feedback
	// is a local Snackbar (as the advertising placements screen does).
	const [ snackbar, setSnackbar ] = useState( null );
	// Each section's values as of the last successful load/save, to detect dirt.
	const savedRef = useRef( {} );

	useEffect( () => {
		setInFlight( true );
		apiFetch( { path: SETTINGS_PATH } )
			.then( fetched => {
				setSettings( fetched );
				savedRef.current = snapshot( fetched );
				setError( null );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	}, [] );

	const handleChange = ( sectionKey, key ) => value => {
		setSettings( previous => ( {
			...previous,
			[ sectionKey ]: previous[ sectionKey ].map( setting => ( setting.key === key ? { ...setting, value } : setting ) ),
		} ) );
	};

	const handleSave = async () => {
		setInFlight( true );
		setError( null );
		const savedTitles = [];
		try {
			for ( const sectionKey of Object.keys( settings ) ) {
				const section = settings[ sectionKey ];
				const current = sectionValues( section );
				// Only POST dirty sections, to shrink the stale-overwrite window.
				if ( mapsEqual( current, savedRef.current[ sectionKey ] || {} ) ) {
					continue;
				}
				const title = section.find( isSectionInfo )?.description || sectionKey;
				try {
					const response = await apiFetch( {
						path: SETTINGS_PATH,
						method: 'POST',
						data: { section: sectionKey, settings: current },
					} );
					// Merge into the latest state so a section response can't clobber unrelated updates.
					setSettings( previous => ( { ...previous, [ sectionKey ]: response[ sectionKey ] } ) );
					savedRef.current = { ...savedRef.current, [ sectionKey ]: sectionValues( response[ sectionKey ] ) };
					savedTitles.push( title );
				} catch ( err ) {
					// Saved sections keep their refreshed snapshot, so a retry re-posts only this one.
					setError( { message: saveErrorMessage( title, savedTitles ) } );
					return;
				}
			}
			setSnackbar( __( 'Settings saved.', 'newspack-plugin' ) );
		} catch ( err ) {
			setError( err );
		} finally {
			setInFlight( false );
		}
	};

	const isDirty = Object.keys( settings ).some(
		sectionKey => ! mapsEqual( sectionValues( settings[ sectionKey ] ), savedRef.current[ sectionKey ] || {} )
	);
	// Guard stays active during an in-flight save: the edits are only safe once
	// a successful response has refreshed the saved snapshot.
	const { confirmDialog } = useUnsavedChangesDialog( { when: isDirty } );

	const headerActions = (
		<Button variant="primary" onClick={ handleSave } disabled={ inFlight || ! isDirty }>
			{ __( 'Save', 'newspack-plugin' ) }
		</Button>
	);

	const sectionKeys = Object.keys( settings );

	return (
		<SettingsScreen { ...props } headerActions={ headerActions }>
			{ confirmDialog }
			<WizardsTab>
				{ error && <Notice isError noticeText={ error.message } /> }
				{ sectionKeys.map( ( sectionKey, index ) => {
					const section = settings[ sectionKey ];
					const sectionInfo = section.find( isSectionInfo );
					const fields = section.filter( setting => setting.key && 'active' !== setting.key );
					return (
						<Fragment key={ sectionKey }>
							{ index > 0 && <Divider alignment="full-width" variant="tertiary" /> }
							<Grid columns={ 2 } gutter={ 32 } noMargin>
								<SectionHeader heading={ 2 } title={ sectionInfo?.description } description={ sectionInfo?.help } noMargin />
								<VStack spacing={ 6 }>
									{ fields.map( setting => (
										<SettingField
											key={ setting.key }
											setting={ setting }
											onChange={ handleChange( sectionKey, setting.key ) }
											disabled={ inFlight }
										/>
									) ) }
								</VStack>
							</Grid>
						</Fragment>
					);
				} ) }
			</WizardsTab>
			{ snackbar &&
				createPortal(
					<div className="newspack-wizard__snackbar-list">
						<Snackbar onRemove={ () => setSnackbar( null ) }>{ snackbar }</Snackbar>
					</div>,
					document.body
				) }
		</SettingsScreen>
	);
};

export default Settings;
