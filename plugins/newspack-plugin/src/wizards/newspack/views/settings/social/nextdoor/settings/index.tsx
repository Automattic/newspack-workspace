/**
 * Nextdoor Settings View
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { CheckboxControl, Notice as WPNotice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, Grid, Notice } from '../../../../../../../../packages/components/src';
import { useSocialCards } from '../../context';
import { SettingsProps } from '../types';

/**
 * Styles
 */
import './style.scss';

/**
 * Settings component.
 */
export const Settings = ( { settings, status, error, updateSettings, disconnect, setError, renderSecondaryActions }: SettingsProps ) => {
	const { notify } = useSocialCards();
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
			notify( __( 'Nextdoor settings updated.', 'newspack-plugin' ) );
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const handleDisconnect = async () => {
		try {
			await disconnect();
		} catch {
			// Already surfaced by the card's error notice.
		}
	};

	if ( ! status.is_connected ) {
		return <Notice noticeText={ __( 'Nextdoor is not connected. Please complete the setup process first.', 'newspack-plugin' ) } isWarning />;
	}

	return (
		<VStack spacing={ 6 }>
			{ error && (
				<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
					{ error }
				</WPNotice>
			) }

			<VStack spacing={ 4 }>
				<h4 className="nextdoor-settings__subheading">{ __( 'Connection Information', 'newspack-plugin' ) }</h4>
				<Grid columns={ 2 } gutter={ 16 } noMargin>
					<div>
						<strong>{ __( 'Status:', 'newspack-plugin' ) } </strong>
						<span className="nextdoor-settings__status-value--success">{ __( 'Connected', 'newspack-plugin' ) }</span>
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
			</VStack>

			<VStack spacing={ 4 }>
				<h4 className="nextdoor-settings__subheading">{ __( 'Settings', 'newspack-plugin' ) }</h4>
				<p className="nextdoor-settings__intro">
					{ __( 'Select which user roles are allowed to publish articles to Nextdoor.', 'newspack-plugin' ) }
				</p>

				<Grid columns={ 2 } gutter={ 16 } noMargin>
					{ availableRoles.map( ( { label, value } ) => (
						<CheckboxControl
							key={ value }
							label={ label }
							checked={ allowedRoles.includes( value ) || 'administrator' === value }
							onChange={ ( checked: boolean ) => handleRoleToggle( value, checked ) }
							disabled={ 'administrator' === value }
							help={
								'administrator' === value ? __( 'Administrators always have publishing permissions.', 'newspack-plugin' ) : undefined
							}
						/>
					) ) }
				</Grid>

				<HStack justify="flex-start" spacing={ 2 }>
					<Button
						variant="primary"
						__next40pxDefaultSize
						onClick={ handleSaveSettings }
						disabled={ ! hasChanges || isSaving }
						isBusy={ isSaving }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
					<Button variant="tertiary" __next40pxDefaultSize isDestructive onClick={ handleDisconnect }>
						{ __( 'Disconnect', 'newspack-plugin' ) }
					</Button>
					{ renderSecondaryActions?.() }
				</HStack>
			</VStack>
		</VStack>
	);
};

export default Settings;
