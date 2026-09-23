/**
 * Newspack > Settings > Advanced Settings > PWA Display Mode
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

const DISPLAY_MODE_OPTIONS = [
	{ label: __( 'Fullscreen', 'newspack-plugin' ), value: 'fullscreen' },
	{ label: __( 'Standalone', 'newspack-plugin' ), value: 'standalone' },
	{ label: __( 'Minimal UI', 'newspack-plugin' ), value: 'minimal-ui' },
	{ label: __( 'Browser', 'newspack-plugin' ), value: 'browser' },
];

export default function PwaDisplayMode( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={ __( 'Web app display mode', 'newspack-plugin' ) }
			help={ __(
				'Fullscreen: Full screen without browser UI. Standalone: Looks like a standalone app. Minimal UI: Minimal browser controls. Browser: Standard browser experience.',
				'newspack-plugin'
			) }
			value={ data.pwa_display_mode || 'minimal-ui' }
			options={ DISPLAY_MODE_OPTIONS }
			onChange={ ( pwa_display_mode: string ) => update( { pwa_display_mode } ) }
			disabled={ isFetching }
		/>
	);
}
