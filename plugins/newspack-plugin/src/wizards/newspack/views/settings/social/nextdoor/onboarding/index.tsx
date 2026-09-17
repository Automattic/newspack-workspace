/**
 * Nextdoor Onboarding View
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Card, Grid, Notice, SelectControl, TextControl } from '../../../../../../../../packages/components/src';
import { OnboardingProps } from '../types';

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

/**
 * Onboarding component.
 */
export const Onboarding = ( { settings, status, error, updateSettings, startOAuthFlow, claimPage, disconnect, setError }: OnboardingProps ) => {
	const [ clientId, setClientId ] = useState( settings.client_id || '' );
	const [ clientSecret, setClientSecret ] = useState( settings.client_secret || '' );
	const [ email, setEmail ] = useState( '' );
	const [ country, setCountry ] = useState( 'US' );
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
		// Check for OAuth error in URL params
		const urlParams = new URLSearchParams( window.location.search );
		const oauthError = urlParams.get( 'nextdoor_oauth_error' );

		if ( oauthError ) {
			setError( decodeURIComponent( oauthError ) );
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
			setCurrentStep( 2 );
		} finally {
			setIsSaving( false );
		}
	};

	const handleStartOAuth = async () => {
		if ( ! email ) {
			setError( __( 'Please enter your email address.', 'newspack-plugin' ) );
			return;
		}

		try {
			setIsSaving( true );
			setError( null );
			const response = await startOAuthFlow( email, country );

			// Redirect to login URL
			window.location.href = response.login_url ?? window.location.href;
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
		} finally {
			setIsSaving( false );
		}
	};

	const handleDisconnect = async () => {
		try {
			setIsSaving( true );
			setError( null );
			await disconnect();
			setCurrentStep( 1 );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<>
			{ error && <Notice noticeText={ error } isError onClose={ () => setError( null ) } /> }

			{ /* Step 1: API Credentials - Only shown in manual mode */ }
			{ isManualMode && currentStep === STEPS.manual.CREDENTIALS && (
				<Card>
					<p>{ __( 'To get started, you need to register your site with Nextdoor and obtain API credentials.', 'newspack-plugin' ) }</p>
					<div className="nextdoor-onboarding__redirect-uri-box">
						<strong>{ __( 'Redirect URI:', 'newspack-plugin' ) }</strong>
						<br />
						<div className="nextdoor-onboarding__redirect-uri-container">
							<code className="nextdoor-onboarding__redirect-uri-code">{ redirectUri }</code>
						</div>
						<small className="nextdoor-onboarding__redirect-uri-help">
							{ __( 'Use this URL as the Redirect URI when signing up for Nextdoor credentials.', 'newspack-plugin' ) }
						</small>
					</div>
					<p>
						<ExternalLink href="https://developer.nextdoor.com/reference/applying-for-access">
							{ __( 'Get your API credentials from Nextdoor Developer Portal', 'newspack-plugin' ) }
						</ExternalLink>
					</p>

					<Grid columns={ 1 } gutter={ 16 }>
						<TextControl
							label={ __( 'Client ID', 'newspack-plugin' ) }
							value={ clientId }
							onChange={ setClientId }
							placeholder={ __( 'Enter your Nextdoor Client ID', 'newspack-plugin' ) }
						/>
						<TextControl
							label={ __( 'Client Secret', 'newspack-plugin' ) }
							value={ clientSecret }
							onChange={ setClientSecret }
							type="password"
							placeholder={ __( 'Enter your Nextdoor Client Secret', 'newspack-plugin' ) }
						/>
					</Grid>

					<div className="newspack-buttons-card">
						<Button
							variant="primary"
							onClick={ handleSaveCredentials }
							disabled={ ! clientId || ! clientSecret || isSaving }
							isBusy={ isSaving }
						>
							{ __( 'Save & Continue', 'newspack-plugin' ) }
						</Button>
					</div>
				</Card>
			) }

			{ /* Step 2: Account Authentication */ }
			{ currentStep === steps.ACCOUNT_AUTH && (
				<Card>
					<p>{ __( 'Connect your Nextdoor account to authorize publishing articles.', 'newspack-plugin' ) }</p>

					<Grid columns={ 1 } gutter={ 16 }>
						<TextControl
							label={ __( 'Email Address', 'newspack-plugin' ) }
							value={ email }
							onChange={ setEmail }
							type="email"
							placeholder={ __( 'Enter your Nextdoor account email', 'newspack-plugin' ) }
							help={ __( 'This should be the email address associated with your Nextdoor account.', 'newspack-plugin' ) }
						/>
						<SelectControl
							label={ __( 'Country', 'newspack-plugin' ) }
							value={ country }
							onChange={ setCountry }
							options={ countryOptions }
						/>
					</Grid>

					<div className="newspack-buttons-card">
						<Button variant="primary" onClick={ handleStartOAuth } disabled={ ! email || isSaving } isBusy={ isSaving }>
							{ __( 'Connect Account', 'newspack-plugin' ) }
						</Button>
						{ isManualMode && (
							<Button variant="secondary" onClick={ () => setCurrentStep( STEPS.manual.CREDENTIALS ) }>
								{ __( 'Back', 'newspack-plugin' ) }
							</Button>
						) }
					</div>
				</Card>
			) }

			{ /* Step 3: Claim Page */ }
			{ currentStep === steps.CLAIM_PAGE && (
				<Card>
					<p>{ __( 'Claim your news page on Nextdoor to start publishing articles.', 'newspack-plugin' ) }</p>

					<Grid columns={ 1 } gutter={ 16 }>
						<TextControl
							label={ __( 'Publication URL', 'newspack-plugin' ) }
							value={ publicationUrl }
							onChange={ setPublicationUrl }
							type="url"
							placeholder={ __( 'https://yoursite.com', 'newspack-plugin' ) }
							help={ __( 'The main URL of your news publication.', 'newspack-plugin' ) }
						/>
					</Grid>

					<div className="newspack-buttons-card">
						<Button variant="primary" onClick={ handleClaimPage } disabled={ ! publicationUrl || isSaving } isBusy={ isSaving }>
							{ __( 'Claim Page', 'newspack-plugin' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setCurrentStep( steps.ACCOUNT_AUTH ) }>
							{ __( 'Back', 'newspack-plugin' ) }
						</Button>
					</div>
				</Card>
			) }

			{ /* Step 4: Success */ }
			{ currentStep === steps.SUCCESS && status.is_connected && (
				<ActionCard
					title={ __( 'Nextdoor Connected Successfully!', 'newspack-plugin' ) }
					description={ __(
						'Your site is now connected to Nextdoor. You can start publishing articles to your local community.',
						'newspack-plugin'
					) }
					actionText={ __( 'Configure Settings', 'newspack-plugin' ) }
					handoff={ 'settings' }
					editLink="#/settings"
					hasGreyHeader={ false }
				/>
			) }

			{ /* Connection Status */ }
			{ ( ! isManualMode || currentStep > STEPS.manual.CREDENTIALS ) && (
				<Card>
					<Grid columns={ 2 } gutter={ 16 }>
						<div>
							<div className="nextdoor-onboarding__status-label">{ __( 'Authorization:', 'newspack-plugin' ) }</div>
							{ ( () => {
								if ( ! isManualMode ) {
									return (
										<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--success">
											{ __( 'Managed by Newspack', 'newspack-plugin' ) }
										</span>
									);
								}
								if ( status.has_credentials ) {
									return (
										<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--success">
											{ __( 'Configured', 'newspack-plugin' ) }
										</span>
									);
								}
								return (
									<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--error">
										{ __( 'Not configured', 'newspack-plugin' ) }
									</span>
								);
							} )() }
						</div>
						<div>
							<div className="nextdoor-onboarding__status-label">{ __( 'Account Connected:', 'newspack-plugin' ) }</div>
							{ status.has_tokens ? (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--success">
									{ __( 'Yes', 'newspack-plugin' ) }
								</span>
							) : (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--error">
									{ __( 'No', 'newspack-plugin' ) }
								</span>
							) }
						</div>
						<div>
							<div className="nextdoor-onboarding__status-label">{ __( 'Page Claimed:', 'newspack-plugin' ) }</div>
							{ status.has_page ? (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--success">
									{ __( 'Yes', 'newspack-plugin' ) }
								</span>
							) : (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--error">
									{ __( 'No', 'newspack-plugin' ) }
								</span>
							) }
						</div>
						<div>
							<div className="nextdoor-onboarding__status-label">{ __( 'Overall Status:', 'newspack-plugin' ) }</div>
							{ status.is_connected ? (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--success">
									{ __( 'Connected', 'newspack-plugin' ) }
								</span>
							) : (
								<span className="nextdoor-onboarding__status-value nextdoor-onboarding__status-value--error">
									{ __( 'Not connected', 'newspack-plugin' ) }
								</span>
							) }
						</div>
					</Grid>

					{ status.is_connected && (
						<div className="newspack-buttons-card">
							<Button isDestructive onClick={ handleDisconnect } disabled={ isSaving } isBusy={ isSaving }>
								{ __( 'Disconnect', 'newspack-plugin' ) }
							</Button>
						</div>
					) }
				</Card>
			) }
		</>
	);
};

export default Onboarding;
