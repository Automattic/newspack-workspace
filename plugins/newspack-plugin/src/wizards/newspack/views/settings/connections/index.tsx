/**
 * Settings Connections: Plugins, APIs, reCAPTCHA, Webhooks, Analytics, Custom Events
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';
import Plugins from './plugins';
import Webhooks from './webhooks';
import Analytics from './analytics';
import Recaptcha from './recaptcha';
import JetpackSSO from './jetpack-sso';
import Mailchimp from './mailchimp';
import GoogleOAuth from './google-oauth';
import CustomEvents from './custom-events';

import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';

const { connections } = window.newspackSettings;

function Connections() {
	return (
		<WizardsTab title={ __( 'Connections', 'newspack-plugin' ) }>
			{ /* Plugins */ }
			<WizardSection title={ __( 'Plugins', 'newspack-plugin' ) }>
				<Plugins />
			</WizardSection>

			{ /* APIs; google */ }
			<WizardSection title={ __( 'APIs', 'newspack-plugin' ) }>
				{ connections.sections.apis.dependencies?.googleOAuth && <GoogleOAuth /> }
				<Mailchimp />
			</WizardSection>

			{ /* Jetpack SSO */ }
			{ connections.sections.jetpack_sso.dependencies?.jetpack_sso ? (
				<WizardSection title={ __( 'Jetpack SSO', 'newspack-plugin' ) }>
					<JetpackSSO />
				</WizardSection>
			) : null }

			{ /* reCAPTCHA */ }
			<WizardSection scrollToAnchor="newspack-settings-recaptcha" title={ __( 'reCAPTCHA', 'newspack-plugin' ) }>
				<Recaptcha />
			</WizardSection>

			{ /* Webhooks */ }
			<WizardSection>
				<Webhooks />
			</WizardSection>

			{ /* Analytics */ }
			<WizardSection title={ __( 'Analytics', 'newspack-plugin' ) }>
				<Analytics />
			</WizardSection>

			{ /* Custom Events */ }
			<WizardSection
				title={ __( 'Activate Newspack Custom Events', 'newspack-plugin' ) }
				description={ __( 'Allows Newspack to send enhanced custom event data to your Google Analytics.', 'newspack-plugin' ) }
			>
				<CustomEvents />
			</WizardSection>
		</WizardsTab>
	);
}

export default Connections;
