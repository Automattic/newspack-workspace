/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { playlist as icon } from 'newspack-icons';

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
import './editor.scss';

export const name = 'youtube-video-playlist';
export const title = __( 'YouTube Video Playlist (DEPRECATED)', 'newspack-blocks' );

export const settings = {
	apiVersion: 3,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	category: 'newspack',
	keywords: [ __( 'video', 'newspack-blocks' ), __( 'playlist', 'newspack-blocks' ), __( 'youtube', 'newspack-blocks' ) ],
	description: __( 'Embed a playlist of latest YouTube videos.', 'newspack-blocks' ),
	attributes: {
		className: {
			type: 'string',
		},
		categories: {
			type: 'array',
			default: [],
		},
		videosToShow: {
			type: 'integer',
			default: 5,
		},
	},
	supports: {
		html: false,
		align: true,
	},
	edit,
	save: () => null, // to use view.php
};
