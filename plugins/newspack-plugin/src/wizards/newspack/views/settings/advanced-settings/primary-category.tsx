/**
 * Newspack > Settings > Advanced Settings > Primary Category.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ChoiceToggle from './choice-toggle';

export default function PrimaryCategory( { data, isFetching, update }: ThemeModComponentProps< PrimaryCategoryData > ) {
	return (
		<ChoiceToggle
			label={ __( 'Categories on posts', 'newspack-plugin' ) }
			help={ __( 'Show only the primary category set in Yoast SEO, or every category the post is in.', 'newspack-plugin' ) }
			onLabel={ __( 'Primary only', 'newspack-plugin' ) }
			offLabel={ __( 'All', 'newspack-plugin' ) }
			checked={ data.enabled }
			disabled={ isFetching }
			onChange={ ( enabled: boolean ) => update( { enabled } ) }
		/>
	);
}
