/**
 * Audience > Configuration > Emails
 */

/**
 * Internal dependencies.
 */
import { withWizardScreen } from '../../../../../../packages/components/src';
import WizardsTab from '../../../../wizards-tab';
import { default as EmailsSection } from './emails';
import WizardSection from '../../../../wizards-section';

function Emails() {
	return (
		<WizardsTab className="newspack-emails-tab">
			<WizardSection>
				<EmailsSection />
			</WizardSection>
		</WizardsTab>
	);
}

// withWizardScreen applied at the export to match the sibling tabs
// (Setup, ContentGating, Payment, Campaign, Complete) — each applies
// the HOC at its own module's default export so the parent setup view
// just routes to the component, not to an inline wrapper.
export default withWizardScreen( Emails );
