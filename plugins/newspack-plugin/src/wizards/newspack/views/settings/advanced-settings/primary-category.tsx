/**
 * Newspack > Settings > Advanced Settings > Primary Category.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';

export default function PrimaryCategory( { data, update, isFetching }: ThemeModComponentProps< PrimaryCategoryData > ) {
	return (
		<ToggleControl
			label={ __( 'Use Yoast primary category', 'newspack-plugin' ) }
			help={ __(
				'When enabled, only the primary category set in Yoast SEO is displayed on posts. Disable to show all categories.',
				'newspack-plugin'
			) }
			checked={ data.enabled }
			onChange={ ( enabled: boolean ) => update( { enabled } ) }
			disabled={ isFetching }
		/>
	);
}
