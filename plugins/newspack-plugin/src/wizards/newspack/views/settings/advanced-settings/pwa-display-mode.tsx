import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

import { Grid, Notice } from '../../../../../../packages/components/src';

interface PwaDisplayModeProps extends ThemeModComponentProps< AdvancedSettings > {}

const DISPLAY_MODE_OPTIONS = [
	{
		label: __( 'Fullscreen', 'newspack-plugin' ),
		value: 'fullscreen',
	},
	{
		label: __( 'Standalone', 'newspack-plugin' ),
		value: 'standalone',
	},
	{
		label: __( 'Minimal UI', 'newspack-plugin' ),
		value: 'minimal-ui',
	},
	{
		label: __( 'Browser', 'newspack-plugin' ),
		value: 'browser',
	},
];

export default function PwaDisplayMode( { data, isFetching, update }: PwaDisplayModeProps ) {
	return (
		<Grid gutter={ 32 }>
			<Grid columns={ 1 } gutter={ 16 }>
				<SelectControl
					label={ __( 'Web App Display Mode', 'newspack-plugin' ) }
					help={ __(
						'Choose how your site appears when installed as a Progressive Web App. Fullscreen: Full screen without browser UI. Standalone: Looks like a standalone app. Minimal UI: Minimal browser controls. Browser: Standard browser experience.',
						'newspack-plugin'
					) }
					value={ data.pwa_display_mode || 'minimal-ui' }
					options={ DISPLAY_MODE_OPTIONS }
					onChange={ ( pwa_display_mode: string ) => update( { pwa_display_mode } ) }
					disabled={ isFetching }
				/>
				<Notice
					noticeText={ __(
						'This setting controls how your site appears when users install it as a Progressive Web App on their devices.',
						'newspack-plugin'
					) }
					isInfo
				/>
			</Grid>
		</Grid>
	);
}
