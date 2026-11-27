/**
 * Warning shown beside a Quick Edit token field whose settled options
 * list cannot account for every term the post has stored. The field
 * would misrepresent the post, so it goes read-only and says why rather
 * than showing a quietly wrong value.
 */

import { Notice } from '@wordpress/components';

import type { ReactElement } from 'react';

interface TermsUnavailableNoticeProps {
	/** Explanation shown in the notice and announced to assistive tech. */
	message: string;
}

export default function TermsUnavailableNotice( { message }: TermsUnavailableNoticeProps ): ReactElement {
	return (
		<Notice status="warning" isDismissible={ false }>
			{ message }
		</Notice>
	);
}
