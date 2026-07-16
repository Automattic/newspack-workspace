/**
 * WorPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalBlockVariationPicker as BlockVariationPicker,
	InnerBlocks,
	InspectorControls,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import type { Block } from '@wordpress/blocks';
import {
	BaseControl,
	Button,
	ButtonGroup,
	Notice,
	PanelBody,
	PanelRow,
	Placeholder,
	RangeControl,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import type { DataRegistry } from '@wordpress/data';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { Icon, loop, postList } from '@wordpress/icons';
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import { Listing, type ListingPost, type ListingLocation, type ListingBlockAttributes } from '../listing/listing';
import { SidebarQueryControls } from '../../components';
import { List } from '../../svg';
import { getContrastRatio, getCuratedListClasses, useDidMount } from '../../editor/utils';

/**
 * `Button`'s types have dropped the legacy `isLarge` prop in this package
 * version (only `isSmall` remains) - re-type at this boundary to preserve the
 * existing call below without altering runtime behavior, matching the
 * `Toolbar` re-typing pattern in newspack-blocks' `blocks/iframe/edit.tsx`.
 */
const LegacyButton = Button as import('react').FC< import('react').ComponentProps< typeof Button > & { isLarge?: boolean } >;

/**
 * Debounced fetchPosts function outside of component scope.
 */
let debouncedFetchPosts: ReturnType< typeof setTimeout >;

/**
 * Absolute maximum number of listing posts to fetch in the editor.
 * This allows us to fetch all listing locations for a query-based list,
 * while also serving as a safeguard to ensure that we don't accidentally
 * fetch a massive number of posts if the query options are too broad.
 */
const MAX_EDITOR_ITEMS = 100;

/**
 * `authors`/`categories`/`tags`/`categoryExclusions`/`tagExclusions` allow
 * `undefined` entries, and `maxItems` allows `undefined`, to match
 * `../../components/sidebar-query-controls.tsx`'s own `QueryOptions` type --
 * this attribute is edited through that component's `AutocompleteTokenField`/
 * `RangeControl` fields, which can legitimately hand back those values.
 */
type QueryOptionTokenValue = string | number | undefined;

export type CuratedListQueryOptions = {
	type: string | null;
	authors: QueryOptionTokenValue[];
	categories: QueryOptionTokenValue[];
	tags: QueryOptionTokenValue[];
	categoryExclusions: QueryOptionTokenValue[];
	tagExclusions: QueryOptionTokenValue[];
	maxItems: number | undefined;
	sortBy: string;
	order: string;
};

/**
 * Matches `blocks/curated-list/block.json`'s `attributes`.
 */
export type CuratedListAttributes = {
	className: string;
	isSelected: boolean;
	showNumbers: boolean;
	showMap: boolean;
	showSortUi: boolean;
	showAuthor: boolean;
	showExcerpt: boolean;
	showImage: boolean;
	showCaption: boolean;
	imageShape: string;
	minHeight: number;
	showCategory: boolean;
	showTags: boolean;
	mediaPosition: string;
	categories: number[];
	tags: number[];
	tagExclusions: number[];
	typeScale: number;
	imageScale: number;
	textColor: string;
	backgroundColor: string;
	hasDarkBackground: boolean;
	startup: boolean;
	queryMode: boolean;
	queryOptions: CuratedListQueryOptions;
	listingIds: number[];
	queriedListings: ListingPost[];
	showLoadMore: boolean;
	loadMoreText: string;
};

/**
 * A `newspack-listings/list-container` block whose `innerBlocks` are the
 * individual listing blocks placed into it. Declared as its own shape
 * (rather than intersecting `Block`, whose own `innerBlocks: Block[]` field
 * would conflict with the narrower element type wanted here) covering only
 * the fields this file reads off `list`.
 */
type ListContainerChild = {
	clientId: string;
	innerBlocks: Block< ListingBlockAttributes >[];
};

type CuratedListEditorComponentProps = {
	attributes: CuratedListAttributes;
	canUseMapBlock: boolean;
	clientId: string;
	innerBlocks: Block[];
	insertBlocks: ( blocks: Block[], index: number | null, rootClientId: string ) => void;
	isSelected: boolean;
	removeBlocks: ( clientIds: string[] ) => void;
	selectBlock: ( clientId: string ) => void;
	selectedBlock: string | null;
	setAttributes: ( attributes: Partial< CuratedListAttributes > ) => void;
	updateBlockAttributes: ( clientIds: string | string[], attributes: Record< string, unknown >, uniqueByBlock?: boolean ) => void;
};

const CuratedListEditorComponent = ( {
	attributes,
	canUseMapBlock,
	clientId,
	innerBlocks,
	insertBlocks,
	isSelected,
	removeBlocks,
	selectBlock,
	selectedBlock,
	setAttributes,
	updateBlockAttributes,
}: CuratedListEditorComponentProps ) => {
	const [ error, setError ] = useState< string | null >( null );
	const [ isFetching, setIsFetching ] = useState( false );
	const [ locations, setLocations ] = useState< ListingLocation[] >( [] );
	const blockProps = useBlockProps( {
		className: 'newspack-listings__curated-list-editor',
	} );
	const {
		showNumbers,
		showMap,
		showSortUi,
		showAuthor,
		showExcerpt,
		showImage,
		showCaption,
		minHeight,
		showCategory,
		showTags,
		mediaPosition,
		typeScale,
		imageScale,
		textColor,
		backgroundColor,
		startup,
		queryMode,
		queryOptions,
		queriedListings,
		showLoadMore,
		loadMoreText,
	} = attributes;

	const isEmpty = !! window.newspack_listings_data.no_listings || false;
	const list = innerBlocks.find( innerBlock => innerBlock.name === 'newspack-listings/list-container' ) as ListContainerChild | undefined;
	const hasMap = innerBlocks.find( innerBlock => innerBlock.name === 'jetpack/map' );
	const classes = getCuratedListClasses( blockProps.className, attributes );
	const initialRender = useDidMount();

	/**
	 * Use current query options to get listing posts.
	 *
	 * @param {Object} query Query args.
	 * @return {void}
	 */
	const fetchPosts = async ( query: CuratedListQueryOptions ) => {
		if ( isFetching || ! queryMode ) {
			return;
		}

		setIsFetching( true );

		try {
			setError( null );
			const posts = await apiFetch< ListingPost[] >( {
				path: addQueryArgs( '/newspack-listings/v1/listings', {
					// FLAG (pre-existing, not fixed here): `query` always carries its own
					// `maxItems` (see `CuratedListQueryOptions`), so it always overwrites
					// the `MAX_EDITOR_ITEMS` default below -- the "up to MAX_EDITOR_ITEMS"
					// comment doesn't actually hold. `Object.assign` (a function call, not
					// an object literal) produces the exact same merged result as the
					// original spread while sidestepping tsc's "specified more than once,
					// will be overwritten" diagnostic on that literal pattern.
					query: Object.assign( { maxItems: MAX_EDITOR_ITEMS }, query ),
					_fields: 'id,title,author,category,tags,excerpt,media,meta,type,sponsors,classes',
				} ),
			} );
			setAttributes( { listingIds: posts.map( post => post.id ) } );
			setAttributes( { queriedListings: posts } );

			if ( 0 === posts.length ) {
				throw 'No posts matching query options. Try selecting different or less specific query options.';
			}
		} catch ( e ) {
			// The catch value can be either the string thrown above or an apiFetch
			// rejection; preserved as-is (matching the original untyped JS) rather
			// than narrowed/extracted, since this state is rendered directly.
			setError( e as string );
		}

		setIsFetching( false );
	};

	/**
	 * If changing query options, fetch listing posts that match the query.
	 */
	useEffect( () => {
		if ( initialRender ) {
			fetchPosts( queryOptions );
		} else {
			// Debounced version of fetchPosts to minimize consecutive executions.
			clearTimeout( debouncedFetchPosts );
			debouncedFetchPosts = setTimeout( () => {
				fetchPosts( queryOptions );
			}, 500 );
		}
	}, [ JSON.stringify( queryOptions ), queryMode ] );

	/**
	 * Set isSelected attribute so child blocks know selected state.
	 */
	useEffect( () => {
		setAttributes( { isSelected } );
	}, [ isSelected ] );

	/**
	 * Update locations in component state. This lets us keep the map block in sync with listing items.
	 */
	useEffect( () => {
		if ( ! canUseMapBlock ) {
			return;
		}

		let newLocations: ListingLocation[] = [];

		// Only build locations array if we have any listings, and the Jetpack Maps block exists.
		if ( queryMode ) {
			newLocations = queriedListings.reduce< ListingLocation[] >( ( acc, queriedListing ) => {
				if ( queriedListing.meta && queriedListing.meta.newspack_listings_locations ) {
					queriedListing.meta.newspack_listings_locations.map( location => {
						if ( isValidLocation( location ) ) {
							acc.push( location );
						}

						return acc;
					} );
				}

				return acc;
			}, [] );
		} else {
			newLocations = list
				? list.innerBlocks.reduce< ListingLocation[] >( ( acc, innerBlock ) => {
						if ( innerBlock.attributes.locations && 0 < innerBlock.attributes.locations.length ) {
							innerBlock.attributes.locations.map( location => {
								if ( isValidLocation( location ) ) {
									acc.push( location );
								}

								return acc;
							} );
						}
						return acc;
				  }, [] )
				: [];
		}

		setLocations( newLocations );
	}, [ list, JSON.stringify( queriedListings ), queryMode ] );

	/**
	 * Keep track of post IDs of all nested listings in specific listings mode.
	 */
	useEffect( () => {
		if ( ! queryMode && list ) {
			const newListingIds = list.innerBlocks.map( innerBlock => parseInt( innerBlock.attributes.listing ) );

			setAttributes( { listingIds: newListingIds } );
		}
	}, [ list ] );

	/**
	 * Create, update, or remove map when showMap attribute or locations change.
	 */
	useEffect( () => {
		// Don't run on the initial render.
		if ( initialRender ) {
			return;
		}

		// Don't bother if the Jetpack Maps block doesn't exist.
		if ( ! canUseMapBlock ) {
			return;
		}

		// If showMap toggle is enabled, update the existing map or create a new one.
		if ( showMap && 0 < locations.length ) {
			if ( hasMap ) {
				// If we already have a map, update it.
				updateBlockAttributes( hasMap.clientId, { points: locations } );
			} else {
				// Don't add a new map unless we have some locations to show.
				if ( 0 === locations.length ) {
					return;
				}

				// Create a new map at the top of the list.
				const newBlock = createBlock( 'jetpack/map', {
					points: locations,
				} );

				insertBlocks( [ newBlock ], 0, clientId );
			}
		} else if ( hasMap ) {
			// If disabling the showMap toggle, remove the existing map.
			removeBlocks( [ hasMap.clientId ] );
		}
	}, [ showMap, JSON.stringify( locations ) ] );

	/**
	 * Guard against accidentally deleting the list container block.
	 */
	useEffect( () => {
		if ( ! queryMode && ! list ) {
			// Create a new map at the bottom of the list.
			const newBlock = createBlock( 'newspack-listings/list-container' );

			insertBlocks( [ newBlock ], null, clientId );
		}
	}, [ list ] );

	/**
	 * Prevent focusing of "invisible" List Container wrapper block.
	 * Passes the focusing of List Container to this parent Curated List block.
	 */
	useEffect( () => {
		if ( list && selectedBlock === list.clientId ) {
			selectBlock( clientId );
		}
	}, [ selectedBlock ] );

	/**
	 * Determine if the background color is dark or light.
	 */
	useEffect( () => {
		if ( backgroundColor ) {
			const contrastRatio = getContrastRatio( backgroundColor );

			if ( contrastRatio < 5 ) {
				return setAttributes( { hasDarkBackground: true } );
			}
		}

		setAttributes( { hasDarkBackground: false } );
	}, [ backgroundColor ] );

	/**
	 * Sync parent attributes to inner blocks.
	 */
	useEffect( () => {
		if ( list ) {
			updateBlockAttributes(
				[ list.clientId ].concat( list.innerBlocks.map( innerBlock => innerBlock.clientId ) ), // Array of client IDs for both list container and individual listings.
				attributes,
				false
			);
		}
	}, [ JSON.stringify( attributes ) ] );

	/**
	 * Render the results of the listing query.
	 *
	 * @param {Object} listing Post object for listing to show.
	 * @param {number} index   Index of the item in the array.
	 */
	const renderQueriedListings = ( listing: ListingPost, index: number ) => (
		<div key={ index } className="newspack-listings__listing-editor newspack-listings__listing">
			<Listing attributes={ attributes } error={ error } post={ listing } />
			{
				<Button isLink href={ `/wp-admin/post.php?post=${ listing.id }&action=edit` } target="_blank">
					{ __( 'Edit this listing', 'newspack-listings' ) }
				</Button>
			}
		</div>
	);

	/**
	 * Validate location data.
	 *
	 * @param {*} location Location data to check.
	 * @return {boolean} True if the data is valid location data, false if not.
	 */
	const isValidLocation = ( location: ListingLocation ) => {
		if ( ! location || ! location.id || ! location.coordinates || ! location.coordinates.latitude || ! location.coordinates.longitude ) {
			return false;
		}

		return true;
	};

	/**
	 * Image size options for the sidebar.
	 */
	const imageSizeOptions = [
		{
			value: 1,
			label: /* translators: label for small size option */ __( 'Small', 'newspack-listings' ),
			shortName: /* translators: abbreviation for small size */ __( 'S', 'newspack-listings' ),
		},
		{
			value: 2,
			label: /* translators: label for medium size option */ __( 'Medium', 'newspack-listings' ),
			shortName: /* translators: abbreviation for medium size */ __( 'M', 'newspack-listings' ),
		},
		{
			value: 3,
			label: /* translators: label for large size option */ __( 'Large', 'newspack-listings' ),
			shortName: /* translators: abbreviation for large size */ __( 'L', 'newspack-listings' ),
		},
		{
			value: 4,
			label: /* translators: label for extra large size option */ __( 'Extra Large', 'newspack-listings' ),
			shortName: /* translators: abbreviation for extra large size */ __( 'XL', 'newspack-listings' ),
		},
	];

	/**
	 * Show a hint to the user if there are no listings that can be added to the list.
	 */
	if ( isEmpty ) {
		return (
			<div { ...blockProps }>
				<Placeholder icon={ <List /> } label={ __( 'Curated List', 'newspack-listings' ) }>
					<Notice isDismissible={ false }>{ __( 'Your site doesn’t have any listings. Create some to get started.' ) }</Notice>
				</Placeholder>
			</div>
		);
	}

	// Let user pick Query or Specific mode on startup.
	if ( startup ) {
		return (
			<div { ...blockProps }>
				<div className="newspack-listings__placeholder">
					<BlockVariationPicker
						icon={ <List /> }
						label={ __( 'Curated List', 'newspack-listings' ) }
						instructions={ __( 'Select the type of list to start with.' ) }
						// `BlockVariationPicker` (`__experimentalBlockVariationPicker`) comes from
						// `@wordpress/block-editor`, which ships no types at all (see
						// `packages/scripts/types/wordpress-block-editor.d.ts`) -- annotate this
						// callback param at that opaque boundary.
						onSelect={ ( variation: { name?: string } ) => {
							if ( variation.name && 'query' === variation.name ) {
								setAttributes( {
									queryMode: true,
									startup: false,
								} );
							} else {
								setAttributes( {
									startup: false,
								} );
							}
						} }
						variations={ [
							{
								name: 'query',
								title: __( 'Query', 'newspack-listings' ),
								icon: <Icon icon={ loop } />,
							},
							{
								name: 'specific',
								title: __( 'Specific Listings', 'newspack-listings' ),
								icon: <Icon icon={ postList } />,
							},
						] }
					/>
				</div>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				{ queryMode && (
					<PanelBody title={ __( 'Query Settings', 'newspack-listings' ) }>
						<SidebarQueryControls
							disabled={ isFetching }
							setAttributes={ setAttributes }
							queryOptions={ queryOptions }
							showAuthor={ showAuthor }
							showLoadMore={ showLoadMore }
							loadMoreText={ loadMoreText }
						/>
					</PanelBody>
				) }
				<PanelBody title={ __( 'List Settings', 'newspack-listings' ) }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show list item numbers', 'newspack-listings' ) }
							checked={ showNumbers }
							onChange={ () => setAttributes( { showNumbers: ! showNumbers } ) }
						/>
					</PanelRow>

					{ canUseMapBlock && (
						<PanelRow>
							<ToggleControl
								label={ __( 'Show map', 'newspack-listings' ) }
								help={ sprintf(
									// translators: %d: maximum number of items to display on the map.
									__(
										'The map will display locations for up to %d items in the list, regardless of the current number of items shown.',
										'newspack-listings'
									),
									MAX_EDITOR_ITEMS
								) }
								checked={ showMap }
								onChange={ () => setAttributes( { showMap: ! showMap } ) }
							/>
						</PanelRow>
					) }
					<PanelRow>
						<ToggleControl
							label={ __( 'Show sort UI', 'newspack-listings' ) }
							checked={ showSortUi }
							onChange={ () => setAttributes( { showSortUi: ! showSortUi } ) }
						/>
					</PanelRow>
				</PanelBody>
				<PanelBody title={ __( 'Featured Image Settings', 'newspack-listings' ) }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show Featured Image', 'newspack-listings' ) }
							checked={ showImage }
							onChange={ () => setAttributes( { showImage: ! showImage } ) }
						/>
					</PanelRow>

					{ showImage && (
						<Fragment>
							<PanelRow>
								<ToggleControl
									label={ __( 'Show Featured Image Caption', 'newspack-listings' ) }
									checked={ showCaption }
									onChange={ () => setAttributes( { showCaption: ! showCaption } ) }
								/>
							</PanelRow>
							<SelectControl
								label={ __( 'Featured Image Position', 'newspack-listings' ) }
								// `SelectControl`'s `value` type is inferred from the literal `options`
								// below ('top' | 'left' | 'right'), narrower than `mediaPosition`'s
								// declared `string` (block.json only declares `"type": "string"`, so
								// the attribute itself isn't restricted to these three values) - cast
								// at this rendering boundary only.
								value={ mediaPosition as 'top' | 'left' | 'right' }
								onChange={ value => setAttributes( { mediaPosition: value } ) }
								options={ [
									{ label: __( 'Top', 'newspack-listings' ), value: 'top' },
									{ label: __( 'Left', 'newspack-listings' ), value: 'left' },
									{ label: __( 'Right', 'newspack-listings' ), value: 'right' },
								] }
							/>
						</Fragment>
					) }

					{ showImage && mediaPosition !== 'top' && mediaPosition !== 'behind' && (
						<Fragment>
							<BaseControl label={ __( 'Featured Image Size', 'newspack-listings' ) } id="newspackfeatured-image-size">
								<PanelRow>
									<ButtonGroup id="newspackfeatured-image-size" aria-label={ __( 'Featured Image Size', 'newspack-listings' ) }>
										{ imageSizeOptions.map( option => {
											const isCurrent = imageScale === option.value;
											return (
												<LegacyButton
													isLarge
													isPrimary={ isCurrent }
													aria-pressed={ isCurrent }
													aria-label={ option.label }
													key={ option.value }
													onClick={ () => setAttributes( { imageScale: option.value } ) }
												>
													{ option.shortName }
												</LegacyButton>
											);
										} ) }
									</ButtonGroup>
								</PanelRow>
							</BaseControl>
						</Fragment>
					) }

					{ showImage && mediaPosition === 'behind' && (
						<RangeControl
							label={ __( 'Minimum height', 'newspack-listings' ) }
							help={ __(
								"Sets a minimum height for the block, using a percentage of the screen's current height.",
								'newspack-listings'
							) }
							value={ minHeight }
							onChange={ _minHeight => setAttributes( { minHeight: _minHeight } ) }
							min={ 0 }
							max={ 100 }
							required
						/>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Post Control Settings', 'newspack-listings' ) }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show Excerpt', 'newspack-listings' ) }
							checked={ showExcerpt }
							onChange={ () => setAttributes( { showExcerpt: ! showExcerpt } ) }
						/>
					</PanelRow>
					<RangeControl
						className="type-scale-slider"
						label={ __( 'Type Scale', 'newspack-listings' ) }
						value={ typeScale }
						onChange={ _typeScale => setAttributes( { typeScale: _typeScale } ) }
						min={ 1 }
						max={ 10 }
						required
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Color Settings', 'newspack-listings' ) }
					initialOpen={ true }
					// `PanelColorSettings` comes from `@wordpress/block-editor`, which ships no
					// types at all (see `packages/scripts/types/wordpress-block-editor.d.ts`) --
					// annotate these callback params at that opaque boundary.
					colorSettings={ [
						{
							value: textColor,
							onChange: ( value: string ) => setAttributes( { textColor: value } ),
							label: __( 'Text Color', 'newspack-listings' ),
						},
						{
							value: backgroundColor,
							onChange: ( value: string ) => setAttributes( { backgroundColor: value } ),
							label: __( 'Background Color', 'newspack-listings' ),
						},
					] }
				/>
				<PanelBody title={ __( 'Meta Settings', 'newspack-listings' ) }>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show Author', 'newspack-listings' ) }
							checked={ showAuthor }
							onChange={ () => setAttributes( { showAuthor: ! showAuthor } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show Category', 'newspack-listings' ) }
							checked={ showCategory }
							onChange={ () => setAttributes( { showCategory: ! showCategory } ) }
						/>
					</PanelRow>
					<PanelRow>
						<ToggleControl
							label={ __( 'Show Tags', 'newspack-listings' ) }
							checked={ showTags }
							onChange={ () => setAttributes( { showTags: ! showTags } ) }
						/>
					</PanelRow>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div
					className={ classes.join( ' ' ) }
					style={ {
						backgroundColor: backgroundColor || '#fff',
						color: textColor || '#000',
					} }
				>
					{ queryMode && error && (
						<Notice className="newspack-listings__error" status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<InnerBlocks
						allowedBlocks={ [ 'jetpack/map', 'newspack-listings/list-container' ] }
						template={ [ [ 'newspack-listings/list-container' ] ] } // Start with an empty list only.
						templateInsertUpdatesSelection={ false }
						renderAppender={ () => null } // We want to discourage editors from adding blocks in this top-level wrapper, but we can't lock the template because we still need to be able to programmatically add or remove map blocks.
					/>
					{
						// If in query mode and while fetching posts.
						isFetching && queryMode && (
							<Placeholder>
								<Spinner />
							</Placeholder>
						)
					}
					{
						// If in query mode, show the queried listings.
						! isFetching && queryMode && queriedListings.map( renderQueriedListings )
					}
					{ /* `maxItems` defaults to 10 in block.json and is only ever `undefined` via the
					   `RangeControl` reset case handled in sidebar-query-controls.tsx; the original
					   untyped JS carried no guard here either. */ }
					{ ! isFetching && queryMode && showLoadMore && ( queryOptions.maxItems as number ) < queriedListings.length && (
						<Button className="newspack-listings__load-more" isPrimary>
							{ loadMoreText }
						</Button>
					) }
				</div>
			</div>
		</>
	);
};

// The wordpress/data HOCs type mapSelect/mapDispatch params loosely (registry
// select/dispatch, Record ownProps); accept what they pass and narrow once,
// matching the pattern used in newspack-blocks' homepage-articles/utils.ts.
type Select = ( namespace: string ) => {
	// core/block-editor
	getBlocksByClientId: ( clientId: string ) => Block[];
	getSelectedBlockClientId: () => string | null;
	// core/blocks
	getBlockType: ( name: string ) => unknown;
};

const mapStateToProps = ( select: unknown, ownProps: Record< string, unknown > ) => {
	const typedSelect = select as Select;
	const { clientId } = ownProps as { clientId: string };
	const { getBlocksByClientId, getSelectedBlockClientId } = typedSelect( 'core/block-editor' );
	const { getBlockType } = typedSelect( 'core/blocks' );
	const innerBlocks = getBlocksByClientId( clientId )[ 0 ].innerBlocks || [];
	const canUseMapBlock = !! getBlockType( 'jetpack/map' ); // Check for existence of Jetpack Map block before enabling location-based features.

	return {
		canUseMapBlock,
		selectedBlock: getSelectedBlockClientId(),
		innerBlocks,
	};
};

const mapDispatchToProps = ( dispatch: DataRegistry[ 'dispatch' ] ) => {
	const { insertBlocks, removeBlocks, selectBlock, updateBlockAttributes } = dispatch( 'core/block-editor' );

	return {
		insertBlocks,
		removeBlocks,
		selectBlock,
		updateBlockAttributes,
	};
};

// `compose`'s declared type is `(...funcs: Function[]) => ...` (a rest
// parameter, not a single array argument) even though its real implementation
// also flattens a single array of functions (see `@wordpress/compose`'s
// `basePipe`) - called here with separate arguments to match the declared
// signature; behaviorally identical either way. `compose` itself is untyped
// (returns `(...args: unknown[]) => unknown`), and `withSelect`/`withDispatch`
// type their injected props loosely - re-type the composed result at this
// boundary to the shape `registerBlockType` actually needs, matching the
// pattern used in newspack-blocks' carousel/edit.tsx.
export const CuratedListEditor = compose(
	withSelect( mapStateToProps ),
	withDispatch( mapDispatchToProps )
)( CuratedListEditorComponent ) as ComponentType< Record< string, unknown > >;
