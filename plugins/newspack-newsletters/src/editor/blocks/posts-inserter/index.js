/**
 * External dependencies
 */
import { isUndefined, find, pickBy } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { __, _x } from '@wordpress/i18n';
import { withSelect, withDispatch } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import {
	BaseControl,
	FontSizePicker,
	PanelBody,
	RangeControl,
	ToggleControl,
	Toolbar,
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
import { getTemplateBlocks, convertBlockSerializationFormat } from './utils';
import QueryControlsSettings from './query-controls';
import { POSTS_INSERTER_BLOCK_NAME, POSTS_INSERTER_STORE_NAME } from './consts';
import PostsPreview from './posts-preview';

const PostsInserterBlock = ( {
	setAttributes,
	attributes,
	postList,
	replaceBlocks,
	setHandledPostsIds,
	setInsertedPostsIds,
	removeBlock,
	blockEditorSettings,
} ) => {
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
	const subtitleColorSettings = [];

	if ( attributes.displayPostSubtitle ) {
		subtitleColorSettings.push( {
			colorValue: attributes.subHeadingColor,
			onColorChange: value => setAttributes( { subHeadingColor: value } ),
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
			const images = postList.reduce( ( all, post ) => {
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
								onChange={ value => setAttributes( { featuredImageSize: value } ) }
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
							onColorChange: value => setAttributes( { headingColor: value } ),
							label: __( 'Heading', 'newspack-newsletters' ),
						},
						...subtitleColorSettings,
						{
							colorValue: attributes.textColor,
							onColorChange: value => setAttributes( { textColor: value } ),
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

const PostsInserterBlockWithSelect = compose( [
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
		} = props.attributes;
		const { getEntityRecords } = select( 'core' );
		const { getSelectedBlock, getBlocks, getSettings } = select( 'core/block-editor' );
		const catIds = categories && categories.length > 0 ? categories.map( cat => cat.id ) : [];

		const { getHandledPostIds } = select( POSTS_INSERTER_STORE_NAME );
		const exclude = getHandledPostIds( props.clientId );

		let posts = [];
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
			posts = specificPosts.reduce( ( all, { id } ) => {
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
	withDispatch( ( dispatch, props ) => {
		const { replaceBlocks } = dispatch( 'core/block-editor' );
		const { setHandledPostsIds, setInsertedPostsIds, removeBlock } = dispatch( POSTS_INSERTER_STORE_NAME );
		return {
			replaceBlocks: blocks => {
				replaceBlocks( props.selectedBlock.clientId, blocks );
			},
			setHandledPostsIds: ids => setHandledPostsIds( ids, props ),
			setInsertedPostsIds,
			removeBlock: () => removeBlock( props.clientId ),
		};
	} ),
] )( PostsInserterBlock );

export default () => {
	registerBlockType( POSTS_INSERTER_BLOCK_NAME, {
		...blockDefinition,
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
