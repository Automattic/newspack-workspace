/* eslint-disable jsx-a11y/anchor-is-valid */

/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { Fragment, RawHTML } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import { getTermClasses } from '../../editor/utils';

export const Listing = ( { attributes, error, post } ) => {
	// Parent Curated List block attributes.
	const { showAuthor, showCategory, showTags, showExcerpt, showImage, showCaption } = attributes;
	const { author = '', category = [], excerpt = '', media = {}, sponsors = false, tags = [], title = '' } = post;

	const classes = [ 'newspack-listings__listing-post', 'entry-wrapper' ];
	const termClasses = getTermClasses( post );

	return (
		<div className={ classes.concat( termClasses ).join( ' ' ) }>
			{ error && (
				<Notice className="newspack-listings__error" status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ showImage && post && media && media.image && (
				<figure className="newspack-listings__listing-featured-media">
					<img className="newspack-listings__listing-thumbnail" src={ media.image } alt={ media.caption || title } />
					{ showCaption && media.caption && <figcaption className="newspack-listings__listing-caption">{ media.caption }</figcaption> }
				</figure>
			) }
			{ post && (
				<div className="newspack-listings__listing-meta">
					{ sponsors && 0 < sponsors.length && (
						<span className="cat-links sponsor-label">
							<span className="flag">{ sponsors[ 0 ].sponsor_flag }</span>
						</span>
					) }
					{ showCategory && 0 < category.length && ! sponsors && (
						<div className="cat-links">
							{ category.map( ( _category, index ) => (
								<Fragment key="index">
									{ decodeEntities( _category.name ) }
									{ index + 1 < category.length && ', ' }
								</Fragment>
							) ) }
						</div>
					) }
					<h3 className="newspack-listings__listing-title">{ decodeEntities( title ) }</h3>
					{ sponsors && 0 < sponsors.length && (
						<div className="newspack-listings__sponsors">
							<span className="sponsor-logos">
								{ sponsors.map( sponsor => {
									return (
										<img
											key={ sponsor.sponsor_id }
											src={ sponsor.sponsor_logo.src }
											width={ sponsor.sponsor_logo.img_width }
											height={ sponsor.sponsor_logo.img_height }
											alt={ sponsor.sponsor_name }
										/>
									);
								} ) }
							</span>
							<span className="sponsor-byline">
								{ sponsors.map( ( sponsor, index ) =>
									sprintf(
										// Translators: Sponsorship attribution.
										'%1$s%2$s%3$s%4$s',
										0 === index ? sponsor.sponsor_byline + ' ' : '',
										1 < sponsors.length && index + 1 === sponsors.length ? __( 'and', 'newspack-listings' ) : '',
										sponsor.sponsor_name,
										2 < sponsors.length && index + 1 < sponsors.length
											? _x( ',', 'separator character', 'newspack-listings' )
											: ''
									)
								) }
							</span>
						</div>
					) }
					{ showAuthor && author && ! sponsors && <cite>{ __( 'By', 'newpack-listings' ) + ' ' + decodeEntities( author ) }</cite> }

					{ showExcerpt && excerpt && <RawHTML>{ excerpt }</RawHTML> }

					{ showTags && tags.length && (
						<p>
							<strong>{ __( 'Tagged:', 'newspack-listings' ) }</strong>
							{ tags.map( ( tag, index ) => (
								<Fragment key="index">
									{ decodeEntities( tag.name ) }
									{ index + 1 < tags.length && ', ' }
								</Fragment>
							) ) }
						</p>
					) }
				</div>
			) }
		</div>
	);
};
