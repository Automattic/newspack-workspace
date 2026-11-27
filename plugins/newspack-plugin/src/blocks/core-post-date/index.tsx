/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { isModifiedDate, getLabelParts } from './utils';
import type { PostDateAttributes } from './utils';

/**
 * Props the `editor.BlockListBlock` filter passes through, limited to the
 * members this label filter reads and forwards.
 */
type BlockListBlockProps = {
	name: string;
	attributes: PostDateAttributes;
	wrapperProps?: Record< string, unknown >;
	[ key: string ]: unknown;
};

/**
 * Show the "Updated" label on modified dates in the editor, matching the
 * front end. The label is drawn by CSS from these data attributes (see
 * Post_Date::enqueue_block_assets()), since core's edit component owns the
 * date markup.
 */
addFilter(
	'editor.BlockListBlock',
	'newspack-plugin/core-post-date/updated-label',
	createHigherOrderComponent( ( BlockListBlock: React.ComponentType< BlockListBlockProps > ) => {
		return ( props: BlockListBlockProps ) => {
			const label = ( window as Window & { newspack_blocks?: { post_date_updated_label?: string } } ).newspack_blocks?.post_date_updated_label;
			if ( props.name !== 'core/post-date' || ! label || ! isModifiedDate( props.attributes ) ) {
				return <BlockListBlock { ...props } />;
			}
			const { before, after } = getLabelParts( label );
			const wrapperProps = {
				...props.wrapperProps,
				'data-newspack-updated-before': before || undefined,
				'data-newspack-updated-after': after || undefined,
			};
			return <BlockListBlock { ...props } wrapperProps={ wrapperProps } />;
		};
	}, 'withUpdatedDateLabel' )
);
