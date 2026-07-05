/**
 * WordPress dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';
import type { BlockCategory } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { NewspackLogo } from '../svg';

/**
 * `BlockCategory` only declares `slug`/`title` - the runtime block category
 * API also accepts an `icon`, as used below. Widen locally via a typed
 * variable (rather than casting) so the icon-bearing object isn't checked as
 * an excess-property literal against the narrower published type.
 */
type CategoryWithIcon = BlockCategory & { icon: import('react').JSX.Element };

/**
 * If the Newspack Blocks plugin is installed, use the existing Newspack block category.
 * Otherwise, create the category. This lets Newspack Listings remain usable without
 * depending on Newspack Blocks.
 */
export const setCustomCategory = () => {
	const categories = getCategories();
	const hasNewspackCategory = !! categories.find( ( { slug } ) => slug === 'newspack' );

	if ( ! hasNewspackCategory ) {
		const newspackCategory: CategoryWithIcon = {
			slug: 'newspack',
			title: 'Newspack',
			icon: <NewspackLogo />,
		};

		setCategories( [ ...categories.filter( ( { slug } ) => slug !== 'newspack' ), newspackCategory ] );
	}
};
