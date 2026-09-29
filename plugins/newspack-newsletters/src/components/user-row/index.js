import { Icon } from '@wordpress/components';
import { commentAuthorAvatar } from '@wordpress/icons';

import './style.scss';

/**
 * Avatar and name on one line, with a fallback icon when there is no
 * avatar. Shared by the Author columns, the newsletters list's
 * "currently editing" line, and the layout pickers.
 *
 * @param {Object} props
 * @param {string} [props.avatarUrl]    1x avatar source. Falsy renders `icon` instead.
 * @param {string} [props.avatarSrcSet] Optional `srcSet` for the avatar.
 * @param {string} props.label          Name to display.
 * @param {Object} [props.icon]         Fallback icon; defaults to the generic person.
 * @param {string} [props.className]    Extra class on the wrapper.
 */
export default function UserRow( { avatarUrl, avatarSrcSet, label, icon = commentAuthorAvatar, className = '' } ) {
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

/**
 * Avatar props for a REST author object. The row renders at 16px, so 24
 * is the 1x source and 48 the retina one.
 *
 * @param {Object} author Embedded author from the REST response.
 * @return {{avatarUrl: string|undefined, avatarSrcSet: string|undefined}} Props for `UserRow`.
 */
export function avatarPropsFromAuthor( author ) {
	const urls = author?.avatar_urls || {};
	return {
		avatarUrl: urls[ 24 ] || urls[ 48 ],
		avatarSrcSet: urls[ 48 ] ? `${ urls[ 48 ] } 2x` : undefined,
	};
}
