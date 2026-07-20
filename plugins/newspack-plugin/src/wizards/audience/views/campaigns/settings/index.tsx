/**
 * Internal dependencies
 */
import { withWizardScreen, PluginSettings } from '../../../../../../packages/components/src';

const Settings = () => {
	return <PluginSettings pluginSlug="newspack-audience-campaigns" isWizard={ true } title={ null } />;
};

export default withWizardScreen( Settings );
