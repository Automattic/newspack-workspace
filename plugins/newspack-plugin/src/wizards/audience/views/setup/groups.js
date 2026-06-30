/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { withWizardScreen } from '../../../../../packages/components/src';
import WizardsTab from '../../../wizards-tab';
import GroupLabels from '../../components/group-labels';

export default withWizardScreen( function () {
	return (
		<WizardsTab
			title={ __( 'Advanced settings', 'newspack-plugin' ) }
			description={ __( 'Configure reader-facing labels for My Account.', 'newspack-plugin' ) }
		>
			<GroupLabels />
		</WizardsTab>
	);
} );
