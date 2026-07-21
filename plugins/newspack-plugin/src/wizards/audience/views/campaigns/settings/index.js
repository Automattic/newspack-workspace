/**
 * Internal dependencies
 */
import { withWizardScreen, PluginSettings } from '../../../../../../packages/components/src';
import ContextualPromptsOptIn from './contextual-prompts-opt-in';

const Settings = () => {
	return (
		<>
			<ContextualPromptsOptIn />
			<PluginSettings pluginSlug="newspack-audience-campaigns" isWizard={ true } title={ null } />
		</>
	);
};

export default withWizardScreen( Settings );
