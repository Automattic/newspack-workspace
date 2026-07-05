/* globals newspack_popups_settings */

/**
 * WordPress dependencies
 */
import { render, useState } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, CardHeader, CheckboxControl, FlexBlock, Notice, SelectControl, TextControl } from '@wordpress/components';

/**
 * Newspack dependencies.
 */
import { NewspackIcon } from 'newspack-components';

/**
 * Internal dependencies
 */
import './style.scss';

/** A setting's persisted value, as sent to and returned from the settings REST endpoint. */
type SettingValue = string | boolean;

const App = () => {
	const [ inFlight, setInFlight ] = useState( false );
	const [ settings, setSettings ] = useState< PopupsSetting[] >( newspack_popups_settings );
	const [ settingsToUpdate, setSettingsToUpdate ] = useState< Record< string, SettingValue > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const handleSettingChange = ( option_name: string ) => ( option_value: SettingValue ) => {
		const newSettings = { ...settingsToUpdate };
		newSettings[ option_name ] = option_value;
		setSettingsToUpdate( newSettings );
	};
	const handleSave = () => {
		setError( null );
		setInFlight( true );
		apiFetch< PopupsSetting[] >( {
			path: '/newspack-popups/v1/settings/',
			method: 'POST',
			data: { settingsToUpdate },
		} )
			.then( response => {
				setSettingsToUpdate( {} );
				setSettings( response );
			} )
			.catch( ( e: unknown ) => {
				// A rejected apiFetch() call rejects with a parsed error object (or, rarely, a raw
				// Error); narrow at this boundary rather than assuming its shape.
				const err = e as { message?: string };
				setError( err?.message || __( 'Error updating settings.', 'newspack-popups' ) );
			} )
			.finally( () => {
				setInFlight( false );
			} );
	};

	const renderSetting = ( setting: PopupsSetting ) => {
		if ( setting.description && 'active' !== setting.key ) {
			const props = {
				key: setting.key,
				label: setting.description,
				help: setting.help,
				disabled: inFlight,
				onChange: handleSettingChange( setting.key ),
			};
			switch ( setting.type ) {
				case 'string':
					return (
						<TextControl
							{ ...props }
							value={ ( settingsToUpdate.hasOwnProperty( setting.key ) ? settingsToUpdate[ setting.key ] : setting.value ) as string }
						/>
					);
				case 'select':
					return (
						<SelectControl
							{ ...props }
							value={ ( settingsToUpdate.hasOwnProperty( setting.key ) ? settingsToUpdate[ setting.key ] : setting.value ) as string }
							options={ setting.options }
						/>
					);
				default:
					return (
						<CheckboxControl
							{ ...props }
							checked={
								settingsToUpdate.hasOwnProperty( setting.key ) ? ( settingsToUpdate[ setting.key ] as boolean ) : !! setting.value
							}
						/>
					);
			}
		}
		return null;
	};

	return (
		<div className="newspack-campaigns__wrapper">
			<div className="newspack-logo__wrapper">
				<Button className="newspack-logo-button" href="https://newspack.pub/" target="_blank" label={ __( 'By Newspack' ) }>
					<NewspackIcon height={ 32 } />
				</Button>
			</div>
			<Card>
				<CardHeader isShady>
					<FlexBlock>
						<h2>{ __( 'Settings', 'newspack-popups' ) }</h2>
					</FlexBlock>
				</CardHeader>
				<CardBody>
					{ settings.map( renderSetting ) }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div className="newspack-popups-save">
						<Button disabled={ inFlight || 0 === Object.keys( settingsToUpdate ).length } onClick={ handleSave }>
							{ __( 'Save', 'newspack-popups' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
};

domReady( () => {
	const element = document.getElementById( 'newspack-popups-settings-root' );
	render( <App />, element );
} );
