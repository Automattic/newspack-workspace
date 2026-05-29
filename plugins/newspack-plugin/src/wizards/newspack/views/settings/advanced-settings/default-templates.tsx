/**
 * Newspack > Settings > Advanced Settings > Default Templates
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid, SelectControl } from '../../../../../../packages/components/src';

interface TemplateOption {
	label: string;
	value: string;
}

export interface TemplateOptions {
	post: TemplateOption[];
	page: TemplateOption[];
}

export default function DefaultTemplates( { data, update, options }: ThemeModComponentProps< AdvancedSettings > & { options: TemplateOptions } ) {
	return (
		<Grid gutter={ 32 }>
			<SelectControl
				label={ __( 'Default template for new posts', 'newspack-plugin' ) }
				help={ __( 'Set a default template for new posts.', 'newspack-plugin' ) }
				value={ data.post_template_default }
				options={ options.post }
				onChange={ ( post_template_default: string ) => update( { post_template_default } ) }
			/>
			<SelectControl
				label={ __( 'Default template for new pages', 'newspack-plugin' ) }
				help={ __( 'Set a default template for new pages.', 'newspack-plugin' ) }
				value={ data.page_template_default }
				options={ options.page }
				onChange={ ( page_template_default: string ) => update( { page_template_default } ) }
			/>
		</Grid>
	);
}
