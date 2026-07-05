/**
 * External dependencies
 */
import { isUndefined, find, pickBy } from 'lodash';
import classnames from 'classnames';
import type { ComponentProps, ComponentType, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { registerBlockType, type Block, type BlockEditProps, type BlockSupports } from '@wordpress/blocks';
import { __, _x } from '@wordpress/i18n';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose as composeBase } from '@wordpress/compose';
import {
	BaseControl,
	FontSizePicker,
	PanelBody,
	RangeControl,
	ToggleControl,
	Toolbar as ToolbarBase,
	ToolbarButton,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import {
	BlockControls,
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	__experimentalPanelColorGradientSettings as PanelColorGradientSettings, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/block-editor';
import { Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { pages } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';
import './deduplication';
import blockDefinition from './block.json';
import { getTemplateBlocks, convertBlockSerializationFormat, type InserterPost } from './utils';
import QueryControlsSettings from './query-controls';
import { POSTS_INSERTER_BLOCK_NAME, POSTS_INSERTER_STORE_NAME } from './consts';
import PostsPreview from './posts-preview';

export interface InserterBlockAttributes {
	areBlocksInserted: boolean;
	preventDeduplication: boolean;
	displayFeaturedImage: boolean;
	displayPostSubtitle: boolean;
	displayPostExcerpt: boolean;
	displayPostDate: boolean;
	displayAuthor: boolean;
	displayContinueReading: boolean;
	displaySponsoredPosts: boolean;
	isDisplayingSpecificPosts: boolean;
	excerptLength: number;
	postsToShow: number;
	order: string;
	orderBy: string;
	postType: string;
	featuredImageAlignment?: string;
	featuredImageSize?: string;
	headingColor?: string;
	subHeadingColor?: string;
	textColor?: string;
	headingFontSize?: string | number;
	subHeadingFontSize?: string | number;
	textFontSize?: string | number;
	innerBlocksToInsert?: unknown[];
	specificPosts: Array< { id: number; title?: string } >;
	categories?: Array< { id: number; name?: string } >;
	tags?: Array< string | number >;
	tagExclusions?: Array< string | number >;
	categoryExclusions?: Array< string | number >;
}

interface PostsInserterBlockProps {
	setAttributes: ( attributes: Partial< InserterBlockAttributes > ) => void;
	attributes: InserterBlockAttributes;
	postList: InserterPost[];
	replaceBlocks: ( blocks: Block[] ) => void;
	setHandledPostsIds: ( ids: number[] ) => void;
	setInsertedPostsIds: ( ids: number[] ) => void;
	removeBlock: () => void;
	blockEditorSettings: { fontSizes?: ComponentProps< typeof FontSizePicker >[ 'fontSizes' ] };
}

// `Toolbar`'s current types only describe the modern (label-required, no `controls`)
// API, while the runtime still supports the legacy `controls`/label-less usage this
// block relies on. Cast to the legacy prop shape at this @wordpress/components boundary.
const Toolbar = ToolbarBase as ComponentType< {
	controls?: Array< Record< string, unknown > >;
	label?: string;
	children?: ReactNode;
} >;

const PostsInserterBlock = ( {
	setAttributes,
	attributes,
	postList,
	replaceBlocks,
	setHandledPostsIds,
	setInsertedPostsIds,
	removeBlock,
	blockEditorSettings,
}: PostsInserterBlockProps ) => {
	const [ isReady, setIsReady ] = useState( ! attributes.displayFeaturedImage );
	const blockProps = useBlockProps( {
		className: classnames( 'newspack-posts-inserter', {
			'newspack-posts-inserter--loading': ! isReady,
		} ),
	} );
	const stringifiedPostList = JSON.stringify( postList );

	// Stringify added to minimize flicker.
	const templateBlocks = useMemo( () => getTemplateBlocks( postList, attributes ), [ stringifiedPostList, attributes ] );
	const stringifiedTemplateBlocks = JSON.stringify( templateBlocks );
	const subtitleColorSettings: Array< {
		colorValue?: string;
		onColorChange: ( value: string ) => void;
		label: string;
	} > = [];

	if ( attributes.displayPostSubtitle ) {
		subtitleColorSettings.push( {
			colorValue: attributes.subHeadingColor,
			onColorChange: ( value: string ) => setAttributes( { subHeadingColor: value } ),
			label: __( 'Subtitle', 'newspack-newsletters' ),
		} );
	}

	useEffect( () => {
		const { isDisplayingSpecificPosts, specificPosts } = attributes;

		// No spinner if we're not dealing with images.
		if ( ! attributes.displayFeaturedImage ) {
			return setIsReady( true );
		}

		// No spinner if we're in the middle of selecting a specific post.
		if ( isDisplayingSpecificPosts && 0 === specificPosts.length ) {
			return setIsReady( true );
		}

		// Reset ready state.
		setIsReady( false );

		// If we have a post to show, check for featured image blocks.
		if ( 0 < postList.length ) {
			// Find all the featured images.
			const images = postList.reduce< number[] >( ( all, post ) => {
				if ( post.featured_media && ( post.featured_media_info?.large_url || post.featured_media_info?.medium_url ) ) {
					all.push( post.featured_media );
				}
				return all;
			}, [] );

			// If no posts have featured media, skip loading state.
			if ( 0 === images.length ) {
				return setIsReady( true );
			}

			// Wait for image blocks to be added to the BlockPreview.
			const imageBlocks = stringifiedTemplateBlocks.match( /\"name\":\"core\/image\"/g ) || [];

			// Preview is ready once all image blocks are accounted for.
			if ( imageBlocks.length >= images.length ) {
				setIsReady( true );
			}
		}
	}, [ stringifiedPostList, stringifiedTemplateBlocks ] );

	const innerBlocksToInsert = templateBlocks.map( convertBlockSerializationFormat );
	useEffect( () => {
		setAttributes( { innerBlocksToInsert } );
	}, [ JSON.stringify( innerBlocksToInsert ) ] );

	const handledPostIds = postList.map( post => post.id );

	useEffect( () => {
		if ( attributes.areBlocksInserted ) {
			replaceBlocks( templateBlocks );
			setInsertedPostsIds( handledPostIds );
		}
	}, [ attributes.areBlocksInserted ] );

	useEffect( () => {
		if ( ! attributes.preventDeduplication ) {
			setHandledPostsIds( handledPostIds );
			return removeBlock;
		}
	}, [ handledPostIds.join() ] );

	const blockControlsImages = [
		{
			icon: 'align-none',
			title: __( 'Show image on top', 'newspack-newsletters' ),
			isActive: attributes.featuredImageAlignment === 'top',
			onClick: () => setAttributes( { featuredImageAlignment: 'top' } ),
		},
		{
			icon: 'align-pull-left',
			title: __( 'Show image on left', 'newspack-newsletters' ),
			isActive: attributes.featuredImageAlignment === 'left',
			onClick: () => setAttributes( { featuredImageAlignment: 'left' } ),
		},
		{
			icon: 'align-pull-right',
			title: __( 'Show image on right', 'newspack-newsletters' ),
			isActive: attributes.featuredImageAlignment === 'right',
			onClick: () => setAttributes( { featuredImageAlignment: 'right' } ),
		},
	];

	const imageSizeOptions = [
		{
			value: 'small',
			label: _x( 'S', 'image size abbreviation', 'newspack-newsletters' ),
			ariaLabel: __( 'Small', 'newspack-newsletters' ),
		},
		{
			value: 'medium',
			label: _x( 'M', 'image size abbreviation', 'newspack-newsletters' ),
			ariaLabel: __( 'Medium', 'newspack-newsletters' ),
		},
		{
			value: 'large',
			label: _x( 'L', 'image size abbreviation', 'newspack-newsletters' ),
			ariaLabel: __( 'Large', 'newspack-newsletters' ),
		},
	];

	return attributes.areBlocksInserted ? null : (
		<Fragment>
			<InspectorControls>
				<PanelBody title={ __( 'Post Content', 'newspack-newsletters' ) }>
					<ToggleControl
						label={ __( 'Post subtitle', 'newspack-newsletters' ) }
						checked={ attributes.displayPostSubtitle }
						onChange={ value => setAttributes( { displayPostSubtitle: value } ) }
					/>
					<ToggleControl
						label={ __( 'Post excerpt', 'newspack-newsletters' ) }
						checked={ attributes.displayPostExcerpt }
						onChange={ value => setAttributes( { displayPostExcerpt: value } ) }
					/>
					{ attributes.displayPostExcerpt && (
						<RangeControl
							label={ __( 'Max number of words in excerpt', 'newspack-newsletters' ) }
							value={ attributes.excerptLength }
							onChange={ value => setAttributes( { excerptLength: value } ) }
							min={ 10 }
							max={ 100 }
						/>
					) }
					<ToggleControl
						label={ __( 'Date', 'newspack-newsletters' ) }
						checked={ attributes.displayPostDate }
						onChange={ value => setAttributes( { displayPostDate: value } ) }
					/>
					<ToggleControl
						label={ __( 'Featured image', 'newspack-newsletters' ) }
						checked={ attributes.displayFeaturedImage }
						onChange={ value => setAttributes( { displayFeaturedImage: value } ) }
					/>
					<ToggleControl
						label={ __( "Author's name", 'newspack-newsletters' ) }
						checked={ attributes.displayAuthor }
						onChange={ value => setAttributes( { displayAuthor: value } ) }
					/>
					<ToggleControl
						label={ __( '"Continue reading…" link', 'newspack-newsletters' ) }
						checked={ attributes.displayContinueReading }
						onChange={ value => setAttributes( { displayContinueReading: value } ) }
					/>
					{ attributes.displayFeaturedImage &&
						( attributes.featuredImageAlignment === 'left' || attributes.featuredImageAlignment === 'right' ) && (
							<ToggleGroupControl
								label={ __( 'Image size', 'newspack-newsletters' ) }
								value={ attributes.featuredImageSize || 'large' }
								onChange={ value => setAttributes( { featuredImageSize: value as string | undefined } ) }
								isBlock
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							>
								{ imageSizeOptions.map( option => (
									<ToggleGroupControlOption
										key={ option.value }
										value={ option.value }
										label={ option.label }
										aria-label={ option.ariaLabel }
										showTooltip
									/>
								) ) }
							</ToggleGroupControl>
						) }
				</PanelBody>
				<PanelBody title={ __( 'Sorting & Filtering', 'newspack-newsletters' ) }>
					<QueryControlsSettings attributes={ attributes } setAttributes={ setAttributes } />
				</PanelBody>
			</InspectorControls>
			<InspectorControls group="styles">
				<PanelColorGradientSettings
					title={ __( 'Color', 'newspack-newsletters' ) }
					gradients={ [] } // Pass empty array to disable gradients.
					settings={ [
						{
							colorValue: attributes.headingColor,
							onColorChange: ( value: string ) => setAttributes( { headingColor: value } ),
							label: __( 'Heading', 'newspack-newsletters' ),
						},
						...subtitleColorSettings,
						{
							colorValue: attributes.textColor,
							onColorChange: ( value: string ) => setAttributes( { textColor: value } ),
							label: __( 'Text', 'newspack-newsletters' ),
						},
					] }
				/>
				<PanelBody title={ __( 'Typography', 'newspack-newsletters' ) }>
					<BaseControl
						className="newspack-posts-inserter__font-size-picker"
						label={ __( 'Heading size', 'newspack-newsletters' ) }
						id="heading-size"
					>
						<FontSizePicker
							fontSizes={ blockEditorSettings.fontSizes }
							value={ attributes.headingFontSize }
							onChange={ value => setAttributes( { headingFontSize: value } ) }
							__next40pxDefaultSize
						/>
					</BaseControl>
					{ attributes.displayPostSubtitle && (
						<BaseControl
							className="newspack-posts-inserter__font-size-picker"
							label={ __( 'Subtitle size', 'newspack-newsletters' ) }
							id="subtitle-size"
						>
							<FontSizePicker
								fontSizes={ blockEditorSettings.fontSizes }
								value={ attributes.subHeadingFontSize }
								onChange={ value => setAttributes( { subHeadingFontSize: value } ) }
								__next40pxDefaultSize
							/>
						</BaseControl>
					) }
					<BaseControl
						className="newspack-posts-inserter__font-size-picker"
						label={ __( 'Text size', 'newspack-newsletters' ) }
						id="text-size"
					>
						<FontSizePicker
							fontSizes={ blockEditorSettings.fontSizes }
							value={ attributes.textFontSize }
							onChange={ value => {
								return setAttributes( { textFontSize: value } );
							} }
							__next40pxDefaultSize
						/>
					</BaseControl>
				</PanelBody>
			</InspectorControls>

			<BlockControls>
				{ attributes.displayFeaturedImage && <Toolbar controls={ blockControlsImages } /> }
				<Toolbar>
					<ToolbarButton onClick={ () => setAttributes( { areBlocksInserted: true } ) }>
						{ __( 'Insert posts', 'newspack-newsletters' ) }
					</ToolbarButton>
				</Toolbar>
			</BlockControls>

			<div { ...blockProps }>
				<PostsPreview
					isReady={ isReady }
					blocks={ templateBlocks }
					viewportWidth={ 'top' === attributes.featuredImageAlignment || ! attributes.displayFeaturedImage ? 600 : 1148 }
					className={ attributes.displayFeaturedImage ? 'image-' + attributes.featuredImageAlignment : null }
				/>
			</div>
		</Fragment>
	);
};

// The `@wordpress/data` string-store selectors/dispatchers are untyped (they
// resolve to `unknown`), so their selector/action bags are cast to local shapes
// at this data-store boundary.
interface CoreSelectors {
	getEntityRecords: ( kind: string, name: string, query?: Record< string, unknown > ) => InserterPost[] | null;
}
interface BlockEditorSelectors {
	getSelectedBlock: () => { clientId: string } | null;
	getBlocks: () => Block[];
	getSettings: () => { fontSizes?: ComponentProps< typeof FontSizePicker >[ 'fontSizes' ] };
}
interface PostsInserterStoreSelectors {
	getHandledPostIds: ( clientId: string ) => number[];
}
interface BlockEditorDispatch {
	replaceBlocks: ( clientId: string, blocks: Block[] ) => void;
}
interface PostsInserterStoreDispatch {
	setHandledPostsIds: ( ids: number[], props: unknown ) => void;
	setInsertedPostsIds: ( ids: number[] ) => void;
	removeBlock: ( clientId: string ) => void;
}

const PostsInserterBlockWithSelect = composeBase(
	withSelect( ( select, props ) => {
		const {
			postsToShow,
			order,
			orderBy,
			postType,
			categories,
			isDisplayingSpecificPosts,
			specificPosts,
			preventDeduplication,
			tags,
			tagExclusions,
			categoryExclusions,
			excerptLength,
			displaySponsoredPosts,
		} = props.attributes as InserterBlockAttributes;
		const { getEntityRecords } = select( 'core' ) as CoreSelectors;
		const { getSelectedBlock, getBlocks, getSettings } = select( 'core/block-editor' ) as BlockEditorSelectors;
		const catIds = categories && categories.length > 0 ? categories.map( cat => cat.id ) : [];

		const { getHandledPostIds } = select( POSTS_INSERTER_STORE_NAME ) as PostsInserterStoreSelectors;
		const exclude = getHandledPostIds( props.clientId as string );

		let posts: InserterPost[] = [];
		const isHandlingSpecificPosts = isDisplayingSpecificPosts && specificPosts.length > 0;
		const query = {
			categories: catIds,
			tags,
			order,
			orderby: orderBy,
			per_page: postsToShow,
			exclude: preventDeduplication ? [] : exclude,
			categories_exclude: categoryExclusions,
			tags_exclude: tagExclusions,
			excerpt_length: excerptLength,
			exclude_sponsors: displaySponsoredPosts ? 0 : 1,
		};

		if ( ! isDisplayingSpecificPosts || isHandlingSpecificPosts ) {
			const postListQuery = isDisplayingSpecificPosts
				? { include: specificPosts.map( post => post.id ) }
				: pickBy( query, value => ! isUndefined( value ) );

			posts = getEntityRecords( 'postType', postType, postListQuery ) || [];
		}

		// Order posts in the order as they appear in the input
		if ( isHandlingSpecificPosts ) {
			posts = specificPosts.reduce< InserterPost[] >( ( all, { id } ) => {
				const found = find( posts, [ 'id', id ] );
				return found ? [ ...all, found ] : all;
			}, [] );
		}

		return {
			// Not used by the component, but needed in deduplication.
			existingBlocks: getBlocks(),
			blockEditorSettings: getSettings(),
			selectedBlock: getSelectedBlock(),
			postList: posts,
		};
	} ),
	withDispatch( ( ( dispatch, props ) => {
		const replaceBlocksAction = dispatch( 'core/block-editor' ).replaceBlocks as BlockEditorDispatch[ 'replaceBlocks' ];
		const postsInserterDispatch = dispatch( POSTS_INSERTER_STORE_NAME );
		const setHandledPostsIdsAction = postsInserterDispatch.setHandledPostsIds as PostsInserterStoreDispatch[ 'setHandledPostsIds' ];
		const setInsertedPostsIdsAction = postsInserterDispatch.setInsertedPostsIds as PostsInserterStoreDispatch[ 'setInsertedPostsIds' ];
		const removeBlockAction = postsInserterDispatch.removeBlock as PostsInserterStoreDispatch[ 'removeBlock' ];
		return {
			replaceBlocks: ( blocks: Block[] ) => {
				replaceBlocksAction( ( props.selectedBlock as { clientId: string } ).clientId, blocks );
			},
			setHandledPostsIds: ( ids: number[] ) => setHandledPostsIdsAction( ids, props ),
			setInsertedPostsIds: setInsertedPostsIdsAction,
			removeBlock: () => removeBlockAction( props.clientId as string ),
		};
	} ) as Parameters< typeof withDispatch >[ 0 ] )
)( PostsInserterBlock ) as ComponentType< BlockEditProps< Record< string, unknown > > >;

export default () => {
	// `block.json` declares `supports` in the bare-array form (`["align"]`) rather than the
	// documented WordPress object form (e.g. `{ "align": true }`) that `BlockSupports` expects.
	// Flagged as a suspected authoring bug (out of scope to fix here); the value is passed through
	// unchanged and only re-typed at this @wordpress/blocks API boundary.
	registerBlockType( POSTS_INSERTER_BLOCK_NAME, {
		...blockDefinition,
		supports: blockDefinition.supports as object as BlockSupports,
		title: __( 'Posts Inserter', 'newspack-newsletters' ),
		description: __( 'Lets you insert posts into your newsletter.', 'newspack-newsletters' ),
		icon: {
			src: pages,
			foreground: '#406ebc',
		},
		edit: PostsInserterBlockWithSelect,
		save: () => <InnerBlocks.Content />,
	} );
};
