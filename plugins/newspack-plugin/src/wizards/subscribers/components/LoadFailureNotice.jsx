/**
 * WordPress dependencies.
 */
import { Notice } from '@wordpress/components';

/**
 * A read that failed, stated plainly and announced to assistive tech.
 *
 * @param {Object}      props
 * @param {string}      props.message What went wrong, as a complete sentence.
 * @param {JSX.Element} props.action  The affordance that follows it: a retry, or a way back.
 * @return {JSX.Element} The notice.
 */
export default function LoadFailureNotice( { message, action } ) {
	return (
		// spokenMessage is load-bearing, not redundant: core defaults it to children
		// and runs non-string children through renderToString mid-render, which
		// corrupts hook state.
		<Notice status="error" isDismissible={ false } spokenMessage={ message }>
			{ message } { action }
		</Notice>
	);
}
