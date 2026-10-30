/**
 * Warning shown beside a Quick Edit token field whose settled options
 * list cannot account for every term the post has stored. The field
 * would misrepresent the post, so it goes read-only and says why rather
 * than showing a quietly wrong value.
 */

import { Notice } from '@wordpress/components';

/**
 * @param {Object} props
 * @param {string} props.message Explanation shown in the notice and announced to assistive tech.
 */
export default function TermsUnavailableNotice( { message } ) {
	return (
		<Notice status="warning" isDismissible={ false }>
			{ message }
		</Notice>
	);
}
