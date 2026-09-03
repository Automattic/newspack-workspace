/**
 * WordPress dependencies.
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Keep a failed read's retry affordance reachable from the keyboard.
 *
 * Retrying unmounts the notice while the request is in flight, so the affordance
 * that comes back is a fresh node and focus has already fallen to the document
 * body. Focus is restored only when it actually landed there, so a reader who
 * tabbed away while the request was running is not pulled back.
 *
 * @param {Object}   options
 * @param {boolean}  options.settled Whether the read has finished.
 * @param {boolean}  options.failed  Whether the finished read failed.
 * @param {Function} options.reload  Starts the read again.
 * @return {{retryRef: Object, retry: Function}} Ref for the affordance, and the handler that starts a retry.
 */
export function useRetryFocus( { settled, failed, reload } ) {
	const retryRef = useRef( null );
	const hasRetried = useRef( false );

	const retry = () => {
		hasRetried.current = true;
		reload();
	};

	useEffect( () => {
		const node = retryRef.current;
		if ( settled && failed && hasRetried.current && node && node.ownerDocument.activeElement === node.ownerDocument.body ) {
			node.focus();
		}
	}, [ settled, failed ] );

	return { retryRef, retry };
}
