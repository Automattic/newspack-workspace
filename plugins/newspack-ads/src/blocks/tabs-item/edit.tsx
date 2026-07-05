/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { compose } from '@wordpress/compose';
import { withSelect } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { decodeEntities } from '@wordpress/html-entities';
import type { Block } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import createFilterableComponent from '../utils/createFilterableComponent';

const FilterableTabsItemHeader = createFilterableComponent( 'newspack.tabsItem.header' );
const FilterableTabsItemFooter = createFilterableComponent( 'newspack.tabsItem.footer' );

type TabsItemEditProps = {
	isSelected: boolean;
	hasSelectedInnerBlock: () => boolean;
	setAttributes: ( attrs: Partial< { header: string } > ) => void;
	clientId: string;
	position: number;
	name: string;
	attributes: { header: string };
};

const TabsItemEdit = ( props: TabsItemEditProps ) => {
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
					onChange={ ( newHeader: string ) => {
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

/**
 * The subset of the `core/block-editor` store's selectors used below. The
 * package ships no store types, so this is hand-declared to match usage.
 */
type BlockEditorSelectors = {
	hasSelectedInnerBlock: () => boolean;
	getBlockParents: ( clientId: string ) => string[];
	// `getBlock` is typed non-nullable here (rather than `Block | null`, its
	// real return type) because the code below dereferences the result with
	// no null check -- it assumes a tabs-item block always has a parent.
	getBlock: ( clientId: string ) => Block;
};

export default compose(
	withSelect( ( select: unknown, ownProps: Record< string, unknown > ) => {
		const { clientId } = ownProps as { clientId: string };
		const { hasSelectedInnerBlock, getBlockParents, getBlock } = ( select as ( namespace: string ) => BlockEditorSelectors )(
			'core/block-editor'
		);

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
)( TabsItemEdit ) as ComponentType< Record< string, unknown > >;
