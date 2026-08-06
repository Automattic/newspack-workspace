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

// Stands in for a secret the server holds but never returns.
const STORED_SECRET_MASK = '\u2022'.repeat( 12 );

// The OAuth error is attacker-controllable through the redirect link, so it is
// truncated before it can fill the notice with arbitrary prose.
const OAUTH_ERROR_MAX_LENGTH = 200;

/**
 * Onboarding view.
 *
 * One screen rather than a wizard: signing in leaves the page and returns on a
 * fresh load, so a step counter would only ever restate what the server already
 * reports. Each section unlocks on the connection status instead.
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
	// Always starts blank: the GET never returns the secret. Left empty on a
	// later save, the server keeps the stored one.
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ hasBlurredEmail, setHasBlurredEmail ] = useState( false );
	const [ country, setCountry ] = useState( window.newspackSettings?.social?.nextdoor?.default_country || 'US' );
	const [ publicationUrl, setPublicationUrl ] = useState( settings.publication_url || window.newspackSettings?.social?.nextdoor?.site_url || '' );
	const [ isSaving, setIsSaving ] = useState( false );

	const countryOptions = window.newspackSettings?.social?.nextdoor?.country_options || [];
	const redirectUri = window.newspackSettings?.social?.nextdoor?.redirect_uri || '';

	// Newspack supplies the credentials on some sites, and there is nothing for
	// the publisher to enter or change when it does.
	const isManualMode = ! status.has_centralized_credentials;
	const canConnectAccount = status.has_credentials;
	const canClaimPage = status.has_tokens;

	useEffect( () => {
		// `get()` has already decoded the value; decoding again throws on any lone
		// `%` an attacker puts in the link.
		const oauthError = new URLSearchParams( window.location.search ).get( 'nextdoor_oauth_error' );
		if ( oauthError ) {
			setError( oauthError.slice( 0, OAUTH_ERROR_MAX_LENGTH ) );
		}
	}, [] );

	// Shape only, but stricter than is_email(), which accepts a one-letter TLD.
	// The lookahead keeps an all-numeric TLD out while still allowing a
	// non-ASCII one and its punycode form. Whether the address exists is
	// Nextdoor's problem, not something a pattern can answer.
	const isEmailValid = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.(?=[^\s@.]*\p{L})[^\s@.]{2,}$/u.test( email.trim() );
	// Only after leaving the field: an empty one is not a mistake worth calling
	// out, and a half-typed address is not either.
	const emailError =
		hasBlurredEmail && email.trim() && ! isEmailValid ? __( 'That does not look like a valid email address.', 'newspack-plugin' ) : null;

	// A blank secret is a change only before there is one stored to fall back on.
	const hasCredentialChanges = clientId !== ( settings.client_id || '' ) || !! clientSecret;
	const canSaveCredentials = !! clientId && ( canConnectAccount || !! clientSecret ) && hasCredentialChanges;

	const handleSaveCredentials = async () => {
		if ( ! canSaveCredentials ) {
			return;
		}
		try {
			setIsSaving( true );
			setError( null );
			// Omitting the secret is what tells the server to keep the stored one.
			await updateSettings( clientSecret ? { client_id: clientId, client_secret: clientSecret } : { client_id: clientId } );
			setClientSecret( '' );
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
		<VStack spacing={ 6 }>
			{ error && (
				<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
					{ error }
				</WPNotice>
			) }

			{ isManualMode && (
				<VStack spacing={ 4 }>
					<h4 className="nextdoor-onboarding__subheading">{ __( 'API Credentials', 'newspack-plugin' ) }</h4>
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
						// Dots stand in for the stored secret. The field itself stays empty,
						// which is what tells the server to keep what it already has.
						placeholder={ canConnectAccount ? STORED_SECRET_MASK : __( 'Enter your Nextdoor Client Secret', 'newspack-plugin' ) }
						help={
							canConnectAccount
								? __( 'Leave blank to keep the stored secret, or enter a new one to replace it.', 'newspack-plugin' )
								: __( 'Issued with the Client ID. Stored securely, and never shown here again.', 'newspack-plugin' )
						}
						withMargin={ false }
						__nextHasNoMarginBottom
					/>

					<HStack justify="flex-start" spacing={ 2 }>
						<Button
							variant="primary"
							__next40pxDefaultSize
							onClick={ handleSaveCredentials }
							disabled={ ! canSaveCredentials || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Save Credentials', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			) }

			<VStack spacing={ 4 }>
				<h4 className="nextdoor-onboarding__subheading">{ __( 'Nextdoor Account', 'newspack-plugin' ) }</h4>
				{ ! canConnectAccount && (
					<p className="newspack-text-muted nextdoor-onboarding__intro">
						{ __( 'Available once your API credentials are saved.', 'newspack-plugin' ) }
					</p>
				) }
				{ canClaimPage ? (
					<p className="newspack-text-muted nextdoor-onboarding__intro">
						{ __( 'Your Nextdoor account is connected.', 'newspack-plugin' ) }
					</p>
				) : (
					<>
						<VStack spacing={ 0 }>
							<TextControl
								label={ __( 'Email Address', 'newspack-plugin' ) }
								value={ email }
								onChange={ setEmail }
								onBlur={ () => setHasBlurredEmail( true ) }
								type="email"
								placeholder={ __( 'Enter your Nextdoor account email', 'newspack-plugin' ) }
								help={ __( 'This should be the email address associated with your Nextdoor account.', 'newspack-plugin' ) }
								disabled={ ! canConnectAccount }
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
							help={ __(
								'Where your publication is based. Nextdoor creates your publisher account in this country.',
								'newspack-plugin'
							) }
							disabled={ ! canConnectAccount }
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
								disabled={ ! canConnectAccount || ! isEmailValid || isSaving }
								isBusy={ isSaving }
							>
								{ __( 'Connect Account', 'newspack-plugin' ) }
							</Button>
						</HStack>
					</>
				) }
			</VStack>

			<VStack spacing={ 4 }>
				<h4 className="nextdoor-onboarding__subheading">{ __( 'Publication Page', 'newspack-plugin' ) }</h4>
				{ ! canClaimPage && (
					<p className="newspack-text-muted nextdoor-onboarding__intro">
						{ __( 'Available once your Nextdoor account is connected.', 'newspack-plugin' ) }
					</p>
				) }
				<TextControl
					label={ __( 'Publication URL', 'newspack-plugin' ) }
					value={ publicationUrl }
					onChange={ setPublicationUrl }
					type="url"
					placeholder={ __( 'https://yoursite.com', 'newspack-plugin' ) }
					help={ __( 'The main URL of your news publication.', 'newspack-plugin' ) }
					disabled={ ! canClaimPage }
					withMargin={ false }
					__nextHasNoMarginBottom
				/>
				<HStack justify="flex-start" spacing={ 2 }>
					<Button
						variant="primary"
						__next40pxDefaultSize
						onClick={ handleClaimPage }
						disabled={ ! canClaimPage || ! publicationUrl || isSaving }
						isBusy={ isSaving }
					>
						{ __( 'Claim Page', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>

			{ renderSecondaryActions && (
				<HStack justify="flex-start" spacing={ 2 }>
					{ renderSecondaryActions() }
				</HStack>
			) }
		</VStack>
	);
};

export default Onboarding;
