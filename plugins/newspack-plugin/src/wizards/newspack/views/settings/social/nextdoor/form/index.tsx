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
	SelectControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { Button, Grid, TextControl } from '../../../../../../../../packages/components/src';
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

const OAUTH_PARAMS = [ 'nextdoor_oauth_error', 'oauth_success' ];

// A one-time message, dropped from the URL once read: the card stays reopenable for the
// rest of the page's life and a stale failure must not come back with it. `nextdoor.tsx`
// reads the same parameters on its first render, which always precedes this mount.
const consumeOAuthParams = () => {
	const params = new URLSearchParams( window.location.search );
	if ( ! OAUTH_PARAMS.some( param => params.has( param ) ) ) {
		return;
	}
	OAUTH_PARAMS.forEach( param => params.delete( param ) );
	const query = params.toString();
	window.history.replaceState( null, '', `${ window.location.pathname }${ query ? `?${ query }` : '' }${ window.location.hash }` );
};

const isSameRoles = ( current: string[], stored: string[] ) => current.length === stored.length && current.every( role => stored.includes( role ) );

// `aria-errormessage` is unimplemented in WebKit, so the error joins `aria-describedby`.
// `__help` is BaseControl's own help-text id, kept so that association survives.
const describedBy = ( fieldId: string, errorId: string | null ) => [ `${ fieldId }__help`, errorId ].filter( Boolean ).join( ' ' );

// Anything outside this set is stripped or percent-encoded by the server's esc_url_raw()
// check, which then rejects the value. URL() would normalise the same characters and
// report it valid, enabling the button on a URL that comes back as an unattributed error.
const UNSTORABLE_CHARACTER = /[^-a-z0-9~+_.?#=!&;,/:%@$|*'()[\]\u0080-\uffff]/i;

// esc_url() additionally runs _deep_replace() over these two, so a URL carrying either
// survives the character check and comes back mangled rather than rejected.
const STRIPPED_ESCAPE = /%0[da]/i;

// Shape only: a scheme Nextdoor can fetch and a host that could resolve.
const isPublicationUrlValid = ( value: string ) => {
	const trimmed = value.trim();
	if ( UNSTORABLE_CHARACTER.test( trimmed ) || STRIPPED_ESCAPE.test( trimmed ) ) {
		return false;
	}
	try {
		const url = new URL( trimmed );
		return ( 'http:' === url.protocol || 'https:' === url.protocol ) && url.hostname.includes( '.' );
	} catch {
		return false;
	}
};

/**
 * Nextdoor form.
 *
 * One screen rather than a wizard: signing in leaves the page and returns on a fresh load,
 * so a step counter would only restate what the server reports. Sections unlock on status.
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

	// The parent rebuilds `settings` on every save, so the mirrored fields are keyed on the
	// stored values: a save returning a field unchanged must not reset a live draft.
	const storedClientId = settings.client_id || '';
	const storedPublicationUrl = settings.publication_url || window.newspackSettings?.social?.nextdoor?.site_url || '';
	// Serialised rather than joined: role slugs are not sanitised by `add_role()`,
	// so a slug containing the separator would otherwise collide with two roles.
	const storedRolesKey = JSON.stringify( [ ...( settings.allowed_roles || [] ) ].sort() );

	const [ clientId, setClientId ] = useState( storedClientId );
	// Always starts blank: the GET never returns the secret. Left empty on a
	// later save, the server keeps the stored one.
	const [ clientSecret, setClientSecret ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ hasBlurredEmail, setHasBlurredEmail ] = useState( false );
	const [ country, setCountry ] = useState( window.newspackSettings?.social?.nextdoor?.default_country || 'US' );
	const [ publicationUrl, setPublicationUrl ] = useState( storedPublicationUrl );
	const [ hasBlurredPublicationUrl, setHasBlurredPublicationUrl ] = useState( false );
	const [ allowedRoles, setAllowedRoles ] = useState< string[] >( settings.allowed_roles || [] );
	const [ isSaving, setIsSaving ] = useState( false );
	// `null` follows the connection status, `true` reopens the connect form and `false` puts
	// it away, so neither changing the connection nor dismissing a prompt is a dead end.
	const [ connectOverride, setConnectOverride ] = useState< boolean | null >( null );

	// More than one form renders per page, so every id is scoped to the instance.
	const instanceId = useInstanceId( NextdoorForm, 'newspack-nextdoor-form' );
	const redirectUriId = `${ instanceId }-redirect-uri`;
	const emailId = `${ instanceId }-email`;
	const emailErrorId = `${ emailId }-error`;
	const publicationUrlId = `${ instanceId }-publication-url`;
	const publicationUrlErrorId = `${ publicationUrlId }-error`;
	const rolesDescriptionId = `${ instanceId }-roles-description`;

	const availableRoles = window.newspackSettings?.social?.nextdoor?.available_roles || [];
	const countryOptions = window.newspackSettings?.social?.nextdoor?.country_options || [];
	const redirectUri = window.newspackSettings?.social?.nextdoor?.redirect_uri || '';

	// Newspack supplies the credentials on some sites, leaving nothing to enter or change.
	const isManualMode = ! status.has_centralized_credentials;

	useEffect( () => setClientId( storedClientId ), [ storedClientId ] );
	useEffect( () => setPublicationUrl( storedPublicationUrl ), [ storedPublicationUrl ] );
	useEffect( () => setAllowedRoles( settings.allowed_roles || [] ), [ storedRolesKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		// `get()` has already decoded the value; decoding again throws on any lone
		// `%` an attacker puts in the link.
		const oauthError = new URLSearchParams( window.location.search ).get( 'nextdoor_oauth_error' );
		if ( oauthError ) {
			setError( oauthError.slice( 0, OAUTH_ERROR_MAX_LENGTH ) );
		}
		consumeOAuthParams();
	}, [] );

	// Shape only, but stricter than is_email(), which accepts a one-letter TLD; the lookahead
	// keeps an all-numeric one out. The alphabet is is_email()'s ASCII, so the field cannot
	// enable Connect on an address the endpoint refuses.
	const isEmailValid = /^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~.-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.(?=[A-Za-z0-9-]*[A-Za-z])[A-Za-z0-9-]{2,}$/.test(
		email.trim()
	);
	// Only after leaving the field: an empty or half-typed address is not a mistake yet.
	const emailError =
		hasBlurredEmail && email.trim() && ! isEmailValid ? __( 'That does not look like a valid email address.', 'newspack-plugin' ) : null;
	const isUrlValid = isPublicationUrlValid( publicationUrl );
	const publicationUrlError = ( () => {
		if ( ! hasBlurredPublicationUrl ) {
			return null;
		}
		if ( ! publicationUrl ) {
			return __( 'Enter the URL of your publication.', 'newspack-plugin' );
		}
		return isUrlValid ? null : __( 'That does not look like a valid URL.', 'newspack-plugin' );
	} )();

	// A blank secret is a change only before there is one stored to fall back on.
	const hasCredentialChanges = clientId !== storedClientId || !! clientSecret;
	const hasCredentials = ! isManualMode || ( !! clientId && ( status.has_credentials || !! clientSecret ) );
	const canConnect = hasCredentials && isEmailValid;
	const connectHint = ( () => {
		if ( canConnect ) {
			return undefined;
		}
		return hasCredentials
			? __( 'Enter a valid email address to connect.', 'newspack-plugin' )
			: __( 'Enter your Nextdoor credentials to connect.', 'newspack-plugin' );
	} )();
	// An expired token is still a token, but it cannot claim anything, so the body
	// has to offer the reconnection its badge advertises.
	const needsConnect = ! status.has_tokens || ! status.token_valid;
	// Claiming is all that is left once Nextdoor has authorised, unless the connection changes.
	const showConnect = connectOverride ?? needsConnect;

	const hasRoleChanges = ! isSameRoles( allowedRoles, settings.allowed_roles || [] );
	const hasUrlChanges = publicationUrl !== ( settings.publication_url || '' );
	// Before a page exists every submission claims one; afterwards only a changed URL does,
	// since `publication_url` is not a settings write param. Validity gates only the claim,
	// so a role-only save is unaffected by a stored URL this rejects.
	const shouldClaim = ! status.has_page || hasUrlChanges;
	const canSubmit = shouldClaim ? isUrlValid : hasRoleChanges;

	const handleConnect = async () => {
		if ( ! canConnect ) {
			return;
		}
		try {
			setIsSaving( true );
			setError( null );
			// The server reads credentials from options when it calls Nextdoor, so they have to
			// land before the OAuth request. Omitting the secret keeps the stored one.
			if ( isManualMode && hasCredentialChanges ) {
				await updateSettings( clientSecret ? { client_id: clientId, client_secret: clientSecret } : { client_id: clientId } );
			}
			// Trimmed to match what the button was enabled on: the endpoint validates
			// before it sanitises, so surrounding whitespace comes back rejected.
			const response = await startOAuthFlow( email.trim(), country );
			window.location.href = response.login_url ?? window.location.href;
		} catch {
			// Already surfaced by the card's error notice.
		} finally {
			setIsSaving( false );
		}
	};

	const handleSubmit = async () => {
		if ( shouldClaim && ! isUrlValid ) {
			return;
		}
		if ( ! shouldClaim && ! hasRoleChanges ) {
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			// Roles first: a successful claim reloads, so the reload lands on persisted state.
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
			accessibleWhenDisabled
			description={ connectHint }
			isBusy={ isSaving }
		>
			{ __( 'Connect', 'newspack-plugin' ) }
		</Button>
	) : (
		<Button
			variant="primary"
			__next40pxDefaultSize
			onClick={ handleSubmit }
			disabled={ ! canSubmit || isSaving }
			accessibleWhenDisabled
			description={ shouldClaim && ! isUrlValid ? __( 'Enter a valid publication URL to continue.', 'newspack-plugin' ) : undefined }
			isBusy={ isSaving }
		>
			{ status.has_page ? __( 'Save', 'newspack-plugin' ) : __( 'Claim Page', 'newspack-plugin' ) }
		</Button>
	);

	const secondary = ( () => {
		if ( ! showConnect ) {
			return (
				<Button variant="secondary" __next40pxDefaultSize onClick={ () => setConnectOverride( true ) }>
					{ __( 'Update Connection', 'newspack-plugin' ) }
				</Button>
			);
		}
		// Only an offer to go back, so it is withheld when the connect form is all there is
		// to act on. A claimed page is something to return to, even while the token renews.
		if ( ! needsConnect || status.has_page ) {
			return (
				<Button variant="secondary" __next40pxDefaultSize onClick={ () => setConnectOverride( false ) }>
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
									id={ redirectUriId }
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
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<TextControl
									label={ __( 'Client Secret', 'newspack-plugin' ) }
									value={ clientSecret }
									onChange={ setClientSecret }
									type="password"
									// Dots stand in for the stored secret; the field stays empty, which is
									// what tells the server to keep what it has.
									placeholder={
										status.has_credentials ? STORED_SECRET_MASK : __( 'Enter your Nextdoor Client Secret', 'newspack-plugin' )
									}
									help={
										status.has_credentials
											? __( 'Leave blank to keep the stored secret, or enter a new one to replace it.', 'newspack-plugin' )
											: __( 'Issued with the Client ID. Stored securely, and never shown here again.', 'newspack-plugin' )
									}
									withMargin={ false }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
							</>
						) }
						<VStack spacing={ 2 }>
							<TextControl
								id={ emailId }
								label={ __( 'Email Address', 'newspack-plugin' ) }
								value={ email }
								onChange={ setEmail }
								onBlur={ () => setHasBlurredEmail( true ) }
								type="email"
								placeholder={ __( 'Enter your Nextdoor account email', 'newspack-plugin' ) }
								help={ __( 'This should be the email address associated with your Nextdoor account.', 'newspack-plugin' ) }
								aria-invalid={ !! emailError }
								aria-describedby={ describedBy( emailId, emailError ? emailErrorId : null ) }
								withMargin={ false }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							{ emailError && (
								<p className="newspack-social-settings__field-error" id={ emailErrorId }>
									{ emailError }
								</p>
							) }
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
						<VStack spacing={ 2 }>
							<TextControl
								id={ publicationUrlId }
								label={ __( 'Publication URL', 'newspack-plugin' ) }
								value={ publicationUrl }
								onChange={ setPublicationUrl }
								onBlur={ () => setHasBlurredPublicationUrl( true ) }
								type="url"
								placeholder="https://yoursite.com"
								help={ __( 'The main URL of your news publication.', 'newspack-plugin' ) }
								aria-invalid={ !! publicationUrlError }
								aria-describedby={ describedBy( publicationUrlId, publicationUrlError ? publicationUrlErrorId : null ) }
								withMargin={ false }
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							{ publicationUrlError && (
								<p className="newspack-social-settings__field-error" id={ publicationUrlErrorId }>
									{ publicationUrlError }
								</p>
							) }
						</VStack>
						{ /* Grouped so the description reads as the group's own, rather than sitting equidistant from the field above it. */ }
						<VStack spacing={ 2 }>
							{ /* Disabled checkboxes are still announced, but leave tab order and form-control quick-nav, so the prose accounts for the inert roles. */ }
							<p className="nextdoor-form__intro" id={ rolesDescriptionId }>
								{ __( 'Select which user roles are allowed to publish articles to Nextdoor.', 'newspack-plugin' ) }
								{ ! status.has_page && ` ${ __( 'Available once the page is claimed.', 'newspack-plugin' ) }` }
							</p>
							{ /* Named as well as described: an unnamed group is skipped, taking its description with it. */ }
							<Grid
								columns={ 2 }
								gutter={ 16 }
								noMargin
								role="group"
								aria-label={ __( 'Publishing roles', 'newspack-plugin' ) }
								aria-describedby={ rolesDescriptionId }
							>
								{ availableRoles.map( ( { label, value } ) => (
									<CheckboxControl
										key={ value }
										label={ label }
										checked={ allowedRoles.includes( value ) || 'administrator' === value }
										onChange={ ( checked: boolean ) =>
											setAllowedRoles( checked ? [ ...allowedRoles, value ] : allowedRoles.filter( role => role !== value ) )
										}
										disabled={ ! status.has_page || isSaving || 'administrator' === value }
										help={
											'administrator' === value
												? __( 'Administrators always have publishing permissions.', 'newspack-plugin' )
												: undefined
										}
										__nextHasNoMarginBottom
									/>
								) ) }
							</Grid>
						</VStack>
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
