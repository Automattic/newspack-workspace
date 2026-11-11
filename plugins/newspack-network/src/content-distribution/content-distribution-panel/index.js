/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { PluginSidebar } from '@wordpress/editor';
import { Panel, PanelBody } from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { broadcast } from 'newspack-icons';

/**
 * Internal dependencies.
 */
import './style.scss';

const ContentDistributionPanel = ( { header, body, footer, buttons } ) => {
	return (
		<PluginSidebar
			name="newspack-network-content-distribution-panel"
			icon={ broadcast }
			title={ __( 'Distribute', 'newspack-network' ) }
			className="newspack-network-content-distribution-panel"
		>
			<Panel>
				<PanelBody className="content-distribution-panel-header">{ header }</PanelBody>
				<PanelBody className="content-distribution-panel-body">{ body }</PanelBody>
				<PanelBody className="content-distribution-panel-footer">{ footer }</PanelBody>
				<PanelBody className="content-distribution-panel-buttons">
					<Stack direction="column" className="content-distribution-panel__button-column" gap="lg">
						{ buttons }
					</Stack>
				</PanelBody>
			</Panel>
		</PluginSidebar>
	);
};

export default ContentDistributionPanel;
