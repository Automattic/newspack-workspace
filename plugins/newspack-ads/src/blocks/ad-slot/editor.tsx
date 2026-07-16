/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { registerBlock } from '../utils/register-block';
import { ad as icon } from '../utils/icons';
import edit from './edit';
import metadata from './block.json';

/**
 * Style dependencies - will load in editor.
 */
import './editor.scss';

const { name } = metadata;

const labels = {
	title: __( 'Ad Slot', 'newspack-ads' ),
	description: __( 'Render an ad in a wizard-managed global placement (above header, sticky footer, etc.).', 'newspack-ads' ),
};

// Only insertable in the Site Editor. The block stays registered everywhere so
// persisted instances remain valid; it's just hidden from the post-editor inserter.
const isSiteEditor = window.location.pathname.endsWith( '/wp-admin/site-editor.php' );

const adSlot = {
	name,
	settings: {
		...metadata,
		...labels,
		supports: {
			...metadata.supports,
			inserter: isSiteEditor,
		},
		icon: {
			src: icon,
			foreground: '#406ebc',
		},
		edit,
		save: () => null, // Dynamic block — PHP render_block() owns the front-end markup.
	},
};

// wp.domReady is required for core filters to work with this custom block.
// See https://github.com/WordPress/gutenberg/issues/9757.
wp.domReady( () => {
	registerBlock( adSlot );
} );
