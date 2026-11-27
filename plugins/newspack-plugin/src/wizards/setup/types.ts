/**
 * Shared types for the Setup wizard.
 */

/**
 * Internal dependencies.
 */
import type { WithWizardInjectedProps } from '../../../packages/components/src/with-wizard';
import type { RenderPrimaryButton, WithWizardScreenProps } from '../../../packages/components/src/with-wizard-screen';

/**
 * The `withWizard`-injected props the setup wizard forwards to its screens.
 */
export type SetupWizardInjectedProps = Pick< WithWizardInjectedProps, 'wizardApiFetch' | 'setError' >;

/**
 * Props the setup wizard passes to each route's screen component.
 */
export type SetupScreenProps = Omit< WithWizardScreenProps, 'buttonAction' > &
	SetupWizardInjectedProps & {
		/** The primary button action – routes followed by another route link to it. */
		buttonAction: { href?: string };
		/** Whether the screen is rendered as part of the initial setup flow. */
		isPartOfSetup: boolean;
	};

/**
 * Props of a setup screen component wrapped by `withWizardScreen`: the setup
 * screen props plus the HOC-injected primary-button renderer.
 */
export type SetupScreenComponentProps = SetupScreenProps & {
	/** Renders the wizard screen's primary button (injected by `withWizardScreen`). */
	renderPrimaryButton: RenderPrimaryButton;
};
