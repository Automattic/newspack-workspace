/**
 * WordPress dependencies
 */
import { _x, __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

type Sponsor = {
	id?: number;
	src?: string;
	sponsor_url?: string;
	sponsor_name?: string;
	img_width?: number;
	img_height?: number;
	byline_prefix?: string;
	author_link?: string;
	flag?: string;
};

export const getBylineHTML = ( post: Post, showAvatar = false ) => {
	const byline = '<span class="byline">' + post.newspack_post_byline + '</span>';
	if ( showAvatar && post.newspack_post_avatars ) {
		return post.newspack_post_avatars + byline;
	}
	return byline;
};

export const formatSponsorLogos = ( sponsorInfo: Sponsor[] ) => (
	<span className="sponsor-logos">
		{ sponsorInfo.map( sponsor => (
			<Fragment key={ sponsor.id }>
				{ sponsor.src && (
					<a href={ sponsor.sponsor_url }>
						<img src={ sponsor.src } width={ sponsor.img_width } height={ sponsor.img_height } alt={ sponsor.sponsor_name } />
					</a>
				) }
			</Fragment>
		) ) }
	</span>
);

export const formatSponsorByline = ( sponsorInfo: Sponsor[] ) => (
	<span className="byline sponsor-byline">
		{ sponsorInfo[ 0 ].byline_prefix }{ ' ' }
		{ sponsorInfo.reduce< ReactNode[] >( ( accumulator, sponsor, index ) => {
			return [
				...accumulator,
				<span className="author" key={ sponsor.id }>
					<a href={ sponsor.author_link }>{ sponsor.sponsor_name }</a>
				</span>,
				index < sponsorInfo.length - 2 && ', ',
				sponsorInfo.length > 1 && index === sponsorInfo.length - 2 && _x( 'and', 'post author', 'newspack-blocks' ),
			];
		}, [] ) }
	</span>
);

export const getPostStatusLabel = ( post: { post_status?: string } = {} ) =>
	post.post_status !== 'publish' ? (
		<div className="newspack-preview-label">
			{ { draft: __( 'Draft', 'newspack-blocks' ), future: __( 'Scheduled', 'newspack-blocks' ) }[ post.post_status as 'draft' | 'future' ] }
		</div>
	) : null;
