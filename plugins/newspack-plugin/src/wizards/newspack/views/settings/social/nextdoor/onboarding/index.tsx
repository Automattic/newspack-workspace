/**
 * Nextdoor Onboarding View
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement, useState, useEffect } from '@wordpress/element';
import { ExternalLink, Notice as WPNotice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, SelectControl, TextControl } from '../../../../../../../../packages/components/src';
import { OnboardingProps } from '../types';
import ReadonlyField from './readonly-field';
import CopyButton from './copy-button';

/**
 * Styles
 */
import './style.scss';

/**
 * Step configuration.
 */
const STEPS = {
	// When auth is managed by Newspack
	centralized: {
		ACCOUNT_AUTH: 1,
		CLAIM_PAGE: 2,
		SUCCESS: 3,
	},
	// When user provides their own credentials
	manual: {
		CREDENTIALS: 1,
		ACCOUNT_AUTH: 2,
		CLAIM_PAGE: 3,
		SUCCESS: 4,
	},
} as const;

// The OAuth error is attacker-controllable through the redirect link, so it is
// truncated before it can fill the notice with arbitrary prose.
const OAUTH_ERROR_MAX_LENGTH = 200;

/**
 * Onboarding component.
 */
export const Onboarding = ( {
	settings,
	status,
	error,
	updateSettings,
	startOAuthFlow,
	claimPage,
	setError,
	renderSecondaryActions,
}: OnboardingProps ) => {
	const [ clientId, setClientId ] = useState( settings.client_id || '' );
	const [ clientSecret, setClientSecret ] = useState( settings.client_secret || '' );
	// The GET never returns the secret, so a dirty check has to remember what was
	// last submitted from here — `settings` alone would report every visit as dirty.
	const [ savedSecret, setSavedSecret ] = useState( settings.client_secret || '' );
	const [ email, setEmail ] = useState( '' );
	const [ hasBlurredEmail, setHasBlurredEmail ] = useState( false );
	const [ country, setCountry ] = useState( window.newspackSettings?.social?.nextdoor?.default_country || 'US' );
	const [ publicationUrl, setPublicationUrl ] = useState( settings.publication_url || '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ currentStep, setCurrentStep ] = useState( 1 );

	// Get country options and redirect URI from localized data
	const countryOptions = window.newspackSettings?.social?.nextdoor?.country_options || [];
	const redirectUri = window.newspackSettings?.social?.nextdoor?.redirect_uri || '';

	// Decide steps/UI based on auth.
	const steps = status.has_centralized_credentials ? STEPS.centralized : STEPS.manual;
	const isManualMode = 'CREDENTIALS' in steps;

	useEffect( () => {
		// Check URL params for OAuth success
		const urlParams = new URLSearchParams( window.location.search );
		if ( urlParams.get( 'oauth_success' ) === '1' ) {
			setCurrentStep( steps.CLAIM_PAGE );
			setError( null );
		}
	}, [ steps.CLAIM_PAGE ] );

	useEffect( () => {
		// Check for OAuth error in URL params. `get()` has already decoded the
		// value; decoding again throws on any lone `%` an attacker puts in the link.
		const urlParams = new URLSearchParams( window.location.search );
		const oauthError = urlParams.get( 'nextdoor_oauth_error' );

		if ( oauthError ) {
			setError( oauthError.slice( 0, OAUTH_ERROR_MAX_LENGTH ) );
		}
	}, [] );

	useEffect( () => {
		// Determine current step based on connection status
		if ( status.is_connected ) {
			setCurrentStep( steps.SUCCESS );
		} else if ( status.has_tokens ) {
			setCurrentStep( steps.CLAIM_PAGE );
		} else if ( status.has_credentials ) {
			setCurrentStep( steps.ACCOUNT_AUTH );
		} else {
			setCurrentStep( 1 );
		}
	}, [ status, steps ] );

	// Deliberately permissive: the server rejects with is_email(), this only
	// catches the obvious typo before a round trip.
	const isEmailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email.trim() );
	// Only after leaving the field: an empty one is not a mistake worth calling
	// out, and a half-typed address is not either.
	const emailError =
		hasBlurredEmail && email.trim() && ! isEmailValid ? __( 'That does not look like a valid email address.', 'newspack-plugin' ) : null;

	const hasCredentialChanges = clientId !== ( settings.client_id || '' ) || clientSecret !== savedSecret;

	const handleSaveCredentials = async () => {
		if ( ! clientId || ! clientSecret ) {
			setError( __( 'Please enter both Client ID and Client Secret.', 'newspack-plugin' ) );
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			await updateSettings( {
				client_id: clientId,
				client_secret: clientSecret,
			} );
			setSavedSecret( clientSecret );
			setCurrentStep( steps.ACCOUNT_AUTH );
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const handleStartOAuth = async () => {
		if ( ! isEmailValid ) {
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			const response = await startOAuthFlow( email, country );

			// Redirect to login URL
			window.location.href = response.login_url ?? window.location.href;
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const handleClaimPage = async () => {
		if ( ! publicationUrl ) {
			setError( __( 'Please enter your publication URL.', 'newspack-plugin' ) );
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			const result = await claimPage( publicationUrl );
			if ( result.success ) {
				window.location.reload();
			} else {
				setError( __( 'Failed to claim page.', 'newspack-plugin' ) );
			}
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<VStack spacing={ 4 }>
			{ error && (
				<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
					{ error }
				</WPNotice>
			) }

			{ isManualMode && currentStep === STEPS.manual.CREDENTIALS && (
				<VStack spacing={ 4 }>
					<ReadonlyField
						id="nextdoor-onboarding-redirect-uri"
						label={ __( 'Redirect URI', 'newspack-plugin' ) }
						help={ __( 'Use this URL as the Redirect URI when signing up for Nextdoor credentials.', 'newspack-plugin' ) }
						value={ redirectUri }
						isMonospace
					>
						<CopyButton
							value={ redirectUri }
							label={ __( 'Copy Redirect URI', 'newspack-plugin' ) }
							successMessage={ __( 'Redirect URI copied to clipboard.', 'newspack-plugin' ) }
							errorMessage={ __( 'Could not copy the Redirect URI.', 'newspack-plugin' ) }
						/>
					</ReadonlyField>

					<TextControl
						label={ __( 'Client ID', 'newspack-plugin' ) }
						value={ clientId }
						onChange={ setClientId }
						placeholder={ __( 'Enter your Nextdoor Client ID', 'newspack-plugin' ) }
						help={ createInterpolateElement(
							__(
								'The public identifier for your app. Get your API credentials from the <linkToNextdoor>Nextdoor Developer Portal</linkToNextdoor>.',
								'newspack-plugin'
							),
							{
								// createInterpolateElement replaces the child with the tagged text.
								linkToNextdoor: (
									<ExternalLink href="https://developer.nextdoor.com/reference/applying-for-access">{ '' }</ExternalLink>
								),
							}
						) }
						withMargin={ false }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Client Secret', 'newspack-plugin' ) }
						value={ clientSecret }
						onChange={ setClientSecret }
						type="password"
						placeholder={ __( 'Enter your Nextdoor Client Secret', 'newspack-plugin' ) }
						help={ __( 'Issued with the Client ID. Stored securely, and never shown here again.', 'newspack-plugin' ) }
						withMargin={ false }
						__nextHasNoMarginBottom
					/>

					<HStack justify="flex-start" spacing={ 2 }>
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ handleSaveCredentials }
							disabled={ ! clientId || ! clientSecret || ! hasCredentialChanges || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Save Credentials', 'newspack-plugin' ) }
						</Button>
						{ /* Only an escape once there is a saved pair to go back to. */ }
						{ status.has_credentials && (
							<Button variant="secondary" __next40pxDefaultSize onClick={ () => setCurrentStep( steps.ACCOUNT_AUTH ) }>
								{ __( 'Cancel', 'newspack-plugin' ) }
							</Button>
						) }
						{ renderSecondaryActions?.() }
					</HStack>
				</VStack>
			) }

			{ currentStep === steps.ACCOUNT_AUTH && (
				<VStack spacing={ 4 }>
					<VStack spacing={ 0 }>
						<TextControl
							label={ __( 'Email Address', 'newspack-plugin' ) }
							value={ email }
							onChange={ setEmail }
							onBlur={ () => setHasBlurredEmail( true ) }
							type="email"
							placeholder={ __( 'Enter your Nextdoor account email', 'newspack-plugin' ) }
							help={ __( 'This should be the email address associated with your Nextdoor account.', 'newspack-plugin' ) }
							withMargin={ false }
							__nextHasNoMarginBottom
						/>
						{ emailError && <p className="newspack-social-settings__field-error">{ emailError }</p> }
					</VStack>
					<SelectControl
						label={ __( 'Country', 'newspack-plugin' ) }
						value={ country }
						onChange={ setCountry }
						options={ countryOptions }
						help={ __( 'Where your publication is based. Nextdoor creates your publisher account in this country.', 'newspack-plugin' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						// Escape dismisses the select's own menu. Capture phase because CardForm's
						// close listener sits on the body and would otherwise run first.
						onKeyDownCapture={ ( event: React.KeyboardEvent< HTMLSelectElement > ) => {
							if ( 'Escape' === event.key ) {
								event.preventDefault();
							}
						} }
					/>

					<HStack justify="flex-start" spacing={ 2 }>
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ handleStartOAuth }
							disabled={ ! isEmailValid || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Connect Account', 'newspack-plugin' ) }
						</Button>
						{ isManualMode && (
							<Button variant="secondary" __next40pxDefaultSize onClick={ () => setCurrentStep( STEPS.manual.CREDENTIALS ) }>
								{ __( 'Edit Credentials', 'newspack-plugin' ) }
							</Button>
						) }
						{ renderSecondaryActions?.() }
					</HStack>
				</VStack>
			) }

			{ currentStep === steps.CLAIM_PAGE && (
				<VStack spacing={ 4 }>
					<p className="nextdoor-onboarding__intro">
						{ __( 'Claim your news page on Nextdoor to start publishing articles.', 'newspack-plugin' ) }
					</p>

					<TextControl
						label={ __( 'Publication URL', 'newspack-plugin' ) }
						value={ publicationUrl }
						onChange={ setPublicationUrl }
						type="url"
						placeholder={ __( 'https://yoursite.com', 'newspack-plugin' ) }
						help={ __( 'The main URL of your news publication.', 'newspack-plugin' ) }
						withMargin={ false }
						__nextHasNoMarginBottom
					/>

					<HStack justify="flex-start" spacing={ 2 }>
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ handleClaimPage }
							disabled={ ! publicationUrl || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Claim Page', 'newspack-plugin' ) }
						</Button>
						<Button variant="secondary" __next40pxDefaultSize onClick={ () => setCurrentStep( steps.ACCOUNT_AUTH ) }>
							{ __( 'Change Account', 'newspack-plugin' ) }
						</Button>
						{ renderSecondaryActions?.() }
					</HStack>
				</VStack>
			) }
		</VStack>
	);
};

export default Onboarding;
