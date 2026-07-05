/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockEditProps } from '@wordpress/blocks';
import { calendar } from '@wordpress/icons';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { EventDatesEditor, type EventDatesAttributes } from './edit';
import metadata from './block.json';
const { attributes, category, name } = metadata;

export const registerEventDatesBlock = () => {
	// See the matching comment in `../price/index.tsx` for why this wrapper
	// exists (bridges the generic default `Attributes` with `EventDatesEditor`'s
	// specific `EventDatesAttributes`).
	const edit: ComponentType< BlockEditProps< Record< string, unknown > > > = ( { attributes: editAttributes, clientId, setAttributes } ) => (
		<EventDatesEditor attributes={ editAttributes as EventDatesAttributes } clientId={ clientId } setAttributes={ setAttributes } />
	);

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

		edit,
		save: () => null, // uses view.php
	} );
};
