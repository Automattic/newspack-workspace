/**
 * External dependencies
 */
import clsx from 'clsx';
import type { ForwardedRef, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { forwardRef } from '@wordpress/element';

interface PostPanelRowProps {
	className?: string;
	label?: ReactNode;
	// Accepted (mirroring PostStatus's call site) but not read by this component.
	description?: ReactNode;
	children?: ReactNode;
}

const PostPanelRow = forwardRef( ( { className, label, children }: PostPanelRowProps, ref: ForwardedRef< HTMLDivElement > ) => {
	return (
		<HStack className={ clsx( 'editor-post-panel__row', className ) } ref={ ref }>
			{ label && <div className="editor-post-panel__row-label">{ label }</div> }
			<div className="editor-post-panel__row-control">{ children }</div>
		</HStack>
	);
} );

export default PostPanelRow;
