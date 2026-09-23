/**
 * Newspack > Settings > Advanced Settings > Author Bio
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { TextControl } from '../../../../../../packages/components/src';
import ChoiceToggle from './choice-toggle';

export default function AuthorBio( { data, isFetching, update }: ThemeModComponentProps< AdvancedSettings > ) {
	return (
		<>
			<ChoiceToggle
				label={ __( 'Author bio', 'newspack-plugin' ) }
				help={ __( 'Display an author bio under individual posts.', 'newspack-plugin' ) }
				checked={ data.show_author_bio }
				disabled={ isFetching }
				onChange={ show_author_bio => update( { show_author_bio } ) }
			/>
			{ data.show_author_bio && (
				<>
					<ChoiceToggle
						label={ __( 'Author email', 'newspack-plugin' ) }
						help={ __( 'Display the author email with bio on individual posts.', 'newspack-plugin' ) }
						checked={ data.show_author_email }
						disabled={ isFetching }
						onChange={ show_author_email => update( { show_author_email } ) }
					/>
					<TextControl
						withMargin={ false }
						label={ __( 'Length', 'newspack-plugin' ) }
						help={ __(
							'Truncates the author bio on single posts to this approximate character length, but without breaking a word. The full bio appears on the author archive page.',
							'newspack-plugin'
						) }
						type="number"
						disabled={ isFetching }
						value={ data.author_bio_length }
						onChange={ ( author_bio_length: number ) => update( { author_bio_length } ) }
					/>
				</>
			) }
		</>
	);
}
