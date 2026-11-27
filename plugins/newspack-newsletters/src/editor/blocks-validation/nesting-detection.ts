/**
 * External dependencies
 */
import { some } from 'lodash';

/**
 * WordPress dependencies
 */
import { withSelect, withDispatch } from '@wordpress/data';
import type { DataRegistry } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { useEffect } from '@wordpress/element';
import type { ComponentType } from 'react';

interface EditorBlock {
	name: string;
	clientId: string;
	attributes: Record< string, unknown >;
	innerBlocks: EditorBlock[];
}

interface NestedColumnsDetectionProps {
	blocks: EditorBlock[];
	updateBlock: ( id: string, block: EditorBlock ) => void;
}

const NestedColumnsDetectionBase = ( { blocks, updateBlock }: NestedColumnsDetectionProps ) => {
	const handleWarning = ( block: EditorBlock, condition: boolean, warningKeyName: string ) => {
		const hasWarning = block.attributes[ warningKeyName ] === true;

		if ( condition && ! hasWarning ) {
			updateBlock( block.clientId, {
				...block,
				attributes: { ...block.attributes, [ warningKeyName ]: true },
			} );
		} else if ( ! condition && hasWarning ) {
			updateBlock( block.clientId, {
				...block,
				attributes: { ...block.attributes, [ warningKeyName ]: false },
			} );
		}
	};

	const warnIfColumnHasColumns = ( block: EditorBlock ) => {
		if ( block.name === 'core/column' ) {
			const hasColumns = some( block.innerBlocks, ( { name } ) => name === 'core/columns' );
			handleWarning( block, hasColumns, '__nestedColumnWarning' );
		}
		block.innerBlocks.forEach( warnIfColumnHasColumns );
	};

	const warnIfIsGroupBlock = ( block: EditorBlock ) => {
		handleWarning( block, block.name === 'core/group', '__nestedGroupWarning' );
		block.innerBlocks.forEach( warnIfIsGroupBlock );
	};

	useEffect( () => {
		blocks.forEach( block => {
			// A column cannot host columns.
			block.innerBlocks.forEach( warnIfColumnHasColumns );
			// Group can only be top-level.
			block.innerBlocks.forEach( warnIfIsGroupBlock );
		} );
	}, [ blocks ] );

	return null;
};

export const NestedColumnsDetection = compose(
	withSelect( ( select: DataRegistry[ 'select' ] ) => {
		const getBlocks = select( 'core/block-editor' ).getBlocks as () => EditorBlock[];
		return {
			blocks: getBlocks(),
		};
	} ),
	withDispatch( ( ( dispatch: DataRegistry[ 'dispatch' ] ) => {
		return {
			updateBlock: ( id: string, block: EditorBlock ) => {
				const replaceBlock = dispatch( 'core/block-editor' ).replaceBlock as ( id: string, block: EditorBlock ) => void;
				replaceBlock( id, block );
			},
		};
	} ) as Parameters< typeof withDispatch >[ 0 ] )
)( NestedColumnsDetectionBase ) as ComponentType;
