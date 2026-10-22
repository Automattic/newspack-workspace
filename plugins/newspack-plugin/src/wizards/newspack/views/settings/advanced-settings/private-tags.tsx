/**
 * Newspack > Settings > Advanced Settings > Private Tags
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	BaseControl,
	CheckboxControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';

const PUBLIC_TOGGLES = [
	{ key: 'archives', label: __( 'Disable private tag archive pages', 'newspack-plugin' ) },
	{ key: 'feeds', label: __( 'Disable private tag RSS feeds', 'newspack-plugin' ) },
	{ key: 'feed_terms', label: __( 'Remove private tags from RSS feeds', 'newspack-plugin' ) },
	{ key: 'tag_links', label: __( 'Remove private tags from post tag lists', 'newspack-plugin' ) },
	{ key: 'tag_clouds', label: __( 'Remove private tags from tag cloud widgets', 'newspack-plugin' ) },
];

const INTEGRATION_TOGGLES = [
	{ key: 'css_classes', label: __( 'Remove private tags from CSS classes', 'newspack-plugin' ) },
	{ key: 'gam_targeting', label: __( 'Exclude private tags from Google Ad Manager targeting', 'newspack-plugin' ) },
	{ key: 'yoast_metadata', label: __( 'Exclude private tags from Yoast SEO metadata', 'newspack-plugin' ) },
	{ key: 'yoast_sitemap', label: __( 'Exclude private tags from Yoast XML sitemaps', 'newspack-plugin' ) },
	{ key: 'reader_data', label: __( 'Remove private tags from audience management data', 'newspack-plugin' ) },
];

const GROUPS = [
	{ key: 'public', legend: __( 'Public-facing site', 'newspack-plugin' ), toggles: PUBLIC_TOGGLES },
	{ key: 'integrations', legend: __( 'Data and integrations', 'newspack-plugin' ), toggles: INTEGRATION_TOGGLES },
];

export default function PrivateTags( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	const settings = data.newspack_private_tags_settings;
	if ( ! settings ) {
		return null;
	}

	const isCustom = ! Boolean( settings.all );

	const updateSetting = ( key: string, value: boolean ) => {
		update( {
			newspack_private_tags_settings: {
				[ key ]: value,
			},
		} );
	};

	return (
		<>
			<ToggleGroupControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				isBlock
				label={ __( 'Hide private tags', 'newspack-plugin' ) }
				help={ __(
					'By default, private tags are hidden in all supported locations. Choose Custom to pick where they are hidden.',
					'newspack-plugin'
				) }
				value={ isCustom ? 'custom' : 'all' }
				onChange={ value => updateSetting( 'all', value !== 'custom' ) }
			>
				<ToggleGroupControlOption value="all" label={ __( 'Everywhere', 'newspack-plugin' ) } disabled={ isFetching } />
				<ToggleGroupControlOption value="custom" label={ __( 'Custom', 'newspack-plugin' ) } disabled={ isFetching } />
			</ToggleGroupControl>
			{ isCustom &&
				GROUPS.map( ( { key, legend, toggles } ) => (
					<fieldset key={ key } className="newspack-private-tags__group">
						<legend>
							<BaseControl.VisualLabel>{ legend }</BaseControl.VisualLabel>
						</legend>
						<Stack direction="column" gap="xs">
							{ toggles.map( toggle => (
								<CheckboxControl
									__nextHasNoMarginBottom
									key={ toggle.key }
									label={ toggle.label }
									disabled={ isFetching }
									checked={ Boolean( settings[ toggle.key ] ) }
									onChange={ ( value: boolean ) => updateSetting( toggle.key, value ) }
								/>
							) ) }
						</Stack>
					</fieldset>
				) ) }
		</>
	);
}
