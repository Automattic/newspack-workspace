/**
 * WordPress dependencies
 */
import { applyFilters } from '@wordpress/hooks';

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * Ensures that children is always an array so we can spread.
 *
 * @param children The child component(s)
 *
 * @return The list of children
 */
function prepareChildren( children: ReactNode ): ReactNode[] {
	return ! Array.isArray( children ) ? [ children ] : children;
}

type FilterableComponentProps = {
	children?: ReactNode;
	blockProps?: unknown;
};

/**
 * Create a filtered area component
 *
 * @param filterName The name of the filter to create
 * @return The component
 */
export default function createFilterableComponent( filterName: string ) {
	return ( { children, blockProps }: FilterableComponentProps ): ReactNode => {
		// `applyFilters` is untyped (WordPress hooks return `unknown`); by
		// default (no filter registered) this is just `prepareChildren`'s
		// array, which is a valid ReactNode.
		return applyFilters( filterName, prepareChildren( children ), blockProps ) as ReactNode;
	};
}
