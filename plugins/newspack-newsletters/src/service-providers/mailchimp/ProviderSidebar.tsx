/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useNewsletterData } from '../../newsletter-editor/store';
import type { CampaignFolder, ProviderSidebarProps } from '../types';

export const ProviderSidebar = ( { inFlight, meta, updateMeta }: ProviderSidebarProps ) => {
	const { newsletterData } = useNewsletterData();
	const { campaign, folders } = newsletterData;

	const getFolderOptions = () => {
		const options: Array< { label: string; value: string; disabled?: boolean } > = folders!.map( ( folder: CampaignFolder ) => ( {
			label: folder.name as string,
			value: folder.id as string,
		} ) );
		options.unshift( {
			label: campaign?.settings?.folder_id ? __( 'Can’t unset folder', 'newspack-newsletters' ) : __( 'No folder', 'newspack-newsletters' ),
			value: '',
			disabled: !! campaign?.settings?.folder_id,
		} );
		return options;
	};

	return (
		<>
			<SelectControl
				label={ __( 'Campaign Folder', 'newspack-newsletters' ) }
				value={ meta?.mc_folder_id || campaign?.settings?.folder_id }
				options={ getFolderOptions() }
				onChange={ folderId => updateMeta!( { mc_folder_id: folderId } ) }
				disabled={ inFlight || ! folders!.length }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</>
	);
};
