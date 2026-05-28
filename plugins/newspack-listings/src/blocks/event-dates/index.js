/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { calendar } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { EventDatesEditor } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerEventDatesBlock = () => {
	registerBlockType( name, {
		apiVersion: 3,
		title: __( 'Event Dates', 'newspack-listings' ),
		icon: {
			src: calendar,
			foreground: '#003da5',
		},
		category,
		keywords: [
			__( 'curated', 'newspack-listings' ),
			__( 'list', 'newspack-listings' ),
			__( 'lists', 'newspack-listings' ),
			__( 'listings', 'newspack-listings' ),
			__( 'latest', 'newspack-listings' ),
			__( 'event', 'newspack-listings' ),
			__( 'events', 'newspack-listings' ),
		],

		attributes,

		edit: EventDatesEditor,
		save: () => null, // uses view.php
	} );
};
