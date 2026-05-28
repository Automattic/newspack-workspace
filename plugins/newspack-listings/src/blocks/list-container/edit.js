/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelRow, Spinner } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';

const ListContainerEditorComponent = ( { attributes, clientId, innerBlocks } ) => {
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

const mapStateToProps = ( select, ownProps ) => {
	const { clientId } = ownProps;
	const { getBlock } = select( 'core/block-editor' );
	const innerBlocks = getBlock( clientId ).innerBlocks || [];

	return {
		innerBlocks,
	};
};

const mapDispatchToProps = dispatch => {
	const { insertBlock, removeBlocks, updateBlockAttributes } = dispatch( 'core/block-editor' );

	return {
		insertBlock,
		removeBlocks,
		updateBlockAttributes,
	};
};

export const ListContainerEditor = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( ListContainerEditorComponent );
