/* eslint-disable jsx-a11y/anchor-is-valid, jsx-a11y/anchor-has-content, jsx-a11y/click-events-have-key-events, jsx-a11y/interactive-supports-focus */

/**
 * External dependencies
 */
import { isEqual, pick } from 'lodash';
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { Component, createRef, Fragment, RawHTML, useRef } from '@wordpress/element';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	Spinner,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { withDispatch, withSelect } from '@wordpress/data';
import { compose } from '@wordpress/compose';
import { decodeEntities } from '@wordpress/html-entities';
import type { ComponentType, RefObject } from 'react';

/**
 * Internal dependencies
 */
import QueryControls from '../../components/query-controls';
import { PostTypesPanel, PostStatusesPanel } from '../../components/editor-panels';
import createSwiper from './create-swiper';
import { getBylineHTML, formatSponsorLogos, formatSponsorByline, getPostStatusLabel } from '../../shared/js/utils';
// Use same posts store as Homepage Posts block.
import { postsBlockSelector, postsBlockDispatch } from '../homepage-articles/utils';

// Max number of slides that can be shown at once.
const MAX_NUMBER_OF_SLIDES = 6;

export type CarouselAttributes = {
	className: string;
	imageFit: string;
	autoplay: boolean;
	delay: number;
	postsToShow: number;
	authors: AuthorId[];
	categories: CategoryId[];
	includeSubcategories: boolean;
	categoryJoinType: string;
	tags: TagId[];
	customTaxonomies: Taxonomy;
	showDate: boolean;
	showAuthor: boolean;
	showAvatar: boolean;
	showCaption: boolean;
	showCredit: boolean;
	showCategory: boolean;
	showTitle: boolean;
	postType: string[];
	specificMode: boolean;
	specificPosts: string[];
	slidesPerView: number;
	hideControls: boolean;
	aspectRatio: number;
	includedPostStatuses: string[];
	deduplicate: boolean;
};

type CarouselPropsFromDataSelector = {
	topBlocksClientIdsInOrder: Block[ 'clientId' ][];
	latestPosts: Post[];
	isUIDisabled: boolean;
};

type CarouselProps = CarouselPropsFromDataSelector & {
	attributes: CarouselAttributes;
	setAttributes: ( attributes: Partial< CarouselAttributes > ) => void;
	triggerReflow: () => void;
	// Injected by the `EditWithBlockProps` wrapper below via `useBlockProps()`.
	blockProps: { className?: string; [ key: string ]: unknown };
	carouselRef: RefObject< HTMLDivElement >;
};

type CarouselState = {
	swiperInitialized: boolean;
};

// `shouldReflow` (homepage-articles/utils.ts) is typed specifically to
// `HomepageArticlesProps`, which this block's props don't satisfy (no
// `textColor`/`setTextColor`/`className`/`isSelected`), even though both
// blocks share the same posts store and the comparison itself is identical -
// re-implemented here rather than importing the mismatched-typed helper.
// `POST_QUERY_ATTRIBUTES` in that file also isn't exported, so the equivalent
// subset relevant to this block's own attributes is listed directly below
// (the original includes extra keys - e.g. `excerptLength`, `tagExclusions` -
// that don't exist on this block's attributes at all and are no-ops via
// `pick()`, so omitting them here changes nothing).
const CAROUSEL_QUERY_ATTRIBUTES: ( keyof CarouselAttributes )[] = [
	'postsToShow',
	'authors',
	'categories',
	'includeSubcategories',
	'categoryJoinType',
	'tags',
	'customTaxonomies',
	'specificPosts',
	'specificMode',
	'postType',
	'includedPostStatuses',
	'deduplicate',
	'showCaption',
	'showCredit',
];

const shouldReflow = ( prevProps: CarouselProps, props: CarouselProps ): boolean =>
	! isEqual( pick( prevProps.attributes, CAROUSEL_QUERY_ATTRIBUTES ), pick( props.attributes, CAROUSEL_QUERY_ATTRIBUTES ) ) ||
	! isEqual( prevProps.topBlocksClientIdsInOrder, props.topBlocksClientIdsInOrder );

class Edit extends Component< CarouselProps, CarouselState > {
	btnPlayRef: RefObject< HTMLButtonElement >;
	btnPauseRef: RefObject< HTMLButtonElement >;
	btnNextRef: RefObject< HTMLButtonElement >;
	btnPrevRef: RefObject< HTMLButtonElement >;
	paginationRef: RefObject< HTMLDivElement >;
	// Only set once a visible Swiper instance has actually been created.
	swiperInstance?: ReturnType< typeof createSwiper >;

	constructor( props: CarouselProps ) {
		super( props );

		this.btnPlayRef = createRef< HTMLButtonElement >();
		this.btnPauseRef = createRef< HTMLButtonElement >();
		this.btnNextRef = createRef< HTMLButtonElement >();
		this.btnPrevRef = createRef< HTMLButtonElement >();
		this.paginationRef = createRef< HTMLDivElement >();

		this.state = {
			swiperInitialized: false,
		};
	}

	componentDidMount() {
		this.initializeSwiper( 0 );
		this.props.triggerReflow();
	}

	componentDidUpdate( prevProps: CarouselProps ) {
		const isVisible = 0 < this.props.carouselRef.current!.offsetWidth && 0 < this.props.carouselRef.current!.offsetHeight;

		// Bail early if the component is hidden.
		if ( ! isVisible ) {
			return false;
		}

		// If the swiper hasn't been initialized yet, initialize it.
		if ( ! this.state.swiperInitialized ) {
			return this.initializeSwiper( 0 );
		}

		if ( shouldReflow( prevProps, this.props ) ) {
			this.props.triggerReflow();
		}

		const { attributes, latestPosts } = this.props;

		if ( ! isEqual( prevProps.latestPosts, latestPosts ) || ! isEqual( prevProps.attributes, attributes ) ) {
			let initialSlide = 0;

			if ( this.swiperInstance ) {
				if ( latestPosts && this.swiperInstance.realIndex < latestPosts.length ) {
					initialSlide = this.swiperInstance.realIndex;
				}
				this.setState( { swiperInitialized: false } );
				this.swiperInstance.destroy( true, true );
			}

			this.initializeSwiper( initialSlide );
		}
	}

	componentWillUnmount() {
		this.props.triggerReflow();
	}

	initializeSwiper( initialSlide: number ) {
		const { latestPosts } = this.props;

		if ( latestPosts ) {
			const { aspectRatio, autoplay, delay, slidesPerView } = this.props.attributes;
			const swiperInstance = createSwiper(
				{
					block: this.props.carouselRef.current!, // Editor uses the same wrapper for block and swiper container.
					container: this.props.carouselRef.current!,
					next: this.btnNextRef.current!,
					prev: this.btnPrevRef.current!,
					play: this.btnPlayRef.current!,
					pause: this.btnPauseRef.current!,
					pagination: this.paginationRef.current!,
				},
				{
					aspectRatio,
					autoplay,
					delay: delay * 1000,
					initialSlide,
					slidesPerView: slidesPerView <= latestPosts.length ? slidesPerView : latestPosts.length,
				}
			);

			// Swiper won't be initialized unless the component is visible in the viewport.
			if ( swiperInstance ) {
				this.swiperInstance = swiperInstance;
				this.setState( { swiperInitialized: true } );
			}
		}
	}

	render() {
		const { attributes, setAttributes, latestPosts, isUIDisabled, blockProps } = this.props;
		const {
			aspectRatio,
			authors,
			autoplay,
			categories,
			includeSubcategories,
			categoryJoinType,
			customTaxonomies,
			delay,
			hideControls,
			imageFit,
			postsToShow,
			postType,
			showCategory,
			showDate,
			showAuthor,
			showAvatar,
			showCaption,
			showCredit,
			showTitle,
			slidesPerView,
			specificMode,
			specificPosts,
			tags,
		} = attributes;
		const classes = classnames(
			blockProps.className,
			'wpnbpc', // Shortened version of the default classname.
			'slides-per-view-' + slidesPerView,
			'swiper',
			{
				'wp-block-newspack-blocks-carousel__autoplay-playing': autoplay,
				'newspack-block--disabled': isUIDisabled,
				'hide-controls': hideControls,
			}
		);
		const dateFormat = __experimentalGetSettings().formats.date;
		const hasNoPosts = latestPosts && ! latestPosts.length;
		const hasOnePost = latestPosts && latestPosts.length === 1;
		const maxPosts = latestPosts ? Math.min( postsToShow, latestPosts.length ) : postsToShow;
		const aspectRatioOptions = [
			{
				value: 1,
				label: /* translators: label for square aspect ratio option */ __( 'Square', 'newspack-blocks' ),
				shortName: /* translators: abbreviation for 1:1 aspect ratio */ __( '1:1', 'newspack-blocks' ),
			},
			{
				value: 0.75,
				label: /* translators: label for 4:3 aspect ratio option */ __( '4:3', 'newspack-blocks' ),
				shortName: /* translators: abbreviation for 4:3 aspect ratio */ __( '4:3', 'newspack-blocks' ),
			},
			{
				value: 0.5625,
				label: /* translators: label for 16:9 aspect ratio option */ __( '16:9', 'newspack-blocks' ),
				shortName: /* translators: abbreviation for 16:9 aspect ratio */ __( '16:9', 'newspack-blocks' ),
			},
			{
				value: 4 / 3,
				label: /* translators: label for 3:4 aspect ratio option */ __( '3:4', 'newspack-blocks' ),
				shortName: /* translators: abbreviation for 3:4 aspect ratio */ __( '3:4', 'newspack-blocks' ),
			},
			{
				value: 16 / 9,
				label: /* translators: label for 9:16 aspect ratio option */ __( '9:16', 'newspack-blocks' ),
				shortName: /* translators: abbreviation for 9:16 aspect ratio */ __( '9:16', 'newspack-blocks' ),
			},
		];

		return (
			<Fragment>
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'newspack-blocks' ) } className="newspack-block__panel is-content">
						{ postsToShow && (
							<QueryControls
								numberOfItems={ postsToShow }
								onNumberOfItemsChange={ value => setAttributes( { postsToShow: value ? value : 1 } ) }
								authors={ authors }
								// `QueryControls`' change callbacks are typed generically as
								// `(string | number)[]` (it also serves string-token use cases);
								// this block's REST-backed fields are always numeric IDs.
								onAuthorsChange={ value => setAttributes( { authors: value as AuthorId[] } ) }
								categories={ categories }
								onCategoriesChange={ value => setAttributes( { categories: value as CategoryId[] } ) }
								includeSubcategories={ includeSubcategories }
								onIncludeSubcategoriesChange={ value => setAttributes( { includeSubcategories: value } ) }
								categoryJoinType={ categoryJoinType }
								onCategoryJoinTypeChange={ value => setAttributes( { categoryJoinType: value } ) }
								tags={ tags }
								onTagsChange={ value => setAttributes( { tags: value as TagId[] } ) }
								onCustomTaxonomiesChange={ value => setAttributes( { customTaxonomies: value } ) }
								customTaxonomies={ customTaxonomies }
								specificMode={ specificMode }
								onSpecificModeChange={ () => setAttributes( { specificMode: true } ) }
								onLoopModeChange={ () => setAttributes( { specificMode: false } ) }
								specificPosts={ specificPosts }
								onSpecificPostsChange={ _specificPosts => setAttributes( { specificPosts: _specificPosts as string[] } ) }
								postType={ postType }
								allowDedupeCurrentValue={ ! attributes.deduplicate }
								onAllowDedupeChange={ value => setAttributes( { deduplicate: ! value } ) }
							/>
						) }
					</PanelBody>
					<PanelBody title={ __( 'Display', 'newspack-blocks' ) }>
						<ToggleControl
							label={ __( 'Hide Controls', 'newspack-blocks' ) }
							help={ __( 'Remove navigation indicators from view.', 'newspack-blocks' ) }
							checked={ hideControls }
							onChange={ _hideControls => {
								setAttributes( { hideControls: _hideControls } );
							} }
						/>
						<ToggleControl
							label={ __( 'Autoplay', 'newspack-blocks' ) }
							help={ __( 'Automatically advance through slides.', 'newspack-blocks' ) }
							checked={ autoplay }
							onChange={ _autoplay => {
								setAttributes( { autoplay: _autoplay } );
							} }
						/>
						{ autoplay && (
							<RangeControl
								label={ __( 'Transition delay', 'newspack-blocks' ) }
								value={ delay }
								onChange={ _delay => {
									setAttributes( { delay: _delay } );
								} }
								min={ 1 }
								max={ 20 }
								help={ __( 'Set the waiting time between automatic slide transitions in seconds.', 'newspack-blocks' ) }
								__next40pxDefaultSize
							/>
						) }
						{ latestPosts && 1 < latestPosts.length && (
							<RangeControl
								label={ __( 'Slides per view', 'newspack-blocks' ) }
								value={ slidesPerView <= latestPosts.length ? slidesPerView : latestPosts.length }
								onChange={ _slidesPerView => {
									setAttributes( { slidesPerView: _slidesPerView } );
								} }
								min={ 1 }
								max={
									specificMode ? Math.min( MAX_NUMBER_OF_SLIDES, latestPosts.length ) : Math.min( MAX_NUMBER_OF_SLIDES, maxPosts )
								}
								help={ __( 'Choose how many slides appear on screen simultaneously.', 'newspack-blocks' ) }
								__next40pxDefaultSize
							/>
						) }
					</PanelBody>
					<PanelBody title={ __( 'Featured Image', 'newspack-blocks' ) } className="newspack-block__panel">
						<ToggleGroupControl
							label={ __( 'Aspect ratio', 'newspack-blocks' ) }
							help={ __( 'All slides will share the same aspect ratio, for consistency.', 'newspack-blocks' ) }
							value={ String( aspectRatio ) }
							onChange={ value => setAttributes( { aspectRatio: parseFloat( value as string ) } ) }
							isBlock
							__next40pxDefaultSize
						>
							{ aspectRatioOptions.map( option => (
								<ToggleGroupControlOption key={ option.value } label={ option.shortName } value={ String( option.value ) } />
							) ) }
						</ToggleGroupControl>
						<ToggleGroupControl
							label={ __( 'Fit', 'newspack-blocks' ) }
							help={
								'cover' === imageFit
									? __( 'The image will fill the entire slide and will be cropped if necessary.', 'newspack-blocks' )
									: __( 'The image will be resized to fit inside the slide without being cropped.', 'newspack-blocks' )
							}
							value={ imageFit }
							onChange={ value => setAttributes( { imageFit: value as string } ) }
							isBlock
							__next40pxDefaultSize
						>
							<ToggleGroupControlOption label={ __( 'Cover', 'newspack-blocks' ) } value="cover" />
							<ToggleGroupControlOption label={ __( 'Contain', 'newspack-blocks' ) } value="contain" />
						</ToggleGroupControl>
						<ToggleControl
							label={ __( 'Show caption', 'newspack-blocks' ) }
							checked={ showCaption }
							onChange={ () => setAttributes( { showCaption: ! showCaption } ) }
						/>
						<ToggleControl
							label={ __( 'Show credit', 'newspack-blocks' ) }
							checked={ showCredit }
							onChange={ () => setAttributes( { showCredit: ! showCredit } ) }
						/>
					</PanelBody>
					<PanelBody title={ __( 'Post Meta', 'newspack-blocks' ) }>
						<ToggleControl
							label={ __( 'Show title', 'newspack-blocks' ) }
							checked={ showTitle }
							onChange={ () => setAttributes( { showTitle: ! showTitle } ) }
						/>
						<ToggleControl
							label={ __( 'Show date', 'newspack-blocks' ) }
							checked={ showDate }
							onChange={ () => setAttributes( { showDate: ! showDate } ) }
						/>
						<ToggleControl
							label={ __( 'Show category', 'newspack-blocks' ) }
							checked={ showCategory }
							onChange={ () => setAttributes( { showCategory: ! showCategory } ) }
						/>
						<ToggleControl
							label={ __( 'Show author', 'newspack-blocks' ) }
							checked={ showAuthor }
							onChange={ () => setAttributes( { showAuthor: ! showAuthor } ) }
						/>
						<ToggleControl
							label={ __( 'Show avatar', 'newspack-blocks' ) }
							checked={ showAvatar }
							onChange={ () => setAttributes( { showAvatar: ! showAvatar } ) }
							disabled={ ! showAuthor }
						/>
					</PanelBody>
					<PostTypesPanel attributes={ attributes } setAttributes={ setAttributes } />
					<PostStatusesPanel attributes={ attributes } setAttributes={ setAttributes } />
				</InspectorControls>
				<div { ...blockProps } className={ classes }>
					{ hasNoPosts && (
						<Placeholder className="component-placeholder__align-center">
							<div style={ { margin: 'auto' } }>{ __( 'Sorry, no posts were found.' ) }</div>
						</Placeholder>
					) }
					{ ( ! this.state.swiperInitialized || ! latestPosts ) && (
						<Placeholder icon={ <Spinner /> } className="component-placeholder__align-center" />
					) }
					{ latestPosts && (
						<Fragment>
							{ autoplay && (
								<Fragment>
									<button className="swiper-button swiper-button-pause" ref={ this.btnPauseRef } />
									<button className="swiper-button swiper-button-play" ref={ this.btnPlayRef } />
								</Fragment>
							) }
							<div className="swiper-wrapper">
								{ latestPosts.map( post => (
									<article
										className={ `post-has-image swiper-slide ${ post.post_type } ${ post.newspack_article_classes || '' }` }
										key={ post.id }
									>
										{ getPostStatusLabel( post as Post & { post_status?: string } ) }
										<figure className="post-thumbnail">
											<a href="#" rel="bookmark">
												{ post.newspack_featured_image_src ? (
													<img
														className={ `image-fit-${ imageFit }` }
														src={ post.newspack_featured_image_src.large }
														alt=""
													/>
												) : (
													<div className="wp-block-newspack-blocks-carousel__placeholder" />
												) }
											</a>
										</figure>
										{ ( post.newspack_post_sponsors ||
											showCategory ||
											showTitle ||
											showAuthor ||
											showDate ||
											showCaption ||
											showCredit ) && (
											<div className="entry-wrapper">
												{ ( post.newspack_post_sponsors || ( showCategory && 0 < post.newspack_category_info.length ) ) && (
													<div className={ 'cat-links' + ( post.newspack_post_sponsors ? ' sponsor-label' : '' ) }>
														{ post.newspack_post_sponsors && (
															<span className="flag">{ post.newspack_post_sponsors[ 0 ].flag }</span>
														) }
														{ showCategory &&
															( ! post.newspack_post_sponsors || post.newspack_sponsors_show_categories ) && (
																<RawHTML>{ decodeEntities( post.newspack_category_info ) }</RawHTML>
															) }
													</div>
												) }
												{ showTitle && (
													<h3 className="entry-title">
														<a href="#">{ decodeEntities( post.title.rendered.trim() ) }</a>
													</h3>
												) }
												<div className="entry-meta">
													{ post.newspack_post_sponsors && (
														<span
															className={ `entry-sponsors ${
																post.newspack_sponsors_show_author ? 'plus-author' : ''
															}` }
														>
															{ formatSponsorLogos( post.newspack_post_sponsors ) }
															{ formatSponsorByline( post.newspack_post_sponsors ) }
														</span>
													) }
													{ showAuthor &&
														! post.newspack_listings_hide_author &&
														( ! post.newspack_post_sponsors || post.newspack_sponsors_show_author ) && (
															<RawHTML className="byline-container">{ getBylineHTML( post, showAvatar ) }</RawHTML>
														) }
													{ showDate && (
														<time className="entry-date published" key="pub-date">
															{ dateI18n( dateFormat, post.date ) }
														</time>
													) }
													{ ( showCaption || showCredit ) && post.newspack_featured_image_caption && (
														<div
															className="entry-caption"
															dangerouslySetInnerHTML={ {
																__html: post.newspack_featured_image_caption,
															} }
														/>
													) }
												</div>
											</div>
										) }
									</article>
								) ) }
							</div>
							{ ! hasNoPosts && ! hasOnePost && (
								<>
									<button className="swiper-button swiper-button-prev" ref={ this.btnPrevRef } />
									<button className="swiper-button swiper-button-next" ref={ this.btnNextRef } />
									<div className="swiper-pagination swiper-pagination-bullets" ref={ this.paginationRef } />
								</>
							) }
						</Fragment>
					) }
				</div>
			</Fragment>
		);
	}
}

type CarouselHOCProps = Omit< CarouselProps, 'blockProps' | 'carouselRef' >;

const EditWithBlockProps = ( props: CarouselHOCProps ) => {
	const carouselRef = useRef< HTMLDivElement >( null );
	const blockProps = useBlockProps( { ref: carouselRef } );
	return <Edit { ...props } blockProps={ blockProps } carouselRef={ carouselRef } />;
};

// `compose`'s declared type is `(...funcs: Function[]) => ...` (a rest
// parameter, not a single array argument) even though its real implementation
// also flattens a single array of functions - called here with separate
// arguments to match the declared signature; behaviorally identical either
// way since `compose` flattens its arguments internally regardless.
// `compose` itself is untyped (it returns `(...args: unknown[]) => unknown`),
// and `withSelect`/`withDispatch` type their injected props loosely as
// `Record<string, unknown>` - re-type the composed result at this boundary to
// the shape `registerBlockType` actually needs, matching the pattern used for
// the `Toolbar` re-typing above and for `IframeEdit` in `../iframe/index.tsx`.
export default compose( withSelect( postsBlockSelector ), withDispatch( postsBlockDispatch ) )( EditWithBlockProps ) as ComponentType<
	Record< string, unknown >
>;
