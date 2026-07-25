/**
 * Contextual Prompts Style section.
 *
 * Block themes hand off to the Site Editor's Styles panel; classic themes get
 * controls that write site-wide defaults for the Contextual Prompt block.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, Handoff, SectionHeader } from '../../../../../../packages/components/src';

// eslint-disable-next-line no-unused-vars, @typescript-eslint/no-unused-vars -- the classic controls that read these land in the next task.
const StyleSection = ( { status, styles, inFlight, onChangeStyles } ) => {
	const { is_block_theme: isBlockTheme, site_editor_styles_url: siteEditorStylesUrl } = status;

	return (
		<Grid columns={ 2 } gutter={ 32 } noMargin>
			<SectionHeader
				heading={ 2 }
				title={ __( 'Style', 'newspack-plugin' ) }
				description={
					isBlockTheme
						? __( "Contextual Prompt styles are managed in the Site Editor's Styles panel.", 'newspack-plugin' )
						: __(
								'Site-wide default styles for the Contextual Prompt block. Styles set on an individual block override these.',
								'newspack-plugin'
						  )
				}
				noMargin
			/>
			{ isBlockTheme ? (
				<VStack spacing={ 6 } alignment="start">
					<Handoff url={ siteEditorStylesUrl } __next40pxDefaultSize>
						{ __( 'Edit Styles', 'newspack-plugin' ) }
					</Handoff>
				</VStack>
			) : (
				<VStack spacing={ 6 }>{ /* Classic controls land in the next task. */ }</VStack>
			) }
		</Grid>
	);
};

export default StyleSection;
