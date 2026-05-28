/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, CheckboxControl, ExternalLink } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Grid, Notice, SelectControl } from '../../../../../../packages/components/src';

const isValidError = ( e: unknown ): e is WpRestApiError => {
	return e instanceof Error && 'message' in e;
};

const JetpackSSO = () => {
	const [ error, setError ] = useState< string >( '' );
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ settings, setSettings ] = useState< JetpackSSOSettings >( {} );
	const [ settingsToUpdate, setSettingsToUpdate ] = useState< JetpackSSOSettings >( {} );

	const getCapLabel = ( cap: JetpackSSOCaps ): string | undefined => ( settings.available_caps ? settings.available_caps[ cap ] : undefined );

	useEffect( () => {
		const fetchSettings = async () => {
			setIsLoading( true );
			try {
				const fetchedSettings = await apiFetch< JetpackSSOSettings >( {
					path: '/newspack-manager/v1/jetpack-sso',
				} );
				setSettings( fetchedSettings );
				setSettingsToUpdate( fetchedSettings );
			} catch ( e: unknown ) {
				setError( isValidError( e ) ? e.message : __( 'Error fetching settings.', 'newspack-plugin' ) );
			} finally {
				setIsLoading( false );
			}
		};
		fetchSettings();
	}, [] );

	const updateSettings = async ( data: JetpackSSOSettings ) => {
		setError( '' );
		setIsLoading( true );
		try {
			const newSettings = await apiFetch< JetpackSSOSettings >( {
				path: '/newspack-manager/v1/jetpack-sso',
				method: 'POST',
				data,
			} );
			setSettings( newSettings );
			setSettingsToUpdate( newSettings );
		} catch ( e: unknown ) {
			setError( isValidError( e ) ? e.message : __( 'Error updating settings.', 'newspack-plugin' ) );
		} finally {
			setIsLoading( false );
		}
	};
	return (
		<>
			<ActionCard
				isMedium
				title={ __( 'Force two-factor authentication', 'newspack-plugin' ) }
				description={ __(
					'Improve security by requiring two-factor authentication via WordPress.com for users with higher capabilities.',
					'newspack-plugin'
				) }
				hasGreyHeader={ !! settings.force_2fa }
				toggleChecked={ !! settings.force_2fa }
				toggleOnChange={ () => updateSettings( { force_2fa: ! settings.force_2fa } ) }
				actionContent={
					settings.force_2fa && (
						<Button
							variant="primary"
							disabled={ isLoading || ! Object.keys( settingsToUpdate ).length }
							onClick={ () => updateSettings( settingsToUpdate ) }
						>
							{ __( 'Save Settings', 'newspack-plugin' ) }
						</Button>
					)
				}
				disabled={ isLoading }
			>
				{ settings.force_2fa && (
					<>
						{ error && <Notice isError noticeText={ error } /> }
						{ settings.jetpack_sso_force_2fa && (
							<>
								<Notice isWarning>
									{ __(
										'Two-factor authentication is currently enforced for all users via Jetpack configuration.',
										'newspack-plugin'
									) }{ ' ' }
									<ExternalLink href="/wp-admin/admin.php?page=jetpack#/settings">
										{ __( 'Jetpack Settings', 'newspack-plugin' ) }
									</ExternalLink>
								</Notice>
								<p>
									{ __(
										'Customize which capabilities to enforce 2FA by untoggling the “Require accounts to use WordPress.com Two-Step Authentication” option in Jetpack settings.',
										'newspack-plugin'
									) }
								</p>
							</>
						) }
						<Grid columns={ 1 }>
							<BaseControl
								id="force-2fa-cap"
								label={ __( 'Select the user capability to enforce two-factor authentication', 'newspack-plugin' ) }
							>
								<SelectControl
									label={ __( 'Capability', 'newspack-plugin' ) }
									hideLabelFromVision
									value={ settingsToUpdate?.force_2fa_cap || '' }
									onChange={ ( value: JetpackSSOCaps ) => setSettingsToUpdate( { ...settingsToUpdate, force_2fa_cap: value } ) }
									options={ Object.keys( settings.available_caps || {} ).map( ( cap: string ) => ( {
										label: getCapLabel( cap as JetpackSSOCaps ),
										value: cap,
									} ) ) }
								/>
							</BaseControl>
						</Grid>
						<Grid columns={ 1 }>
							<CheckboxControl
								checked={ settingsToUpdate?.obfuscate_account || false }
								onChange={ value => setSettingsToUpdate( { ...settingsToUpdate, obfuscate_account: value } ) }
								label={ __(
									'Obfuscate restricted accounts by throwing WP’s “user not found” errors on login form attempts.',
									'newspack-plugin'
								) }
							/>
						</Grid>
					</>
				) }
			</ActionCard>
		</>
	);
};

export default JetpackSSO;
