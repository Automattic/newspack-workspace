/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import WizardsActionCard from '../../../../wizards-action-card';
import { Grid } from '../../../../../../packages/components/src';

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
		<WizardsActionCard
			isMedium
			title={ __( 'Customize where private tags are hidden', 'newspack-plugin' ) }
			description={ __(
				'By default, private tags are hidden in all supported locations. Turn this on to choose where private tags should be hidden.',
				'newspack-plugin'
			) }
			disabled={ isFetching }
			toggleChecked={ isCustom }
			toggleOnChange={ ( value: boolean ) => updateSetting( 'all', ! value ) }
			hasGreyHeader={ isCustom }
		>
			{ isCustom && (
				<Grid columns={ 2 } gutter={ 24 } style={ { marginTop: -8, marginBottom: -8 } }>
					<fieldset style={ { border: 0, margin: 0, padding: 0 } }>
						<legend className="components-base-control__label">{ __( 'Public-facing site', 'newspack-plugin' ) }</legend>
						<Grid columns={ 1 } rowGap={ 16 }>
							{ PUBLIC_TOGGLES.map( ( { key, label } ) => (
								<CheckboxControl
									key={ key }
									label={ label }
									disabled={ isFetching }
									checked={ Boolean( settings[ key ] ) }
									onChange={ ( value: boolean ) => updateSetting( key, value ) }
								/>
							) ) }
						</Grid>
					</fieldset>
					<fieldset style={ { border: 0, margin: 0, padding: 0 } }>
						<legend className="components-base-control__label">{ __( 'Data and integrations', 'newspack-plugin' ) }</legend>
						<Grid columns={ 1 } rowGap={ 16 }>
							{ INTEGRATION_TOGGLES.map( ( { key, label } ) => (
								<CheckboxControl
									key={ key }
									label={ label }
									disabled={ isFetching }
									checked={ Boolean( settings[ key ] ) }
									onChange={ ( value: boolean ) => updateSetting( key, value ) }
								/>
							) ) }
						</Grid>
					</fieldset>
				</Grid>
			) }
		</WizardsActionCard>
	);
}
