/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { iframe as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';
const { name, attributes, apiVersion, category } = metadata;

/**
 * Style dependencies - will load in editor
 */
import './editor.scss';

export const title = __( 'Iframe', 'newspack-blocks' );

// Name must be exported separately.
export { name };

export const settings = {
	apiVersion,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	category,
	keywords: [ __( 'iframe', 'newspack-blocks' ), __( 'project iframe', 'newspack-blocks' ) ],
	description: (
		<>
			<p>{ __( 'Embed an iframe.', 'newspack-blocks' ) }</p>
			<ExternalLink href="https://help.newspack.com/publishing-and-appearance/blocks/iframe-block/">
				{ __( 'Support reference', 'newspack-blocks' ) }
			</ExternalLink>
		</>
	),
	attributes,
	supports: {
		html: false,
		align: [ 'wide', 'full' ],
	},
	edit,
	save: () => null, // to use view.php
};
