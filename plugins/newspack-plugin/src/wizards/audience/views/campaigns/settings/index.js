/**
 * Internal dependencies
 */
import { withWizardScreen, PluginSettings } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';

const Settings = () => {
	return (
		<>
			<ContextualPromptsSettings />
			<PluginSettings pluginSlug="newspack-audience-campaigns" isWizard={ true } title={ null } />
		</>
	);
};

export default withWizardScreen( Settings );
