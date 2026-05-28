/**
 * WordPress dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { NewspackLogo } from '../svg';

/**
 * If the Newspack Blocks plugin is installed, use the existing Newspack block category.
 * Otherwise, create the category. This lets Newspack Listings remain usable without
 * depending on Newspack Blocks.
 */
export const setCustomCategory = () => {
	const categories = getCategories();
	const hasNewspackCategory = !! categories.find( ( { slug } ) => slug === 'newspack' );

	if ( ! hasNewspackCategory ) {
		setCategories( [
			...categories.filter( ( { slug } ) => slug !== 'newspack' ),
			{
				slug: 'newspack',
				title: 'Newspack',
				icon: <NewspackLogo />,
			},
		] );
	}
};
