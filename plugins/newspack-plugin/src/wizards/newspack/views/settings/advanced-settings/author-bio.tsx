import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';

import { Grid, TextControl } from '../../../../../../packages/components/src';

export default function AuthorBio( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<Grid gutter={ 32 }>
			<Grid columns={ 1 } gutter={ 16 }>
				<ToggleControl
					label={ __( 'Author Bio', 'newspack-plugin' ) }
					help={ __( 'Display an author bio under individual posts.', 'newspack-plugin' ) }
					disabled={ isFetching }
					checked={ data.show_author_bio }
					onChange={ show_author_bio => update( { show_author_bio } ) }
				/>
				{ data.show_author_bio && (
					<ToggleControl
						label={ __( 'Author Email', 'newspack-plugin' ) }
						help={ __( 'Display the author email with bio on individual posts.', 'newspack-plugin' ) }
						disabled={ isFetching }
						checked={ data.show_author_email }
						onChange={ show_author_email => update( { show_author_email } ) }
					/>
				) }
			</Grid>
			<Grid columns={ 1 } gutter={ 16 }>
				{ data.show_author_bio && (
					<TextControl
						label={ __( 'Length', 'newspack-plugin' ) }
						help={ __(
							'Truncates the author bio on single posts to this approximate character length, but without breaking a word. The full bio appears on the author archive page.',
							'newspack-plugin'
						) }
						type="number"
						value={ data.author_bio_length }
						onChange={ ( author_bio_length: number ) => update( { author_bio_length } ) }
					/>
				) }
			</Grid>
		</Grid>
	);
}
