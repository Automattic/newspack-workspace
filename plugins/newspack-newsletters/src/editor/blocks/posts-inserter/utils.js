/* globals newspack_email_editor_data */

/**
 * External dependencies
 */
import { omit } from 'lodash';

/**
 * WordPress dependencies
 */
import { _x } from '@wordpress/i18n';
import { createBlock, getBlockContent } from '@wordpress/blocks';
import { dateI18n, getSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { POSTS_INSERTER_BLOCK_NAME } from './consts';

const assignFontSize = ( fontSize, attributes ) => {
	if ( typeof fontSize === 'number' ) {
		fontSize = fontSize + 'px';
	}
	attributes.style = { ...( attributes.style || {} ), typography: { fontSize } };
	return attributes;
};

const getHeadingBlockTemplate = ( post, { headingFontSize, headingColor } ) => [
	'core/heading',
	assignFontSize( headingFontSize, {
		style: { color: { text: headingColor } },
		content: `<a href="${ post.link }">${ post.title.rendered }</a>`,
		level: 3,
	} ),
];

const getDateBlockTemplate = ( post, { textFontSize, textColor } ) => {
	const dateFormat = getSettings().formats.date;
	return [
		'core/paragraph',
		assignFontSize( textFontSize, {
			content: dateI18n( dateFormat, post.date ),
			fontSize: 'normal',
			style: { color: { text: textColor } },
		} ),
	];
};

const getSubtitleBlockTemplate = ( post, { subHeadingFontSize, subHeadingColor } ) => {
	const subtitle = post?.meta?.newspack_post_subtitle || '';
	const attributes = {
		level: 4,
		content: subtitle.trim(),
		style: { color: { text: subHeadingColor } },
	};
	return [ 'core/heading', assignFontSize( subHeadingFontSize, attributes ) ];
};

const getExcerptBlockTemplate = ( post, { excerptLength, textFontSize, textColor } ) => {
	let excerpt = post.excerpt.rendered;
	const excerptElement = document.createElement( 'div' );
	excerptElement.innerHTML = excerpt;
	excerpt = excerptElement.textContent || excerptElement.innerText || '';

	const needsEllipsis = excerptLength < excerpt.trim().split( ' ' ).length;

	const postExcerpt = needsEllipsis ? `${ excerpt.split( ' ', excerptLength ).join( ' ' ) } […]` : excerpt;

	const attributes = { content: postExcerpt.trim(), style: { color: { text: textColor } } };
	return [ 'core/paragraph', assignFontSize( textFontSize, attributes ) ];
};

const getContinueReadingLinkBlockTemplate = ( post, { textFontSize, textColor } ) => {
	const attributes = {
		content: `<a href="${ post.link }">${ newspack_email_editor_data?.labels?.continue_reading_label }</a>`,
		style: { color: { text: textColor } },
	};
	return [ 'core/paragraph', assignFontSize( textFontSize, attributes ) ];
};

const getAuthorBlockTemplate = ( post, { textFontSize, textColor } ) => {
	const { newspack_custom_byline, newspack_author_info } = post;

	// Check for custom byline first.
	if ( newspack_custom_byline ) {
		return [
			'core/heading',
			assignFontSize( textFontSize, {
				content: newspack_custom_byline,
				fontSize: 'normal',
				level: 6,
				style: { color: { text: textColor } },
			} ),
		];
	}

	if ( Array.isArray( newspack_author_info ) && newspack_author_info.length ) {
		const authorLinks = newspack_author_info.reduce( ( acc, author, index ) => {
			const { author_link, display_name } = author;

			if ( author_link && display_name ) {
				const comma =
					newspack_author_info.length > 2 && index < newspack_author_info.length - 1
						? _x( ',', 'comma separator for multiple authors', 'newspack-newsletters' )
						: '';
				const and =
					newspack_author_info.length > 1 && index === newspack_author_info.length - 1
						? newspack_email_editor_data?.labels?.byline_connector_label
						: '';
				acc.push( `${ and }<a href="${ author_link }">${ display_name }</a>${ comma }` );
			}

			return acc;
		}, [] );

		return [
			'core/heading',
			assignFontSize( textFontSize, {
				content: newspack_email_editor_data?.labels?.byline_prefix_label + authorLinks.join( ' ' ),
				fontSize: 'normal',
				level: 6,
				style: { color: { text: textColor } },
			} ),
		];
	}

	return null;
};

const getSponsorFlagBlockTemplate = ( content, { textFontSize } ) => {
	return [
		'core/heading',
		assignFontSize( textFontSize, {
			className: 'newspack-sponsors-flag',
			content: `<span style="background-color:${ newspack_email_editor_data.sponsors_flag_hex };color:${ newspack_email_editor_data.sponsors_flag_text_color };font-weight:700;padding:2px 4px;text-transform:uppercase">${ content }</span>`,
			level: 6,
			fontSize: 'small',
		} ),
	];
};

const getSponsorAttributionTemplate = ( sponsors, { textFontSize, textColor } ) => {
	const sponsorsToShow = sponsors.filter( sponsor => 'native' === sponsor.sponsor_scope );
	if ( ! sponsorsToShow.length ) {
		return [];
	}

	const sponsorNames = [];

	sponsorsToShow.forEach( sponsor => {
		const sponsorName = sponsor.sponsor_url ? `<a href="${ sponsor.sponsor_url }">${ sponsor.sponsor_name }</a>` : sponsor.sponsor_name;
		sponsorNames.push( sponsorName );
	} );

	return [
		'core/heading',
		assignFontSize( textFontSize, {
			content: sponsorsToShow[ 0 ].sponsor_byline + ' ' + sponsorNames.join( ', ' ),
			fontSize: 'normal',
			level: 6,
			style: { color: { text: textColor } },
		} ),
	];
};

const createBlockTemplatesForSinglePost = ( post, attributes ) => {
	const postContentBlocks = [];
	let displayAuthor = attributes.displayAuthor;

	const hasSponsors = post.newspack_sponsors_info && 0 < post.newspack_sponsors_info.length;
	if ( hasSponsors ) {
		// If the post is set to show sponsors with native sponsor styling, OR at least one sponsor is a native sponsor, show the "sponsored" flag.
		const showSponsorFlag =
			'native' === post.meta.newspack_sponsor_sponsorship_scope ||
			post.newspack_sponsors_info.reduce( ( acc, sponsor ) => {
				if ( 'native' === sponsor.sponsor_scope ) {
					return true;
				}
				return acc;
			}, false );

		if ( showSponsorFlag ) {
			const sponsorFlag = post.newspack_sponsors_info[ 0 ].sponsor_flag;
			postContentBlocks.push( getSponsorFlagBlockTemplate( sponsorFlag, attributes ) );
		}
	}

	postContentBlocks.push( getHeadingBlockTemplate( post, attributes ) );

	if ( attributes.displayPostSubtitle && post.meta?.newspack_post_subtitle ) {
		postContentBlocks.push( getSubtitleBlockTemplate( post, attributes ) );
	}

	// If the meta is set, use it. Otherwise, if any sponsor is 'native', use 'native'. Otherwise, default to 'underwritten'.
	const sponsorshipScope =
		post.meta.newspack_sponsor_sponsorship_scope ||
		( ( post.newspack_sponsors_info || [] ).some( sponsor => sponsor.sponsor_scope === 'native' ) ? 'native' : 'underwritten' );
	if ( hasSponsors && 'underwritten' !== sponsorshipScope ) {
		// If the post is set to show only sponsor, OR set to inherit and all sponsors are set to show only sponsor, hide the byline.
		if (
			'sponsor' === post.meta.newspack_sponsor_native_byline_display ||
			( 'inherit' === post.meta.newspack_sponsor_native_byline_display && sponsorshipScope !== 'underwritten' )
		) {
			displayAuthor = false;
		}

		const sponsorAttributions = getSponsorAttributionTemplate( post.newspack_sponsors_info, attributes );
		if ( sponsorAttributions?.length ) {
			postContentBlocks.push( sponsorAttributions );
		}
	}

	if ( displayAuthor ) {
		const author = getAuthorBlockTemplate( post, attributes );

		if ( author ) {
			postContentBlocks.push( author );
		}
	}
	if ( attributes.displayPostDate && post.date ) {
		postContentBlocks.push( getDateBlockTemplate( post, attributes ) );
	}
	if ( attributes.displayPostExcerpt ) {
		postContentBlocks.push( getExcerptBlockTemplate( post, attributes ) );
	}
	if ( attributes.displayContinueReading ) {
		postContentBlocks.push( getContinueReadingLinkBlockTemplate( post, attributes ) );
	}

	const hasFeaturedImage = post.featured_media_info?.large_url || post.featured_media_info?.medium_url ? true : false;

	if ( attributes.displayFeaturedImage ) {
		const featuredImageId = post.featured_media;
		const getImageBlock = ( alignCenter = false ) =>
			featuredImageId && hasFeaturedImage
				? [
						[
							'core/image',
							{
								id: featuredImageId,
								url: alignCenter ? post.featured_media_info?.large_url : post.featured_media_info?.medium_url,
								href: post.link,
								...( alignCenter ? { align: 'center' } : {} ),
							},
						],
				  ]
				: [];

		let imageColumnBlockSize = '50%';
		let postContentColumnBlockSize = '50%';

		if ( attributes.featuredImageSize ) {
			switch ( attributes.featuredImageSize ) {
				case 'small':
					imageColumnBlockSize = '25%';
					postContentColumnBlockSize = '75%';
					break;
				case 'medium':
					imageColumnBlockSize = '33.33%';
					postContentColumnBlockSize = '66.66%';
					break;
			}
		}

		const imageColumnBlock = [ 'core/column', { width: imageColumnBlockSize }, [ ...getImageBlock() ] ];
		const postContentColumnBlock = [ 'core/column', { width: postContentColumnBlockSize }, postContentBlocks ];

		switch ( attributes.featuredImageAlignment ) {
			case 'left':
				return [ [ 'core/columns', {}, [ imageColumnBlock, postContentColumnBlock ] ] ];
			case 'right':
				return [ [ 'core/columns', {}, [ postContentColumnBlock, imageColumnBlock ] ] ];
			case 'top':
				return [ ...getImageBlock( true ), ...postContentBlocks ];
		}
	}
	return postContentBlocks;
};

const createBlockFromTemplate = ( [ name, blockAttributes, innerBlocks = [] ] ) =>
	createBlock( name, blockAttributes, innerBlocks.map( createBlockFromTemplate ) );

const createBlockTemplatesForPosts = ( posts, attributes ) =>
	posts.reduce( ( blocks, post ) => {
		return [ ...blocks, ...createBlockTemplatesForSinglePost( post, attributes ) ];
	}, [] );

export const getTemplateBlocks = ( postList, attributes ) => createBlockTemplatesForPosts( postList, attributes ).map( createBlockFromTemplate );

/**
 * Converts a block object to a shape processable by the backend,
 * which contains block's HTML.
 *
 * @param {Object} block block, as understood by the block editor
 * @return {Object} block with innerHTML, processable by the backend
 */
export const convertBlockSerializationFormat = block => ( {
	attrs: omit( block.attributes, 'content' ),
	blockName: block.name,
	innerHTML: getBlockContent( block ),
	innerBlocks: block.innerBlocks.map( convertBlockSerializationFormat ),
} );

/**
 * In some cases, the Posts Inserter block should not handle deduplication.
 * Previews might be displayed next to each other or next to a post, which results in multiple block lists.
 * The deduplication store relies on the assumption that a post has a single blocks list, which
 * is not true when there are block previews used.
 */
export const setPreventDeduplicationForPostsInserter = blocks =>
	blocks.map( block => {
		if ( block.name === POSTS_INSERTER_BLOCK_NAME ) {
			block.attributes.preventDeduplication = true;
		}
		if ( block.innerBlocks ) {
			block.innerBlocks = setPreventDeduplicationForPostsInserter( block.innerBlocks );
		}
		return block;
	} );
