/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { contentCarousel as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import edit from './edit';

/**
 * Style dependencies - will load in editor
 */
import './view.scss';
import './editor.scss';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Content Carousel', 'newspack-content-blocks' );

export const settings = {
	apiVersion,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	attributes,
	category,
	keywords: [
		__( 'posts', 'newspack-content-blocks' ),
		__( 'articles', 'newspack-content-blocks' ),
		__( 'latest', 'newspack-content-blocks' ),
		__( 'query', 'newspack-content-blocks' ),
	],
	description: __(
		'An advanced block that displays content in a carousel format with customizable parameters and visual configurations.',
		'newspack-content-blocks'
	),
	supports: {
		html: false,
		align: [ 'center', 'wide', 'full' ],
	},
	edit,
	save: () => null, // to use view.php
};
