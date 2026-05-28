/**
 * Newspack > Settings > Advanced Settings > Featured Image Posts All
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Grid, SelectControl } from '../../../../../../packages/components/src';

export default function FeaturedImagePostsAll( { data, update, postCount }: ThemeModComponentProps< AdvancedSettings > & { postCount: string } ) {
	return (
		<Fragment>
			{ Number( postCount ) > 1000 && (
				<Notice isDismissible={ false } status="warning" className="ma0 mb2">
					{ __( 'You have more than 1000 posts. Applying these settings might take a moment.', 'newspack-plugin' ) }
				</Notice>
			) }
			<Grid gutter={ 32 }>
				<div>
					<SelectControl
						label={ __( 'Featured image position for all posts', 'newspack-plugin' ) }
						help={ __( 'Set a featured image position for all posts.', 'newspack-plugin' ) }
						value={ data.featured_image_all_posts }
						options={ [
							{
								label: __( 'Select to change all posts', 'newspack-plugin' ),
								value: 'none',
							},
							{
								label: __( 'Large', 'newspack-plugin' ),
								value: 'large',
							},
							{
								label: __( 'Small', 'newspack-plugin' ),
								value: 'small',
							},
							{
								label: __( 'Behind article title', 'newspack-plugin' ),
								value: 'behind',
							},
							{
								label: __( 'Beside article title', 'newspack-plugin' ),
								value: 'beside',
							},
							{
								label: __( 'Hidden', 'newspack-plugin' ),
								value: 'hidden',
							},
						] }
						onChange={ ( featured_image_all_posts: string ) => update( { featured_image_all_posts } ) }
					/>
					{ data.featured_image_all_posts !== 'none' && (
						<Notice isDismissible={ false } status="warning" className="ma0 mt2">
							{ __(
								'After saving the settings with this option selected, all posts will be updated. This cannot be undone.',
								'newspack-plugin'
							) }
						</Notice>
					) }
				</div>

				<div>
					<SelectControl
						label={ __( 'Template for all posts', 'newspack-plugin' ) }
						help={ __( 'Set a template for all posts.', 'newspack-plugin' ) }
						value={ data.post_template_all_posts }
						options={ [
							{
								label: __( 'Select to change all posts', 'newspack-plugin' ),
								value: 'none',
							},
							{
								label: __( 'With sidebar', 'newspack-plugin' ),
								value: 'default',
							},
							{
								label: __( 'One Column', 'newspack-plugin' ),
								value: 'single-feature.php',
							},
							{
								label: __( 'One Column Wide', 'newspack-plugin' ),
								value: 'single-wide.php',
							},
						] }
						onChange={ ( post_template_all_posts: string ) => update( { post_template_all_posts } ) }
					/>
					{ data.post_template_all_posts !== 'none' && (
						<Notice isDismissible={ false } status="warning" className="ma0 mt2">
							{ __(
								'After saving the settings with this option selected, all posts will be updated. This cannot be undone.',
								'newspack-plugin'
							) }
						</Notice>
					) }
				</div>
			</Grid>
		</Fragment>
	);
}
