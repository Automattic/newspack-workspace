/**
 * WordPress dependencies.
 */
import { Notice } from '@wordpress/components';

/**
 * A read that failed, stated plainly and announced to assistive tech.
 *
 * @param {Object}      props
 * @param {string}      props.message  What went wrong, as a complete sentence.
 * @param {JSX.Element} [props.action] The affordance that follows it: a retry, or a way back.
 * @param {string}      [props.status] Notice status. `warning` for a read whose failure
 *                                     degrades one control while the screen stays usable.
 * @return {JSX.Element} The notice.
 */
export default function LoadFailureNotice( { message, action, status = 'error' } ) {
	return (
		// spokenMessage is load-bearing: without it core stringifies these children
		// mid-render, which corrupts hook state.
		<Notice status={ status } isDismissible={ false } spokenMessage={ message }>
			{ message } { action }
		</Notice>
	);
}
