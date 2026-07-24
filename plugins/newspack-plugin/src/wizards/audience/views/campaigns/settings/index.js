/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
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
} from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';
import ContextualPromptsSettings from './contextual-prompts-settings';

const PLUGIN_SLUG = 'newspack-audience-campaigns';
const SETTINGS_PATH = `/newspack/v1/wizard/${ PLUGIN_SLUG }/settings`;

const isSectionInfo = setting => ! setting.key || setting.key === 'active';

// The wizard header/breadcrumbs come from withWizardScreen; this wrapper just
// lets us inject a single header Save action while rendering our own content.
const SettingsScreen = withWizardScreen( ( { children } ) => <>{ children }</> );

const SettingField = ( { setting, onChange } ) => {
	if ( Array.isArray( setting.options ) && setting.options.length ) {
		return (
			<SelectControl
				label={ setting.description }
				help={ setting.help || undefined }
				value={ setting.value }
				options={ setting.options.map( option => ( { value: option.value, label: option.name || option.label } ) ) }
				onChange={ onChange }
				__next40pxDefaultSize
			/>
		);
	}
	if ( 'boolean' === setting.type ) {
		return <ToggleControl label={ setting.description } help={ setting.help || undefined } checked={ !! setting.value } onChange={ onChange } />;
	}
	return (
		<TextControl
			label={ setting.description }
			help={ setting.help || undefined }
			value={ setting.value }
			onChange={ onChange }
			withMargin={ false }
		/>
	);
};

const Settings = props => {
	const [ configuring, setConfiguring ] = useState( false );
	const [ settings, setSettings ] = useState( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setInFlight( true );
		apiFetch( { path: SETTINGS_PATH } )
			.then( fetched => {
				setSettings( fetched );
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
		try {
			let updated = settings;
			for ( const sectionKey of Object.keys( settings ) ) {
				const sectionSettings = settings[ sectionKey ].reduce( ( map, setting ) => {
					if ( setting.key && 'active' !== setting.key ) {
						map[ setting.key ] = setting.value;
					}
					return map;
				}, {} );
				const response = await apiFetch( {
					path: SETTINGS_PATH,
					method: 'POST',
					data: { section: sectionKey, settings: sectionSettings },
				} );
				updated = { ...updated, [ sectionKey ]: response[ sectionKey ] };
				setSettings( updated );
			}
		} catch ( err ) {
			setError( err );
		} finally {
			setInFlight( false );
		}
	};

	const headerActions = configuring ? undefined : (
		<Button variant="primary" onClick={ handleSave } disabled={ inFlight }>
			{ __( 'Save', 'newspack-plugin' ) }
		</Button>
	);

	const sectionKeys = Object.keys( settings );

	return (
		<SettingsScreen { ...props } headerActions={ headerActions }>
			<ContextualPromptsSettings configuring={ configuring } onConfigure={ setConfiguring } />
			{ /* While the Contextual Prompts configure view is open, it replaces the page. */ }
			{ ! configuring && (
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
											/>
										) ) }
									</VStack>
								</Grid>
							</Fragment>
						);
					} ) }
				</WizardsTab>
			) }
		</SettingsScreen>
	);
};

export default Settings;
