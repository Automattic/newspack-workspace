/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { withWizardScreen } from '../../../../../../packages/components/src';
import ContextualPromptsSettings from './contextual-prompts-settings';

const ContextualPrompts = () => {
	const [ configuring, setConfiguring ] = useState( false );
	// While the configure view is open, it replaces the tab content.
	return <ContextualPromptsSettings configuring={ configuring } onConfigure={ setConfiguring } />;
};

export default withWizardScreen( ContextualPrompts );
