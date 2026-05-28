/**
 * Settings Wizard: Connections > reCAPTCHA
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, ExternalLink } from '@wordpress/components';
import { useEffect, useState, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ERROR_MESSAGES } from './constants';
import WizardsActionCard from '../../../../wizards-action-card';
import WizardError from '../../../../errors/class-wizard-error';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { Grid, Button, TextControl, SelectControl } from '../../../../../../packages/components/src';

const settingsDefault: RecaptchaData = {
	threshold: '',
	use_captcha: false,
	version: 'v3',
	credentials: {
		v2_invisible: { site_key: '', site_secret: '' },
		v3: { site_key: '', site_secret: '' },
	},
};

type RecaptchaDependsOn = Record< keyof RecaptchaData, string >;

const fieldValidationMap = new Map<
	keyof Omit< RecaptchaData, 'use_captcha' >,
	{
		callback: ( value: any, version?: RecaptchaVersions ) => string;
		dependsOn?: RecaptchaDependsOn;
	}
>( [
	[
		'credentials',
		{
			dependsOn: { version: 'v3' },
			callback: ( credentials: RecaptchaData[ 'credentials' ], version = 'v3' ) => {
				if ( ! credentials[ version ].site_key ) {
					return ERROR_MESSAGES.RECAPTCHA.SITE_KEY_EMPTY;
				}
				if ( ! credentials[ version ].site_secret ) {
					return ERROR_MESSAGES.RECAPTCHA.SITE_SECRET_EMPTY;
				}
				return '';
			},
		},
	],
	[
		'threshold',
		{
			dependsOn: { version: 'v3' },
			callback: value => {
				const threshold = parseFloat( value || '0' );
				if ( threshold < 0.1 ) {
					return ERROR_MESSAGES.RECAPTCHA.THRESHOLD_INVALID_MIN;
				}
				if ( threshold > 1 ) {
					return ERROR_MESSAGES.RECAPTCHA.THRESHOLD_INVALID_MAX;
				}
				return '';
			},
		},
	],
] );

const apiPath = '/newspack/v1/recaptcha';

function Recaptcha() {
	const { wizardApiFetch, isFetching, errorMessage, setError, resetError } = useWizardApiFetch( '/newspack-settings/connections/recaptcha' );

	const [ settings, setSettings ] = useState< RecaptchaData >( {
		...settingsDefault,
	} );
	const [ settingsToUpdate, setSettingsToUpdate ] = useState< RecaptchaData >( {
		...settingsDefault,
	} );
	const credentials = settingsToUpdate.credentials || {};
	const versionCredentials = credentials[ settingsToUpdate.version ];

	useEffect( () => {
		wizardApiFetch< RecaptchaData >(
			{
				path: apiPath,
			},
			{
				onSuccess( fetchedSettings ) {
					setSettings( fetchedSettings );
					setSettingsToUpdate( fetchedSettings );
				},
			}
		);
	}, [] );

	function updateSettings( data: RecaptchaData, isToggleSave = false ) {
		resetError();

		// Perform validation on non `use_captcha` updates.
		if ( ! isToggleSave ) {
			for ( const [ field, validate ] of fieldValidationMap ) {
				if ( validate.dependsOn ) {
					const [ [ key, value ] ] = Object.entries( validate.dependsOn );
					if ( settingsToUpdate[ key as keyof RecaptchaDependsOn ] !== value ) {
						continue;
					}
				}
				const validationError = validate.callback( settingsToUpdate[ field ], settingsToUpdate.version );
				if ( validationError ) {
					setError( new WizardError( validationError, field ) );
					return;
				}
			}
		}

		wizardApiFetch< RecaptchaData >(
			{
				path: apiPath,
				method: 'POST',
				data,
				updateCacheMethods: [ 'GET' ],
			},
			{
				onSuccess( fetchedSettings ) {
					setSettings( fetchedSettings );
					setSettingsToUpdate( fetchedSettings );
				},
			}
		);
	}

	function onCredentialsChange( field: 'site_key' | 'site_secret', value: string ) {
		setSettingsToUpdate( prev => ( {
			...prev,
			credentials: {
				...prev.credentials,
				[ prev.version ]: {
					...prev.credentials[ prev.version ],
					[ field ]: value,
				},
			},
		} ) );
	}

	return (
		<WizardsActionCard
			isMedium
			title={ __( 'Use reCAPTCHA', 'newspack-plugin' ) }
			description={ () => (
				<Fragment>
					{ isFetching && ! settings.use_captcha ? (
						__( 'Loading…', 'newspack-plugin' )
					) : (
						<>
							{ __( 'Enabling reCAPTCHA can help protect your site against bot attacks and credit card testing.', 'newspack-plugin' ) }{ ' ' }
							<ExternalLink href="https://www.google.com/recaptcha/admin/create">
								{ __( 'Get started', 'newspack-plugin' ) }
							</ExternalLink>
						</>
					) }
				</Fragment>
			) }
			hasGreyHeader={ !! settings.use_captcha }
			toggleChecked={ !! settings.use_captcha }
			toggleOnChange={ () =>
				updateSettings(
					{
						...settings,
						use_captcha: ! settings.use_captcha,
					},
					true
				)
			}
			actionContent={
				settings.use_captcha && (
					<Button
						variant="primary"
						disabled={ isFetching || ! Object.keys( settingsToUpdate ).length }
						onClick={ () => updateSettings( settingsToUpdate ) }
					>
						{ isFetching ? __( 'Loading…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
					</Button>
				)
			}
			error={ settings.use_captcha ? errorMessage : null }
			disabled={ isFetching }
		>
			{ settings.use_captcha && (
				<Fragment>
					<Grid noMargin rowGap={ 16 }>
						<BaseControl
							id="recaptcha-version"
							label={ __( 'reCAPTCHA Version', 'newspack-plugin' ) }
							help={
								<ExternalLink href="https://developers.google.com/recaptcha/docs/versions">
									{ __( 'Learn more about reCAPTCHA versions', 'newspack-plugin' ) }
								</ExternalLink>
							}
						>
							<SelectControl
								label={ __( 'reCAPTCHA Version', 'newspack-plugin' ) }
								hideLabelFromVision
								value={ settingsToUpdate.version || 'v3' }
								onChange={ ( version: RecaptchaVersions ) =>
									setSettingsToUpdate( {
										...settingsToUpdate,
										version,
									} )
								}
								// Note: add 'v2_checkbox' here and in Recaptcha::SUPPORTED_VERSIONS to add support for the Checkbox flavor of reCAPTCHA v2.
								options={ [
									{
										value: 'v3',
										label: __( 'Score based (v3)', 'newspack-plugin' ),
									},
									{
										value: 'v2_invisible',
										label: __( 'Challenge (v2) - invisible reCAPTCHA badge', 'newspack-plugin' ),
									},
								] }
							/>
						</BaseControl>
					</Grid>
					<Grid noMargin rowGap={ 16 }>
						<TextControl
							value={ versionCredentials.site_key || '' }
							label={ __( 'Site Key', 'newspack-plugin' ) }
							onChange={ ( value: string ) => onCredentialsChange( 'site_key', value ) }
							disabled={ isFetching }
							autoComplete="off"
						/>
						<TextControl
							type="password"
							value={ versionCredentials.site_secret || '' }
							label={ __( 'Site Secret', 'newspack-plugin' ) }
							onChange={ ( value: string ) => onCredentialsChange( 'site_secret', value ) }
							disabled={ isFetching }
							autoComplete="one-time-code"
						/>
						{ settingsToUpdate.version === 'v3' && (
							<TextControl
								type="number"
								step="0.05"
								min="0.1"
								max="1"
								value={ parseFloat( settingsToUpdate?.threshold || '0' ) }
								label={ __( 'Threshold', 'newspack-plugin' ) }
								onChange={ ( value: string ) =>
									setSettingsToUpdate( {
										...settingsToUpdate,
										threshold: value,
									} )
								}
								disabled={ isFetching }
								help={
									<ExternalLink href="https://developers.google.com/recaptcha/docs/v3#interpreting_the_score">
										{ __( 'Learn more about the threshold value', 'newspack-plugin' ) }
									</ExternalLink>
								}
							/>
						) }
					</Grid>
				</Fragment>
			) }
		</WizardsActionCard>
	);
}

export default Recaptcha;
