/**
 * Nextdoor Form View
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createInterpolateElement, useState, useEffect } from '@wordpress/element';
import {
	CheckboxControl,
	ExternalLink,
	Notice as WPNotice,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Button, Grid, SelectControl, TextControl } from '../../../../../../../../packages/components/src';
import { useSocialCards } from '../../context';
import { NextdoorFormProps } from '../types';
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

const isSameRoles = ( current: string[], stored: string[] ) => current.length === stored.length && current.every( role => stored.includes( role ) );

/**
 * Nextdoor form.
 *
 * One screen rather than a wizard: signing in leaves the page and returns on a
 * fresh load, so a step counter would only ever restate what the server already
 * reports. Each section unlocks on the connection status instead.
 */
export const NextdoorForm = ( {
	settings,
	status,
	error,
	updateSettings,
	startOAuthFlow,
	claimPage,
	setError,
	renderSecondaryActions,
}: NextdoorFormProps ) => {
	const { notify } = useSocialCards();
	const [ clientId, setClientId ] = useState( settings.client_id || '' );
	// Always starts blank: the GET never returns the secret. Left empty on a
	// later save, the server keeps the stored one.
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ hasBlurredEmail, setHasBlurredEmail ] = useState( false );
	const [ country, setCountry ] = useState( window.newspackSettings?.social?.nextdoor?.default_country || 'US' );
	const [ publicationUrl, setPublicationUrl ] = useState( settings.publication_url || window.newspackSettings?.social?.nextdoor?.site_url || '' );
	const [ allowedRoles, setAllowedRoles ] = useState< string[] >( settings.allowed_roles || [] );
	const [ isSaving, setIsSaving ] = useState( false );
	// The only navigation left: reopening the connect form once it has been put
	// away, so changing credentials or account is not a dead end.
	const [ isEditingConnection, setIsEditingConnection ] = useState( false );

	const availableRoles = window.newspackSettings?.social?.nextdoor?.available_roles || [];
	const countryOptions = window.newspackSettings?.social?.nextdoor?.country_options || [];
	const redirectUri = window.newspackSettings?.social?.nextdoor?.redirect_uri || '';

	// Newspack supplies the credentials on some sites, and there is nothing for
	// the publisher to enter or change when it does.
	const isManualMode = ! status.has_centralized_credentials;

	useEffect( () => {
		setAllowedRoles( settings.allowed_roles || [] );
		setPublicationUrl( settings.publication_url || window.newspackSettings?.social?.nextdoor?.site_url || '' );
	}, [ settings ] );

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
	const hasCredentials = ! isManualMode || ( !! clientId && ( status.has_credentials || !! clientSecret ) );
	const canConnect = hasCredentials && isEmailValid;
	// Claiming is the only thing left once Nextdoor has authorised, unless the
	// publisher asks to change the connection.
	const showConnect = ! status.has_tokens || isEditingConnection;

	const hasRoleChanges = ! isSameRoles( allowedRoles, settings.allowed_roles || [] );
	const hasUrlChanges = publicationUrl !== ( settings.publication_url || '' );

	const handleConnect = async () => {
		if ( ! canConnect ) {
			return;
		}
		try {
			setIsSaving( true );
			setError( null );
			// The server reads the credentials out of options when it calls Nextdoor,
			// so they have to land before the OAuth request goes out. Omitting the
			// secret is what tells it to keep the stored one.
			if ( isManualMode && hasCredentialChanges ) {
				await updateSettings( clientSecret ? { client_id: clientId, client_secret: clientSecret } : { client_id: clientId } );
			}
			const response = await startOAuthFlow( email, country );
			window.location.href = response.login_url ?? window.location.href;
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const handleSubmit = async () => {
		// Before a page exists every submission claims one; afterwards only a
		// changed URL does, since `publication_url` is not a settings write param.
		const shouldClaim = ! status.has_page || hasUrlChanges;
		if ( shouldClaim && ! publicationUrl ) {
			return;
		}
		if ( ! shouldClaim && ! hasRoleChanges ) {
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			// Roles first: a successful claim reloads the page, so the reload lands
			// on state that is already persisted.
			if ( hasRoleChanges ) {
				await updateSettings( { allowed_roles: allowedRoles } );
			}
			if ( shouldClaim ) {
				const result = await claimPage( publicationUrl );
				if ( result.success ) {
					window.location.reload();
					return;
				}
				setError( __( 'Failed to claim page.', 'newspack-plugin' ) );
				return;
			}
			notify( __( 'Nextdoor settings updated.', 'newspack-plugin' ) );
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const primary = showConnect ? (
		<Button
			variant="primary"
			__next40pxDefaultSize
			aria-label={ __( 'Connect to Nextdoor', 'newspack-plugin' ) }
			onClick={ handleConnect }
			disabled={ ! canConnect || isSaving }
			isBusy={ isSaving }
		>
			{ __( 'Connect', 'newspack-plugin' ) }
		</Button>
	) : (
		<Button
			variant="primary"
			__next40pxDefaultSize
			onClick={ handleSubmit }
			disabled={ ( status.has_page ? ! hasRoleChanges && ! hasUrlChanges : ! publicationUrl ) || isSaving }
			isBusy={ isSaving }
		>
			{ status.has_page ? __( 'Save', 'newspack-plugin' ) : __( 'Claim Page', 'newspack-plugin' ) }
		</Button>
	);

	const secondary = ( () => {
		if ( ! showConnect ) {
			return (
				<Button variant="secondary" __next40pxDefaultSize onClick={ () => setIsEditingConnection( true ) }>
					{ __( 'Update Connection', 'newspack-plugin' ) }
				</Button>
			);
		}
		if ( status.has_tokens ) {
			return (
				<Button variant="secondary" __next40pxDefaultSize onClick={ () => setIsEditingConnection( false ) }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
			);
		}
		return null;
	} )();

	return (
		<VStack spacing={ 6 }>
			{ error && (
				<WPNotice status="error" isDismissible={ false } spokenMessage={ null }>
					{ error }
				</WPNotice>
			) }

			<VStack spacing={ 4 }>
				{ showConnect ? (
					<>
						{ isManualMode && (
							<>
								<ReadonlyField
									id="nextdoor-form-redirect-uri"
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
												<ExternalLink href="https://developer.nextdoor.com/reference/applying-for-access">
													{ '' }
												</ExternalLink>
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
									placeholder={
										status.has_credentials ? STORED_SECRET_MASK : __( 'Enter your Nextdoor Client Secret', 'newspack-plugin' )
									}
									help={
										status.has_credentials
											? __( 'Leave blank to keep the stored secret, or enter a new one to replace it.', 'newspack-plugin' )
											: __( 'Issued with the Client ID. Stored securely, and never shown here again.', 'newspack-plugin' )
									}
									withMargin={ false }
									__nextHasNoMarginBottom
								/>
							</>
						) }
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
							help={ __(
								'Where your publication is based. Nextdoor creates your publisher account in this country.',
								'newspack-plugin'
							) }
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
					</>
				) : (
					<>
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
						{ /* A disabled checkbox is not focusable and announces nothing, so the prose accounts for the inert roles. */ }
						<p className="nextdoor-form__intro">
							{ __( 'Select which user roles are allowed to publish articles to Nextdoor.', 'newspack-plugin' ) }
							{ ! status.has_page && ` ${ __( 'Available once the page is claimed.', 'newspack-plugin' ) }` }
						</p>
						<Grid columns={ 2 } gutter={ 16 } noMargin>
							{ availableRoles.map( ( { label, value } ) => (
								<CheckboxControl
									key={ value }
									label={ label }
									checked={ allowedRoles.includes( value ) || 'administrator' === value }
									onChange={ ( checked: boolean ) =>
										setAllowedRoles( checked ? [ ...allowedRoles, value ] : allowedRoles.filter( role => role !== value ) )
									}
									disabled={ ! status.has_page || 'administrator' === value }
									help={
										'administrator' === value
											? __( 'Administrators always have publishing permissions.', 'newspack-plugin' )
											: undefined
									}
								/>
							) ) }
						</Grid>
					</>
				) }
				<HStack justify="flex-start" spacing={ 2 }>
					{ primary }
					{ secondary }
					{ renderSecondaryActions?.() }
				</HStack>
			</VStack>
		</VStack>
	);
};

export default NextdoorForm;
