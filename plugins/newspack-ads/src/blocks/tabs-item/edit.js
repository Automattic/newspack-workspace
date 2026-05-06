/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { compose } from '@wordpress/compose';
import { withSelect } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import createFilterableComponent from '../utils/createFilterableComponent';

const FilterableTabsItemHeader = createFilterableComponent( 'newspack.tabsItem.header' );
const FilterableTabsItemFooter = createFilterableComponent( 'newspack.tabsItem.footer' );

const TabsItemEdit = props => {
	const {
		isSelected,
		hasSelectedInnerBlock,
		setAttributes,
		clientId,
		position,
		name,
		attributes: { header },
	} = props;

	const blockProps = useBlockProps( {
		className: isSelected || hasSelectedInnerBlock() ? 'newspack-ads__tab-content is-active' : 'newspack-ads__tab-content',
	} );

	return (
		<div { ...blockProps }>
			<FilterableTabsItemHeader blockProps={ props } />
			<div data-tab-block={ clientId } className={ `tab-header orientation-horizontal position-${ position }` }>
				{ /* The reason we don't have the RichText field in the parent block is so that when you are editing tab header text you are selecting
				the child block. */ }
				<RichText
					tagName="div"
					value={ header }
					placeholder={ __( 'Tab Header', 'newspack-ads' ) }
					onChange={ newHeader => {
						setAttributes( {
							header: decodeEntities( newHeader ).replace( /<\/?[a-z][^>]*?>/gi, ' ' ),
						} );
					} }
					allowedFormats={ [] }
				/>
			</div>

			<InnerBlocks
				templateInsertUpdatesSelection
				__experimentalCaptureToolbars
				allowedBlocks={ applyFilters( 'newspack.tabs.allowedBlocks', true, name ) }
			/>
			<FilterableTabsItemFooter blockProps={ props } />
		</div>
	);
};

TabsItemEdit.propTypes = {
	attributes: PropTypes.shape( {
		header: PropTypes.string.isRequired,
	} ).isRequired,
	clientId: PropTypes.string.isRequired,
	isSelected: PropTypes.bool.isRequired,
	setAttributes: PropTypes.func.isRequired,
	hasSelectedInnerBlock: PropTypes.func,
};

export default compose(
	withSelect( ( select, { clientId } ) => {
		const { hasSelectedInnerBlock, getBlockParents, getBlock } = select( 'core/block-editor' );

		const parentBlockIds = getBlockParents( clientId );
		const parentBlockId = parentBlockIds[ parentBlockIds.length - 1 ];

		const parentBlock = getBlock( parentBlockId );

		let position = 0;

		parentBlock.innerBlocks.forEach( ( parentInnerBlock, key ) => {
			if ( parentInnerBlock.clientId === clientId ) {
				position = key;
			}
		} );

		return {
			position,
			hasSelectedInnerBlock,
		};
	} )
)( TabsItemEdit );
