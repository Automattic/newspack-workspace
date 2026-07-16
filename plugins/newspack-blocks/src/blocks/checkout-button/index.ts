/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';

/**
 * WordPress dependencies
 */
import { button } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';
import deprecated from './deprecated';

const { name } = metadata;

// Name must be exported separately.
export { name };

export const settings = {
	...metadata,
	icon: {
		src: button,
		foreground: colors[ 'primary-400' ],
	},
	edit,
	deprecated,
	save: () => null, // to use view.php.
};
