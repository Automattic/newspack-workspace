/**
 * Newspack > Settings > Advanced Settings > Default Templates
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import OverwriteWarning from './overwrite-warning';

interface TemplateOption {
	label: string;
	value: string;
}

export interface TemplateOptions {
	post: TemplateOption[];
	page: TemplateOption[];
}

const ALL_POSTS_OPTIONS = [
	{ label: __( 'Select to change all posts', 'newspack-plugin' ), value: 'none' },
	{ label: __( 'With sidebar', 'newspack-plugin' ), value: 'default' },
	{ label: __( 'One Column', 'newspack-plugin' ), value: 'single-feature.php' },
	{ label: __( 'One Column Wide', 'newspack-plugin' ), value: 'single-wide.php' },
];

export default function DefaultTemplates( {
	data,
	update,
	isFetching,
	options,
	postCount,
}: ThemeModComponentProps< AdvancedSettings > & { options: TemplateOptions; postCount?: string } ) {
	return (
		<>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Default for new posts', 'newspack-plugin' ) }
				value={ data.post_template_default }
				options={ options.post }
				disabled={ isFetching }
				onChange={ ( post_template_default: string ) => update( { post_template_default } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Default for new pages', 'newspack-plugin' ) }
				value={ data.page_template_default }
				options={ options.page }
				disabled={ isFetching }
				onChange={ ( page_template_default: string ) => update( { page_template_default } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Apply to all existing posts', 'newspack-plugin' ) }
				value={ data.post_template_all_posts }
				options={ ALL_POSTS_OPTIONS }
				disabled={ isFetching }
				onChange={ ( post_template_all_posts: string ) => update( { post_template_all_posts } ) }
			/>
			{ data.post_template_all_posts !== 'none' && <OverwriteWarning postCount={ postCount } /> }
		</>
	);
}
