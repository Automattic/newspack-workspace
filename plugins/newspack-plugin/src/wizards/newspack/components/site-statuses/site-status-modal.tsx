/**
 * Newspack - Dashboard, Site Action Modal
 *
 * Modal component for installing necessary dependencies
 */

/**
 * Dependencies
 */
// WordPress
import { __ } from '@wordpress/i18n';
import { PluginInstaller, Modal } from '../../../../../packages/components/src';

const SiteActionModal = ( { onRequestClose, plugins, onSuccess }: SiteActionModal ) => {
	return (
		<Modal title={ __( 'Add missing dependencies', 'newspack-plugin' ) } onRequestClose={ () => onRequestClose( false ) }>
			<PluginInstaller
				plugins={ plugins }
				canUninstall
				onStatus={ ( { complete, pluginInfo }: { complete: boolean; pluginInfo: Record< string, any > } ) => {
					if ( complete ) {
						onSuccess( pluginInfo );
						onRequestClose( false );
					}
				} }
			/>
		</Modal>
	);
};

export default SiteActionModal;
