/**
 * Fast Checkout block — settings export.
 */

import { __ } from '@wordpress/i18n';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';
import edit from './edit';
import './view.scss';

export const name: string = metadata.name;
export const title: string = __( 'Fast Checkout', 'newspack-blocks' );

function save() {
	const blockProps = useBlockProps.save();
	return (
		<div { ...blockProps }>
			<InnerBlocks.Content />
		</div>
	);
}

export const settings = {
	...metadata,
	title,
	edit,
	save,
};
