/**
 * Newspack dependencies
 */
import colors from 'newspack-colors';
import { gift as icon } from 'newspack-icons';

/**
 * WordPress dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerBlockStyle } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import edit from './edit';
import metadata from './block.json';

/**
 * Style dependencies - will load in editor
 */
import './styles/editor.scss';
import './styles/view.scss';

const { name, attributes, apiVersion, category, supports } = metadata;

// Name must be exported separately.
export { name };

export const title = __( 'Donate', 'newspack-plugin' );

export const settings = {
	apiVersion,
	title,
	icon: {
		src: icon,
		foreground: colors[ 'primary-400' ],
	},
	category,
	keywords: [ __( 'donate', 'newspack-plugin' ), __( 'memberships', 'newspack-plugin' ), __( 'subscriptions', 'newspack-plugin' ) ],
	description: (
		<>
			<p>{ __( 'Manually place a donation block on any post or page on your site.', 'newspack-plugin' ) }</p>
			<ExternalLink href="https://help.newspack.com/publishing-and-appearance/blocks/donate-block/">
				{ __( 'Support reference', 'newspack-plugin' ) }
			</ExternalLink>
		</>
	),
	attributes,
	supports,
	edit,
	save: () => null, // to use view.php
};

/**
 * Block Styles
 */
registerBlockStyle( 'newspack-blocks/donate', {
	name: 'alternate',
	label: __( 'Alternate', 'newspack-plugin' ),
} );

registerBlockStyle( 'newspack-blocks/donate', {
	name: 'minimal',
	label: __( 'Minimal', 'newspack-plugin' ),
} );

registerBlockStyle( 'newspack-blocks/donate', {
	name: 'modern',
	label: __( 'Modern', 'newspack-plugin' ),
} );
