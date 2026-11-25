/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { tabs as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { registerBlock } from '../utils/register-block';
import edit from './edit';
import metadata from './block.json';

const { name } = metadata;

const labels = {
	title: __( 'Tabs', 'newspack-ads' ),
	description: __( 'Add tabbed content.', 'newspack-ads' ),
};

const tabs = {
	name,
	settings: {
		...metadata,
		...labels,
		icon: {
			src: icon,
			foreground: '#406ebc',
		},
		template: [ [ 'newspack/tabs-item', { header: '' }, [ [ 'core/paragraph', {} ] ] ] ],
		templateInsertUpdatesSelection: true,
		edit,
		save: () => <InnerBlocks.Content />,
	},
};

// Register the block
// wp.domReady is required for core filters to work with this custom block. See - https://github.com/WordPress/gutenberg/issues/9757
wp.domReady( function () {
	registerBlock( tabs );
} );
