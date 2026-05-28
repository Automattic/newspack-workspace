/**
 * External dependencies
 */
import { some } from 'lodash';

/**
 * WordPress dependencies
 */
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { useEffect } from '@wordpress/element';

const NestedColumnsDetectionBase = ( { blocks, updateBlock } ) => {
	const handleWarning = ( block, condition, warningKeyName ) => {
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

	const warnIfColumnHasColumns = block => {
		if ( block.name === 'core/column' ) {
			const hasColumns = some( block.innerBlocks, ( { name } ) => name === 'core/columns' );
			handleWarning( block, hasColumns, '__nestedColumnWarning' );
		}
		block.innerBlocks.forEach( warnIfColumnHasColumns );
	};

	const warnIfIsGroupBlock = block => {
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

export const NestedColumnsDetection = compose( [
	withSelect( select => {
		const { getBlocks } = select( 'core/block-editor' );
		return {
			blocks: getBlocks(),
		};
	} ),
	withDispatch( dispatch => {
		return {
			updateBlock: ( id, block ) => {
				dispatch( 'core/block-editor' ).replaceBlock( id, block );
			},
		};
	} ),
] )( NestedColumnsDetectionBase );
