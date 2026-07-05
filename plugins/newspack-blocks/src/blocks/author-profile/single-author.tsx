/* eslint-disable jsx-a11y/anchor-is-valid */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { RawHTML } from '@wordpress/element';
import { autop } from '@wordpress/autop';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * A single social/contact link (URL plus its inline SVG icon markup).
 */
export type AuthorSocialLink = { url?: string; svg?: string };

/**
 * Author record as returned by the author REST endpoints and passed between
 * the author blocks. Custom fields are accessed dynamically, hence the index
 * signature.
 */
export type Author = {
	id?: number | string;
	name?: string;
	bio?: string;
	url?: string;
	avatar?: string;
	last_name?: string;
	email?: AuthorSocialLink;
	social?: Record< string, AuthorSocialLink >;
	newspack_phone_number?: AuthorSocialLink;
	newspack_role?: string;
	newspack_employer?: string;
	newspack_job_title?: string;
	is_guest?: boolean;
	[ key: string ]: unknown;
};

/**
 * Display-related attributes shared by the author blocks. Toggle attributes for
 * custom fields (`show<Field>`) are read dynamically, hence the index signature.
 */
export type AuthorAttributes = {
	className?: string;
	showBio?: boolean;
	showSocial?: boolean;
	showEmail?: boolean;
	showArchiveLink?: boolean;
	showAvatar?: boolean;
	textSize?: string;
	avatarAlignment?: string;
	avatarBorderRadius?: string;
	avatarSize?: number;
	[ key: string ]: unknown;
};

type MaybeLinkProps = {
	author: Author;
	children: React.ReactNode;
	showArchiveLink?: boolean;
};

// Show a link to the author's post archive page, if available.
const MaybeLink = ( { author, children, showArchiveLink }: MaybeLinkProps ) =>
	showArchiveLink && author && author.url ? (
		<a href="#" className="no-op">
			{ children }
		</a>
	) : (
		<>{ children }</>
	);

export const SingleAuthor = ( { author, attributes }: { author: Author; attributes: AuthorAttributes } ) => {
	const { showBio, showSocial, showEmail, showArchiveLink, showAvatar, textSize, avatarAlignment, avatarBorderRadius, avatarSize } = attributes;

	// Combine social links and email, which are shown together.
	const socialLinks: Record< string, AuthorSocialLink > = ( showSocial && author && author.social ) || {};
	if ( showEmail && author && author.email ) {
		socialLinks.email = author.email;
	} else {
		delete socialLinks.email;
	}
	if ( attributes.shownewspack_phone_number && author && author.newspack_phone_number ) {
		socialLinks.newspack_phone_number = author.newspack_phone_number;
	} else {
		delete socialLinks.newspack_phone_number;
	}

	const employment = [ attributes.shownewspack_role && author.newspack_role, attributes.shownewspack_employer && author.newspack_employer ]
		.filter( Boolean )
		.join( ', ' );
	const socialLinksItems = Object.keys( socialLinks );

	return (
		<div
			className={ classnames(
				'wp-block-newspack-blocks-author-profile',
				'avatar-' + avatarAlignment,
				'text-size-' + textSize,
				attributes.className
			) }
		>
			{ showAvatar && author.avatar && (
				<div className="wp-block-newspack-blocks-author-profile__avatar">
					<figure
						style={ {
							borderRadius: avatarBorderRadius,
							height: `${ avatarSize }px`,
							width: `${ avatarSize }px`,
						} }
						dangerouslySetInnerHTML={ { __html: author.avatar } }
					/>
				</div>
			) }
			<div className="wp-block-newspack-blocks-author-profile__bio">
				<h3>
					<MaybeLink author={ author } showArchiveLink={ showArchiveLink }>
						{ author.name }
					</MaybeLink>
				</h3>
				{ Boolean( attributes.shownewspack_job_title ) && author.newspack_job_title && (
					<p className="wp-block-newspack-blocks-author-profile__job-title">{ author.newspack_job_title }</p>
				) }
				{ employment && <p className="wp-block-newspack-blocks-author-profile__employment">{ employment }</p> }
				{ showBio && author.bio && (
					<p>
						<RawHTML>{ autop( author.bio ) } </RawHTML>
						{ showArchiveLink && (
							<a href="#" className="no-op">
								{ sprintf(
									/* translators: %s: author name. */
									__( 'More by %s', 'newspack-blocks' ),
									// `name` is always present on the real authors SingleAuthor renders; `?? ''` is a pure type guard.
									author.name ?? ''
								) }
							</a>
						) }
					</p>
				) }
				{ socialLinksItems.length !== 0 && (
					<ul className="wp-block-newspack-blocks-author-profile__social-links">
						{ socialLinksItems.map( service => (
							<li key={ service }>
								<a href="#" className="no-op">
									{ socialLinks[ service ].svg && <span dangerouslySetInnerHTML={ { __html: socialLinks[ service ].svg } } /> }
									<span className={ socialLinks[ service ].svg ? 'hidden' : 'visible' }>{ service }</span>
								</a>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</div>
	);
};
