/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { contentLoop as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { applyFilters } from '@wordpress/hooks';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import edit from './edit';

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';
import './view.scss';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Content Loop', 'newspack-content-blocks' );

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
		__( 'homepage', 'newspack-content-blocks' ),
		__( 'query', 'newspack-content-blocks' ),
	],
	description: __(
		'An advanced block that allows displaying content based on different parameters and visual configurations.',
		'newspack-content-blocks'
	),
	styles: [
		{
			name: 'default',
			label: _x( 'Default', 'block style', 'newspack-content-blocks' ),
			isDefault: true,
		},
		{
			name: 'borders',
			label: _x( 'Borders', 'block style', 'newspack-content-blocks' ),
		},
	],
	supports: {
		html: false,
		align: [ 'wide', 'full' ],
		default: '',
	},
	edit,
	save: () => null, // to use view.php
	transforms: {
		from: [
			{
				type: 'block',
				blocks: [ 'core/latest-posts' ],
				transform: ( { displayPostContent, displayPostDate, postLayout, columns, postsToShow, categories } ) => {
					return createBlock( applyFilters( 'blocks.transforms_from_name', 'newspack-blocks/homepage-articles' ), {
						showExcerpt: displayPostContent,
						showDate: displayPostDate,
						postLayout,
						columns,
						postsToShow,
						showAuthor: false,
						categories: categories ? [ categories ] : [],
					} );
				},
			},
		],
		to: [
			{
				type: 'block',
				blocks: [ 'core/latest-posts' ],
				transform: ( { showExcerpt, showDate, postLayout, columns, postsToShow, categories } ) => {
					return createBlock( 'core/latest-posts', {
						displayPostContent: showExcerpt,
						displayPostDate: showDate,
						postLayout,
						columns,
						postsToShow,
						categories: categories[ 0 ] || '',
					} );
				},
			},
		],
	},
};
