/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelRow, Spinner } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import type { DataRegistry } from '@wordpress/data';
import type { Block } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import type { CuratedListAttributes } from '../curated-list/edit';

type ListContainerEditorComponentProps = {
	attributes: CuratedListAttributes;
	clientId: string;
	innerBlocks: Block[];
};

const ListContainerEditorComponent = ( { attributes, clientId, innerBlocks }: ListContainerEditorComponentProps ) => {
	const { queryMode, queryOptions, showSortUi } = attributes;
	const { order } = queryOptions;
	const blockProps = useBlockProps( {
		className: 'newspack-listings__list-container',
	} );

	if ( queryMode && ! showSortUi ) {
		return <div { ...blockProps } style={ { display: 'none' } } />;
	}

	return (
		<>
			<InspectorControls>
				<PanelRow className="newspack-listings__list-container-spinner">
					<Spinner />
				</PanelRow>
			</InspectorControls>
			<div { ...blockProps }>
				{ ! queryMode && innerBlocks && 0 === innerBlocks.length && (
					<Notice className="newspack-listings__info" status="info" isDismissible={ false }>
						{ __( 'This list is empty. Click the [+] button to add some listings.' ) }
					</Notice>
				) }
				{ showSortUi && (
					<div className="newspack-listings__sort-ui">
						<section>
							<label className="newspack-listings__sort-ui-label" htmlFor={ `newspack-listings__sort-by-${ clientId }` }>
								{ __( 'Sort by:', 'newspack-listings' ) }
							</label>
							<select
								disabled // Just a dummy component for demo display.
								className="newspack-listings__sort-select-control"
								id={ `newspack-listings__sort-by-${ clientId }` }
							>
								<option value="" selected>
									{ __( 'Sort by', 'newspack-listings' ) }
								</option>
							</select>
						</section>

						<section>
							<label className="newspack-listings__sort-ui-label" htmlFor={ `sort-buttons-${ clientId }` }>
								{ __( 'Sort order:', 'newspack-listings' ) }
							</label>

							<div id={ `sort-buttons-${ clientId }` }>
								<input
									disabled
									id={ `sort-ascending-${ clientId }` }
									type="radio"
									name="newspack-listings__sort-order"
									value="ASC"
									checked={ queryMode && order === 'ASC' }
								/>
								<label htmlFor={ `sort-ascending-${ clientId }` }>{ __( 'Ascending', 'newspack-listings' ) }</label>
							</div>

							<div>
								<input
									disabled
									id={ `sort-descending-${ clientId }` }
									type="radio"
									name="newspack-listings__sort-order"
									value="DESC"
									checked={ queryMode && order === 'DESC' }
								/>
								<label htmlFor={ `sort-descending-${ clientId }` }>{ __( 'Descending', 'newspack-listings' ) }</label>
							</div>
						</section>
					</div>
				) }
				<InnerBlocks
					allowedBlocks={ [
						'newspack-listings/event',
						'newspack-listings/generic',
						'newspack-listings/marketplace',
						'newspack-listings/place',
					] }
					renderAppender={ () => ( queryMode ? null : <InnerBlocks.ButtonBlockAppender /> ) }
				/>
			</div>
		</>
	);
};

// The wordpress/data HOCs type mapSelect/mapDispatch params loosely (registry
// select/dispatch, Record ownProps); accept what they pass and narrow once,
// matching the pattern used in newspack-blocks' homepage-articles/utils.ts.
type Select = ( namespace: string ) => {
	getBlock: ( clientId: string ) => Block;
};

const mapStateToProps = ( select: unknown, ownProps: Record< string, unknown > ) => {
	const { clientId } = ownProps as { clientId: string };
	const { getBlock } = ( select as Select )( 'core/block-editor' );
	const innerBlocks = getBlock( clientId ).innerBlocks || [];

	return {
		innerBlocks,
	};
};

const mapDispatchToProps = ( dispatch: DataRegistry[ 'dispatch' ] ) => {
	const { insertBlock, removeBlocks, updateBlockAttributes } = dispatch( 'core/block-editor' );

	return {
		insertBlock,
		removeBlocks,
		updateBlockAttributes,
	};
};

// `compose`'s declared type is `(...funcs: Function[]) => ...` (a rest
// parameter, not a single array argument) even though its real implementation
// also flattens a single array of functions - called here with separate
// arguments to match the declared signature; behaviorally identical either
// way. `compose` itself is untyped, and `withSelect`/`withDispatch` type
// their injected props loosely - re-type the composed result at this
// boundary to the shape `registerBlockType` actually needs.
export const ListContainerEditor = compose(
	withSelect( mapStateToProps ),
	withDispatch( mapDispatchToProps )
)( ListContainerEditorComponent ) as ComponentType< Record< string, unknown > >;
