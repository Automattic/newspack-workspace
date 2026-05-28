/**
 * Newspack > Settings > Emails
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import WizardsTab from '../../../../wizards-tab';
import { default as EmailsSection } from './emails';
import { default as SettingsSection } from './settings';
import WizardSection from '../../../../wizards-section';

const { emails } = window.newspackSettings;

function Emails() {
	return (
		<WizardsTab title={ __( 'Emails', 'newspack-plugin' ) }>
			<WizardSection>
				<EmailsSection />
			</WizardSection>
			{ emails?.sections?.emails?.isEmailEnhancementsActive && (
				<WizardSection>
					<SettingsSection />
				</WizardSection>
			) }
		</WizardsTab>
	);
}

export default Emails;
