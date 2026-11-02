/**
 * EditTitleLink
 */

/**
 * WordPress dependencies.
 */
import { Icon, pencil } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * A title that links to its edit screen, revealing a pencil on hover and keyboard focus.
 */
const EditTitleLink = ( { href, children }: { href: string; children: React.ReactNode } ) => (
	<a className="newspack-edit-title-link" href={ href }>
		{ children }
		<Icon icon={ pencil } size={ 20 } />
	</a>
);

export default EditTitleLink;
