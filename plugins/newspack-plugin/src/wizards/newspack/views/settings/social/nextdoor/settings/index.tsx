/**
 * Nextdoor Settings View
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { CheckboxControl, CardHeader, __experimentalHeading as Heading, CardBody } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Notice } from '../../../../../../../../packages/components/src';
import { SettingsProps } from '../types';

/**
 * Styles
 */
import './style.scss';

/**
 * Settings component.
 */
export const Settings = ( { settings, status, error, updateSettings, disconnect, setError }: SettingsProps ) => {
	const [ allowedRoles, setAllowedRoles ] = useState< string[] >( settings.allowed_roles || [] );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ hasChanges, setHasChanges ] = useState( false );

	// Get available roles from localized data
	const availableRoles = window.newspackSettings?.social?.nextdoor?.available_roles || [];

	useEffect( () => {
		setAllowedRoles( settings.allowed_roles || [] );
	}, [ settings ] );

	const handleRoleToggle = ( role: string, checked: boolean ) => {
		setHasChanges( true );
		if ( checked ) {
			setAllowedRoles( [ ...allowedRoles, role ] );
		} else {
			setAllowedRoles( allowedRoles.filter( r => r !== role ) );
		}
	};

	const handleSaveSettings = async () => {
		try {
			setIsSaving( true );
			setError( null );

			await updateSettings( {
				allowed_roles: allowedRoles,
			} );

			setHasChanges( false );
		} finally {
			setIsSaving( false );
		}
	};

	const handleDisconnect = async () => {
		await disconnect();
	};

	if ( ! status.is_connected ) {
		return (
			<Card>
				<Notice
					noticeText={ __( 'Nextdoor is not connected. Please complete the setup process first.', 'newspack-plugin' ) }
					isError={ false }
				/>
			</Card>
		);
	}

	return (
		<>
			{ error && <Notice noticeText={ error } isError onClose={ () => setError( null ) } /> }
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Connection Information', 'newspack-plugin' ) }</Heading>
				</CardHeader>
				<CardBody>
					<Grid columns={ 2 } gutter={ 16 }>
						<div>
							<strong>{ __( 'Status:', 'newspack-plugin' ) } </strong>
							{ status.is_connected ? (
								<span className="nextdoor-settings__status-value--success">{ __( 'Connected', 'newspack-plugin' ) }</span>
							) : (
								<span className="nextdoor-settings__status-value--error">{ __( 'Not Connected', 'newspack-plugin' ) }</span>
							) }
						</div>
						<div>
							<strong>{ __( 'Token:', 'newspack-plugin' ) } </strong>
							{ status.token_valid ? (
								<span className="nextdoor-settings__status-value--success">{ __( 'Valid', 'newspack-plugin' ) }</span>
							) : (
								<span className="nextdoor-settings__status-value--error">{ __( 'Invalid or expired', 'newspack-plugin' ) }</span>
							) }
						</div>
					</Grid>
					{ status.is_connected && (
						<div className="newspack-buttons-card">
							<Button variant="secondary" isDestructive onClick={ handleDisconnect }>
								{ __( 'Disconnect', 'newspack-plugin' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Settings', 'newspack-plugin' ) }</Heading>
				</CardHeader>
				<CardBody>
					<p>{ __( 'Select which user roles are allowed to publish articles to Nextdoor.', 'newspack-plugin' ) }</p>

					<Grid columns={ 4 } gutter={ 16 }>
						{ availableRoles.map( ( { label, value } ) => (
							<CheckboxControl
								key={ value }
								label={ label }
								checked={ allowedRoles.includes( value ) || 'administrator' === value }
								onChange={ ( checked: boolean ) => handleRoleToggle( value, checked ) }
								disabled={ 'administrator' === value }
								help={
									'administrator' === value
										? __( 'Administrators always have publishing permissions.', 'newspack-plugin' )
										: undefined
								}
							/>
						) ) }
					</Grid>

					<div className="newspack-buttons-card">
						<Button variant="primary" onClick={ handleSaveSettings } disabled={ ! hasChanges || isSaving } isBusy={ isSaving }>
							{ __( 'Save', 'newspack-plugin' ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</>
	);
};

export default Settings;
