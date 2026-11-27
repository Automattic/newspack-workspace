import { Icon } from '@wordpress/components';
import { commentAuthorAvatar } from '@wordpress/icons';
import type { ComponentProps } from 'react';

import './style.scss';

interface UserRowProps {
	/** 1x avatar source. Falsy renders `icon` instead. */
	avatarUrl?: string;
	/** Optional `srcSet` for the avatar. */
	avatarSrcSet?: string;
	/** Name to display. */
	label: string;
	/** Fallback icon; defaults to the generic person. */
	icon?: ComponentProps< typeof Icon >[ 'icon' ];
	/** Extra class on the wrapper. */
	className?: string;
}

/**
 * Avatar and name on one line, with a fallback icon when there is no
 * avatar. Shared by the Author columns, the newsletters list's
 * "currently editing" line, and the layout pickers.
 */
export default function UserRow( { avatarUrl, avatarSrcSet, label, icon = commentAuthorAvatar, className = '' }: UserRowProps ) {
	return (
		<span className={ `newspack-newsletters-user-row ${ className }`.trim() }>
			{ avatarUrl ? (
				<span className="newspack-newsletters-user-row__avatar">
					<img src={ avatarUrl } srcSet={ avatarSrcSet } width={ 16 } height={ 16 } alt="" />
				</span>
			) : (
				<Icon className="newspack-newsletters-user-row__icon" icon={ icon } size={ 24 } />
			) }
			<span>{ label }</span>
		</span>
	);
}

/** An embedded author object from the REST response, for avatar extraction. */
interface AuthorLike {
	avatar_urls?: Record< string, string >;
	[ key: string ]: unknown;
}

/**
 * Avatar props for a REST author object. The row renders at 16px, so 24
 * is the 1x source and 48 the retina one.
 *
 * @param author Embedded author from the REST response.
 * @return Props for `UserRow`.
 */
export function avatarPropsFromAuthor( author?: AuthorLike ): { avatarUrl: string | undefined; avatarSrcSet: string | undefined } {
	const urls = author?.avatar_urls || {};
	return {
		avatarUrl: urls[ 24 ] || urls[ 48 ],
		avatarSrcSet: urls[ 48 ] ? `${ urls[ 48 ] } 2x` : undefined,
	};
}
