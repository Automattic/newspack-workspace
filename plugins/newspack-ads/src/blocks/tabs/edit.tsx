/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { compose, ifCondition, useRefEffect } from '@wordpress/compose';
import { useState, useEffect, useLayoutEffect, useCallback, Fragment } from '@wordpress/element';
import { withSelect, withDispatch } from '@wordpress/data';
import { Button, NavigableMenu } from '@wordpress/components';
import { plus } from '@wordpress/icons';
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import type { Block } from '@wordpress/blocks';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import createFilterableComponent from '../utils/createFilterableComponent';
import './editor.scss';

const FilterableTabsHeader = createFilterableComponent( 'newspack.tabs.header' );
const FilterableTabsFooter = createFilterableComponent( 'newspack.tabs.footer' );

type TabsEditProps = {
	isSelected: boolean;
	clientId: string;
	setAttributes: ( attrs: Record< string, unknown > ) => void;
	block: Block;
	selectBlock: ( clientId: string ) => void;
	insertBlock: ( block: Block, index?: number, rootClientId?: string ) => void;
	removeBlock: ( clientId: string ) => void;
	activeClass?: string;
};

const TabsEdit = ( props: TabsEditProps ) => {
	const { isSelected, clientId, block, selectBlock, insertBlock, removeBlock, activeClass = 'is-active' } = props;
	const { innerBlocks } = block;
	const [ tabCount, setTabCount ] = useState( innerBlocks.length );
	const [ editTab, setEditTab ] = useState( '' );
	const [ blockElement, setBlockElement ] = useState< HTMLDivElement | null >( null );

	const ref = useRefEffect< HTMLDivElement >( element => {
		setBlockElement( element );
		return () => setBlockElement( null );
	}, [] );

	const blockProps = useBlockProps( {
		ref,
		className: classnames( 'tabs-horizontal', {
			border: ! isSelected,
			'components-tab-panel__tabs-item-is-editing': editTab,
		} ),
	} );

	const resetEditing = useCallback( () => {
		if ( ! blockElement ) {
			return;
		}
		const isEditing = blockElement.querySelectorAll( '.wp-block[data-is-tab-header-editing]' );
		if ( isEditing ) {
			isEditing.forEach( _block => _block.removeAttribute( 'data-is-tab-header-editing' ) );
		}
	}, [ blockElement ] );

	const onSelect = useCallback(
		( tabName: string ) => {
			setEditTab( tabName );
			selectBlock( tabName );
		},
		[ selectBlock ]
	);

	useEffect( () => {
		const firstBlock = innerBlocks.length > 0 ? innerBlocks[ 0 ].clientId : null;

		// When last tab item is deleted
		if ( innerBlocks.length < 1 && tabCount > innerBlocks.length ) {
			removeBlock( clientId );
		}

		// Action when tab is deleted
		if ( innerBlocks.length > 0 && tabCount > innerBlocks.length ) {
			// `firstBlock` is only used here, inside the `innerBlocks.length > 0` branch
			// that guarantees it was set to a clientId (not null) above.
			selectBlock( firstBlock! );

			// reset count
			setTabCount( innerBlocks.length );
		}

		// Hacky but required in order to select which is the innerblocks assigned to header
		if ( editTab && blockElement ) {
			const editTabEl = blockElement.ownerDocument.getElementById( `block-${ editTab }` );
			if ( editTabEl ) {
				// `setAttribute`'s value parameter is typed `string`; the DOM stringifies
				// a number identically, so `'1'` here renders the exact same attribute.
				editTabEl.setAttribute( 'data-is-tab-header-editing', '1' );
			}
		}
	}, [ selectBlock, clientId, tabCount, setTabCount, editTab, block, innerBlocks, removeBlock, activeClass, blockElement ] );

	/**
	 * Position each `.tab-header` overlay precisely on top of its corresponding tab
	 * button. The overlay lives inside `.newspack-ads__tab-group` (a different
	 * positioning context than the buttons), so we translate the button's viewport
	 * rect into the overlay's containing block. A ResizeObserver keeps the overlays
	 * in sync when buttons reflow (e.g. text wrap on viewport resize) without
	 * waiting for a React render.
	 */
	useLayoutEffect( () => {
		if ( ! blockElement ) {
			return;
		}

		const positionTabHeader = ( innerBlock: Block ) => {
			const tabHeaderButton = blockElement.querySelector< HTMLElement >(
				`.components-tab-panel__tabs-item[data-tab-block="${ innerBlock.clientId }"]`
			);
			if ( ! tabHeaderButton ) {
				return;
			}
			const tabHeader = blockElement.querySelector< HTMLElement >( `.tab-header[data-tab-block="${ innerBlock.clientId }"]` );
			// `offsetParent` is null while the tab is hidden (display:none); we
			// reposition once it becomes visible (editTab change re-runs this effect).
			const containingBlock = tabHeader && tabHeader.offsetParent;
			if ( ! containingBlock ) {
				return;
			}
			const containerRect = containingBlock.getBoundingClientRect();
			const buttonRect = tabHeaderButton.getBoundingClientRect();
			tabHeader.style.left = `${ buttonRect.left - containerRect.left }px`;
			tabHeader.style.top = `${ buttonRect.top - containerRect.top }px`;
			tabHeader.style.width = `${ buttonRect.width }px`;
			tabHeader.style.height = `${ buttonRect.height }px`;
		};

		const positionAll = () => innerBlocks.forEach( positionTabHeader );

		positionAll();

		// Track size changes of the block and each button (covers viewport resizes,
		// text wrapping mid-edit, font loading, etc. — anything that shifts the
		// button's rect without triggering a React render of this component).
		if ( typeof ResizeObserver === 'undefined' ) {
			return;
		}
		const observer = new ResizeObserver( positionAll );
		observer.observe( blockElement );
		innerBlocks.forEach( innerBlock => {
			const button = blockElement.querySelector( `.components-tab-panel__tabs-item[data-tab-block="${ innerBlock.clientId }"]` );
			if ( button ) {
				observer.observe( button );
			}
		} );
		return () => observer.disconnect();
	}, [ blockElement, innerBlocks, editTab ] );

	const tabPanels = innerBlocks.map( innerBlock => {
		// eslint-disable-next-line @typescript-eslint/no-shadow
		const { attributes, clientId: innerBlockClientId } = innerBlock;
		// `attributes` is `Record< string, unknown >` on the real `Block` type
		// (it isn't parameterized through `innerBlocks`); this block only ever
		// contains `newspack/tabs-item` children, whose `header` attribute is a string.
		const { header } = attributes as { header: string };
		// `orientation` isn't a `Button` prop (it belongs to `NavigableMenu`, see the
		// identical note below) -- pre-existing, not fixed here. Built as a loosely-typed
		// object and spread (rather than written as literal JSX attributes) so the extra
		// key doesn't trip the type checker; `Button` still receives it unchanged at runtime.
		const tabButtonProps = {
			orientation: 'horizontal',
			'data-tab-block': innerBlockClientId,
			className: classnames( 'newspack-ads__tab-item', { untitled: ! header }, 'components-tab-panel__tabs-item' ),
			label: header || __( 'Tab Header', 'newspack-ads' ),
			onClick: () => {
				resetEditing();
				onSelect( innerBlockClientId );
				if ( blockElement ) {
					const innerBlockEl = blockElement.ownerDocument.getElementById( `block-${ innerBlockClientId }` );
					if ( innerBlockEl ) {
						// See the identical `setAttribute` note above.
						innerBlockEl.setAttribute( 'data-is-tab-header-editing', '1' );
					}
				}
			},
		};

		return (
			<Fragment key={ innerBlockClientId }>
				<Button { ...tabButtonProps }>{ decodeEntities( header ) || __( 'Tab Header', 'newspack-ads' ) }</Button>
			</Fragment>
		);
	} );

	// `stopNavigationEvents`/`eventToOffset` are `NavigableContainer` props, not
	// `NavigableMenu` props (a sibling type -- `NavigableMenu` only adds `orientation`
	// on top of the shared base). Pre-existing, not fixed here; same loosely-typed
	// spread approach as the tab button above so the extra keys don't trip the checker.
	const navigableMenuProps = {
		stopNavigationEvents: true,
		eventToOffset: () => {
			return false;
		},
		role: 'tablist',
		orientation: 'horizontal',
		className: 'components-tab-panel__tabs newspack-ads__tab-list',
	} as const;

	return (
		<div { ...blockProps }>
			<FilterableTabsHeader blockProps={ props } />
			<div className="tab-control">
				<div className="tabs-header">
					<NavigableMenu { ...navigableMenuProps }>
						{ tabPanels }
						<Button
							className="add-tab-button"
							icon={ plus }
							label={ __( 'Add New Tab', 'newspack-ads' ) }
							variant="secondary"
							size="small"
							onClick={ () => {
								const created = createBlock(
									'newspack/tabs-item',
									{
										header: '',
									},
									[ createBlock( 'core/paragraph' ) ]
								);
								insertBlock( created, undefined, clientId );
								resetEditing();
								onSelect( created.clientId );
							} }
						/>
					</NavigableMenu>
				</div>
			</div>
			<div className="newspack-ads__tab-group">
				<InnerBlocks
					orientation="horizontal"
					allowedBlocks={ [ 'newspack/tabs-item' ] }
					template={ [ [ 'newspack/tabs-item', { header: '' }, [ [ 'core/paragraph', {} ] ] ] ] }
					templateInsertUpdatesSelection
					__experimentalCaptureToolbars
				/>
			</div>
			<FilterableTabsFooter blockProps={ props } />
		</div>
	);
};

/** The subset of the `core/block-editor` store's selectors used below. */
type BlockEditorSelectors = {
	getBlock: ( clientId: string ) => Block;
};

export default compose(
	withSelect( ( select: unknown, ownProps: Record< string, unknown > ) => {
		const { clientId } = ownProps as { clientId: string };
		const { getBlock } = ( select as ( namespace: string ) => BlockEditorSelectors )( 'core/block-editor' );
		return {
			block: getBlock( clientId ),
		};
	} ),
	withDispatch( ( dispatch: unknown ) => {
		// `withDispatch`'s own return type requires every property to be
		// `(...args: unknown[]) => unknown` (see `postsBlockDispatch` in
		// newspack-blocks/homepage-articles/utils.ts for the same pattern) --
		// keep the real action creators at that loose type here, and narrow
		// to the actual `core/block-editor` signatures only where `TabsEdit`
		// consumes them (its own prop type, further below).
		const typedDispatch = dispatch as ( namespace: string ) => Record< string, ( ...args: unknown[] ) => unknown >;
		const { selectBlock, insertBlock, removeBlock } = typedDispatch( 'core/block-editor' );
		return {
			selectBlock: ( id: unknown ) => selectBlock( id ),
			insertBlock,
			removeBlock,
		};
	} ),
	ifCondition( ( { block }: { block: Block } ) => {
		return Boolean( block && block.innerBlocks );
	} )
)( TabsEdit ) as ComponentType< Record< string, unknown > >;
