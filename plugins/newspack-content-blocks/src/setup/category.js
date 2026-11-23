/**
 * External dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import NewspackLogo from './newspack-logo';

// Register the shared "Newspack" block category only if newspack-blocks (or anything else) hasn't already.
if ( ! getCategories().some( ( { slug } ) => slug === 'newspack' ) ) {
	setCategories( [
		...getCategories(),
		{
			slug: 'newspack',
			title: 'Newspack',
			icon: <NewspackLogo />,
		},
	] );
}
