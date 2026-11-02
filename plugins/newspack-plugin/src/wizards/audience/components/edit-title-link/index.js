/**
 * Title link that reveals a pencil icon on hover and keyboard focus.
 */

/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/components';
import { pencil } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';

const EditTitleLink = ( { href, children } ) => (
	<a className="newspack-campaigns__edit-title-link" href={ href }>
		{ children }
		<Icon icon={ pencil } size={ 20 } />
	</a>
);

export default EditTitleLink;
