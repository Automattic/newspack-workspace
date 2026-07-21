/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { withWizardScreen, PluginSettings } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';

const Settings = () => {
	const [ configuring, setConfiguring ] = useState( false );
	return (
		<>
			<ContextualPromptsSettings configuring={ configuring } onConfigure={ setConfiguring } />
			{ /* While the Contextual Prompts configure view is open, it replaces the page. */ }
			{ ! configuring && <PluginSettings pluginSlug="newspack-audience-campaigns" isWizard={ true } title={ null } /> }
		</>
	);
};

export default withWizardScreen( Settings );
