/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { Sidebar } from '../sidebar';

/**
 * Filters the PostTaxonomies component to add explanations unique to Newspack Sponsor posts.
 *
 * @param {Function} PostTaxonomies The original PostTaxonomies component to filter.
 *                                  https://github.com/WordPress/gutenberg/tree/trunk/packages/editor/src/components/post-taxonomies
 * @return {Function} The filtered component.
 */
export const TaxonomyPanel = PostTaxonomies => {
	const OriginalComponent = props => {
		const { post_type: postType, cpt, tax } = window.newspack_sponsors_data;
		const { slug } = props;
		const isSponsorsTax = tax === slug;
		const hasAssignedSponsors = useSelect( select => {
			const sponsors = select( 'core/editor' ).getEditedPostAttribute( tax );
			return Array.isArray( sponsors ) && 0 < sponsors.length;
		} );

		// Only filter compoent for sponsors, tax, categories, and tags.
		if ( 'category' !== slug && 'post_tag' !== slug && ! isSponsorsTax ) {
			return <PostTaxonomies { ...props } />;
		}

		// Append sponsor settings panel to Sponsors taxonomy panel.
		if ( isSponsorsTax ) {
			return (
				<>
					<p>{ __( 'Select one or more sponsors:', 'newspack-sponsors' ) }</p>
					<PostTaxonomies { ...props } />
					{ hasAssignedSponsors && <Sidebar /> }
				</>
			);
		}

		const hierarchical = 'category' === slug;
		const label = 'category' === slug ? __( 'categories', 'newspack-sponsors' ) : __( 'tags', 'newspack-sponsors' );
		const message = sprintf(
			// translators: %1$s: "Select" or "Add", %2$s: taxonomy label, %3$s: taxonomy label.
			__( '%1$s one or more post %2$s to associate this sponsor with those %3$s.', 'newspack-sponsors' ),
			hierarchical ? __( 'Select', 'newspack-sponsors' ) : __( 'Add', 'newspack-sponsors' ),
			label,
			label
		);

		return (
			<>
				{ cpt === postType && ( slug === 'category' || slug === 'post_tag' ) && (
					<p>
						<em>{ message }</em>
					</p>
				) }
				<PostTaxonomies { ...props } />
			</>
		);
	};

	return OriginalComponent;
};
