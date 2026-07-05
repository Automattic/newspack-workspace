/**
 * External dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';
import type { BlockCategory } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import NewspackLogo from './newspack-logo';

setCategories( [
	...getCategories().filter( ( { slug } ) => slug !== 'newspack' ),
	// `BlockCategory` doesn't declare `icon` in this package version's types, but
	// the block category registry (and the inserter UI) does support it at runtime.
	{
		slug: 'newspack',
		title: 'Newspack',
		icon: <NewspackLogo />,
	} as BlockCategory,
] );
