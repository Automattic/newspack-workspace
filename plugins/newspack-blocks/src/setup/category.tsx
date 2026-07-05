/**
 * External dependencies
 */
import { getCategories, setCategories } from '@wordpress/blocks';
import type { BlockCategory } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import NewspackLogo from './newspack-logo';

// `@wordpress/blocks`' `BlockCategory` type doesn't declare `icon`, even though the
// block editor UI reads and renders it. Widen the type at this boundary rather than
// dropping the (functioning) icon.
setCategories( [
	...getCategories().filter( ( { slug } ) => slug !== 'newspack' ),
	{
		slug: 'newspack',
		title: 'Newspack',
		icon: <NewspackLogo />,
	} as BlockCategory & { icon: JSX.Element },
] );
