/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { registerBlock } from '../utils/register-block';
import { tabItem as icon } from '../utils/icons';
import edit from './edit';
import metadata from './block.json';

const { name } = metadata;

const labels = {
	title: __( 'Tab Item', 'newspack-ads' ),
	description: __( 'Add a new tab.', 'newspack-ads' ),
};

const tabsItem = {
	name,
	settings: {
		...metadata,
		...labels,
		icon: {
			src: icon,
			foreground: '#406ebc',
		},
		edit,
		save: () => <InnerBlocks.Content />,
	},
};

// Register the block - wp.domReady is required for core filters to work with this custom block. See - https://github.com/WordPress/gutenberg/issues/9757
wp.domReady( function () {
	registerBlock( tabsItem );
} );
